<?php
require_once __DIR__ . '/sectioning.php';
require_once __DIR__ . '/student-promotion-schema.php';
require_once __DIR__ . '/promotion-policy-schema.php';
require_once __DIR__ . '/teacher-assignment-audit.php';

function validPromotionSchoolYear(string $year): bool
{
    return preg_match('/^(\d{4})-(\d{4})$/', $year, $parts) && (int)$parts[2] === (int)$parts[1]+1;
}

function promotionSubjectKey(string $subject): string { return strtolower(trim($subject)); }

/** A running subject average is not a completed annual grade. Never round honor eligibility. */
function promotionAcademicStanding(array $student, array $results, array $required): array
{
    $issues = []; $subjects = []; $sum = 0; $failed = 0;
    $missingSubjects = []; $incompleteSubjects = [];
    foreach ($results as $result) {
        $subjects[promotionSubjectKey($result['SUBJECT'])] = $result;
        if ($result['RESULT'] === 'Failed') $failed++;
    }
    if (!$required) $issues[] = 'Required subjects are not configured for this grade and strand.';
    $requiredKeys = array_map('promotionSubjectKey', $required);
    foreach ($subjects as $key=>$result) {
        if (!in_array($key, $requiredKeys, true)) $issues[] = 'Review an unexpected subject: '.$result['SUBJECT'];
    }
    foreach ($required as $subject) {
        $result = $subjects[promotionSubjectKey($subject)] ?? null;
        if (!$result) { $missingSubjects[] = $subject; $issues[] = 'Missing subject: '.$subject; continue; }
        if ((int)$result['GRADE'] !== (int)$student['GRADE'] || $result['STRAND'] !== $student['STRAND']) {
            $issues[] = 'Grade/strand mismatch: '.$subject; continue;
        }
        $quarters = []; $complete = true; $missingQuarters = [];
        foreach (['Q1_GRADE','Q2_GRADE','Q3_GRADE','Q4_GRADE'] as $column) {
            $value = $result[$column] ?? null;
            if ($value === null || $value === '' || !is_numeric($value) || (float)$value < 0 || (float)$value > 100) {
                $complete = false; $missingQuarters[] = substr($column,0,2); continue;
            }
            $quarters[] = (float)$value;
        }
        $final = $result['FINAL_GRADE'] ?? null;
        if (!$complete || $final === null || !is_numeric($final) || (float)$final < 0 || (float)$final > 100) {
            $incompleteSubjects[] = $subject;
            if ($final === null || !is_numeric($final) || (float)$final < 0 || (float)$final > 100) $missingQuarters[] = 'final';
            $issues[] = 'Incomplete quarterly/final grades: '.$subject.' ('.implode(', ',$missingQuarters).')'; continue;
        }
        // Preserve the existing subject-final rule, but keep the unrounded quarter
        // totals for general-average qualification and ranking (integer hundredths).
        if ((int)round((float)$final*100) !== (int)round(studentFinalGrade($quarters)*100)
            || $result['RESULT'] !== studentResultRemark((float)$final)) {
            $issues[] = 'Final grade/result needs review: '.$subject; continue;
        }
        foreach ($quarters as $value) $sum += (int)round($value*100);
    }
    $denominator = count($required)*4;
    $complete = !$issues && $denominator > 0;
    return ['complete'=>$complete, 'issues'=>$issues, 'failed'=>$failed,
        'missing_subjects'=>$missingSubjects, 'incomplete_subjects'=>$incompleteSubjects,
        'decision'=>$complete ? studentPromotionDecision($failed) : 'Incomplete grades',
        'sum'=>$complete ? $sum : 0, 'denominator'=>$denominator,
        'average'=>$complete ? $sum / ($denominator*100) : null,
        'honor'=>$complete && studentPromotionDecision($failed)==='Promoted' && $sum >= 9000*$denominator];
}

/** Read-only unless requested to lock rows by the transactional runner. No DDL or backfills. */
function promotionContext(mysqli $conn, string $year, bool $lock = false): array
{
    if (!validPromotionSchoolYear($year)) throw new RuntimeException('The active school year is invalid.');
    $suffix = $lock ? ' FOR UPDATE' : '';
    $setting = $conn->query('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1'.$suffix)->fetch_assoc();
    if (($setting['ACTIVE_SCHOOL_YEAR'] ?? '') !== $year) throw new RuntimeException('The school year changed. Refresh the preview.');
    $students = $conn->query("SELECT * FROM students WHERE ENROLLMENT_STATUS='Enrolled' AND IS_ARCHIVED=0 AND GRADE BETWEEN 7 AND 12 ORDER BY STUDENT_ID".$suffix)->fetch_all(MYSQLI_ASSOC);
    $stmt = $conn->prepare('SELECT * FROM student_subject_results WHERE SCHOOL_YEAR=? ORDER BY STUDENT_ID,SUBJECT'.$suffix);
    $stmt->bind_param('s',$year); $stmt->execute(); $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt = $conn->prepare('SELECT * FROM promotion_required_subjects WHERE SCHOOL_YEAR=? ORDER BY GRADE,STRAND,SUBJECT'.$suffix);
    $stmt->bind_param('s',$year); $stmt->execute(); $required = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sections = $conn->query('SELECT * FROM sections ORDER BY GRADE,STRAND,SORT_ORDER,SECTION_ID'.$suffix)->fetch_all(MYSQLI_ASSOC);
    $honors = $conn->query('SELECT * FROM promotion_honor_sections ORDER BY GRADE,STRAND'.$suffix)->fetch_all(MYSQLI_ASSOC);
    $capacity = $conn->query('SELECT DEFAULT_CAPACITY FROM section_settings WHERE ID=1'.$suffix)->fetch_assoc();
    $teachers=$conn->query("SELECT TEACHER_ID,TEACHER_NAME,TEACHER_STATUS FROM teachers WHERE TEACHER_STATUS='Active' ORDER BY TEACHER_ID".$suffix)->fetch_all(MYSQLI_ASSOC);
    $stmt=$conn->prepare('SELECT TEACHER_ID,SECTION_ID,RELEASED_AT FROM teacher_advisory_assignments WHERE SCHOOL_YEAR=? ORDER BY ID'.$suffix);
    $stmt->bind_param('s',$year); $stmt->execute(); $advisories=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt=$conn->prepare('SELECT TEACHER_ID,GRADE,STRAND,SECTION,SUBJECT FROM teacher_assignments WHERE SCHOOL_YEAR=? ORDER BY TEACHER_ID,GRADE,STRAND,SECTION'.$suffix);
    $stmt->bind_param('s',$year); $stmt->execute(); $subjects=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return ['year'=>$year,'students'=>$students,'results'=>$results,'required'=>$required,'sections'=>$sections,'honors'=>$honors,'default'=>(int)($capacity['DEFAULT_CAPACITY'] ?? 40),
        'assignments'=>['teachers'=>$teachers,'advisories'=>$advisories,'subjects'=>$subjects]];
}

/** Default strand targets, shared by preview controls and validation. */
function promotionDefaultTargets(array $students, array $saved = []): array
{
    $sources = [];
    foreach ($students as $student) {
        if (($student['ENROLLMENT_STATUS'] ?? '')!=='Enrolled' || !empty($student['IS_ARCHIVED'])) continue;
        $grade = (int)$student['GRADE']; $strand = $student['STRAND'];
        if ($grade >= 12) continue;
        $sources[$grade.'|'.$strand] = ['from_grade'=>$grade,'from_strand'=>$strand,'targets'=>[]];
    }
    foreach ($sources as $key=>&$source) {
        $grade = $source['from_grade'];
        if ($grade === 10) {
            $weights = ['Academic'=>100,'Techpro'=>0];
            foreach ($saved as $row) if ((int)$row['FROM_GRADE']===$grade && $row['FROM_STRAND']===$source['from_strand']) {
                $weights[$row['TO_STRAND']] = (int)$row['SPLIT_PERCENT'];
            }
        } else $weights = [$grade===11 ? $source['from_strand'] : '' => 100];
        foreach ($weights as $strand=>$percent) $source['targets'][] = ['strand'=>$strand,'percent'=>$percent];
    }
    unset($source); ksort($sources);
    return array_values($sources);
}

function promotionValidatedTargets(array $posted): array
{
    $targets=normalisePromotionPlans($posted, static function(string $message): void { throw new RuntimeException($message); });
    usort($targets,static fn(array $left,array $right): int=>
        ((int)$left['FROM_GRADE'] <=> (int)$right['FROM_GRADE'])
        ?: strcmp($left['FROM_STRAND'],$right['FROM_STRAND']) ?: strcmp($left['TO_STRAND'],$right['TO_STRAND']));
    return $targets;
}

/** Pure plan: no student updates and no section creation. */
function buildPromotionPlan(array $context, array $targets): array
{
    if ($context['default']<1) throw new RuntimeException('Configure a positive default section capacity.');
    $byStudent = []; $required = []; $targetMap = []; $honorIds = [];
    foreach ($context['results'] as $result) $byStudent[trim($result['STUDENT_ID'])][] = $result;
    foreach ($context['required'] as $row) $required[$row['GRADE'].'|'.$row['STRAND']][] = $row['SUBJECT'];
    foreach ($targets as $target) $targetMap[$target['FROM_GRADE'].'|'.$target['FROM_STRAND']][] = $target;
    foreach ($context['honors'] as $row) $honorIds[$row['GRADE'].'|'.$row['STRAND']] = (int)$row['SECTION_ID'];
    $plan = ['year'=>$context['year'],'students'=>[],'destinations'=>[],'reviews'=>[],
        'summary'=>['promoted'=>0,'honor'=>0,'regular'=>0,'incomplete'=>0,'retained'=>0,'summer'=>0,'graduated'=>0],
        'targets'=>$targets,'blocked'=>false];
    if (isset($context['assignments'])) {
        $assignments=$context['assignments'];
        $plan['assignment_diagnostics']=teacherAssignmentDiagnostics($assignments['teachers'],$assignments['advisories'],$assignments['subjects'],$context['sections'],$context['students'],$context['required']);
        $plan['reviews']=$plan['assignment_diagnostics']['advisory_reviews'];
    }
    $sources = []; $occupied = []; $knownNames = [];
    foreach ($context['sections'] as $section) $knownNames[strtolower($section['SECTION'])] = true;
    foreach ($context['students'] as $student) {
        if ($student['ENROLLMENT_STATUS']!=='Enrolled' || !empty($student['IS_ARCHIVED'])) continue;
        $id = trim($student['STUDENT_ID']); $key = $student['GRADE'].'|'.$student['STRAND'];
        $standing = promotionAcademicStanding($student,$byStudent[$id] ?? [],$required[$key] ?? []);
        $plan['students'][$id] = ['student'=>$student,'standing'=>$standing,'placement'=>null];
        if (!$standing['complete']) {
            $plan['summary']['incomplete']++;
            $plan['reviews'][] = ['student'=>$id,'name'=>$student['STUDENT_NAME'],'reason'=>implode(' ', $standing['issues'])];
        } elseif ($standing['decision']==='Retained') $plan['summary']['retained']++;
        elseif ($standing['decision']==='Summer class required') $plan['summary']['summer']++;
        elseif ((int)$student['GRADE']===12) $plan['summary']['graduated']++;
        else $sources[$key][] = $id;
        if ($standing['decision'] !== 'Promoted') $occupied[$key.'|'.$student['SECTION']][] = $id;
    }
    foreach ($sources as $key=>$ids) {
        if (!isset($targetMap[$key])) throw new RuntimeException('Every eligible source grade must have a promotion target.');
        sort($ids, SORT_STRING);
        $sourceTargets = $targetMap[$key];
        $chunks = splitStudentsByWeights($ids,array_column($sourceTargets,'SPLIT_PERCENT'));
        foreach ($sourceTargets as $index=>$target) {
            $destinationKey = $target['TO_GRADE'].'|'.$target['TO_STRAND'];
            if (!isset($plan['destinations'][$destinationKey])) $plan['destinations'][$destinationKey] = ['grade'=>(int)$target['TO_GRADE'],'strand'=>$target['TO_STRAND'],'ids'=>[]];
            $plan['destinations'][$destinationKey]['ids'] = array_merge($plan['destinations'][$destinationKey]['ids'],$chunks[$index]);
        }
    }
    foreach ($plan['destinations'] as $key=>&$destination) {
        $sections = []; $honor = null; $regularSections = []; $groups = [];
        foreach ($context['sections'] as $section) {
            if (!(int)$section['IS_ACTIVE'] || (int)$section['GRADE']!==$destination['grade'] || $section['STRAND']!==$destination['strand']) continue;
            $section['EXISTING_COUNT'] = count($occupied[$key.'|'.$section['SECTION']] ?? []);
            $section['IS_HONOR'] = (int)$section['SECTION_ID']===($honorIds[$key] ?? 0);
            $sections[] = $section;
            if ($section['IS_HONOR']) $honor = $section; else $regularSections[] = $section;
            if ($section['EXISTING_COUNT'] > sectionCapacity($section,$context['default'])) $plan['reviews'][] = ['student'=>'','name'=>$section['SECTION'],'reason'=>'Existing students exceed configured section capacity.'];
        }
        if (isset($honorIds[$key]) && !$honor) $plan['reviews'][] = ['student'=>'','name'=>$key,'reason'=>'Configured honor section is missing, inactive, or assigned to another grade/strand.'];
        $qualified = []; $regular = []; $ids = $destination['ids']; sort($ids,SORT_STRING);
        foreach ($ids as $id) {
            if ($honor && $plan['students'][$id]['standing']['honor']) $qualified[] = $id; else $regular[] = $id;
        }
        // Exact rational ranking; LRN is used only to break equal averages.
        usort($qualified, static function($a,$b) use ($plan): int {
            $left=$plan['students'][$a]['standing']; $right=$plan['students'][$b]['standing'];
            return ($right['sum']*$left['denominator'] <=> $left['sum']*$right['denominator']) ?: strcmp((string)$a,(string)$b);
        });
        if ($honor) {
            foreach ($occupied[$key.'|'.$honor['SECTION']] ?? [] as $id) {
                if (!$plan['students'][$id]['standing']['honor']) $plan['reviews'][] = ['student'=>$id,'name'=>$plan['students'][$id]['student']['STUDENT_NAME'],'reason'=>'A retained/summer-class student occupies the honor section. Review their current placement before rollover.'];
            }
            $free = max(0,sectionCapacity($honor,$context['default'])-$honor['EXISTING_COUNT']);
            $groups[] = ['section'=>$honor,'students'=>array_slice($qualified,0,$free)];
            foreach (array_slice($qualified,$free) as $id) $plan['reviews'][] = ['student'=>$id,'name'=>$plan['students'][$id]['student']['STUDENT_NAME'],'reason'=>'Honor-qualified at '.number_format($plan['students'][$id]['standing']['average'],6).' but honor capacity is exhausted. Administrator review required; no regular placement was substituted.'];
        }
        $available = 0;
        foreach ($regularSections as $section) $available += max(0,sectionCapacity($section,$context['default'])-$section['EXISTING_COUNT']);
        $next = count($sections)+1;
        while ($available < count($regular)) {
            do { $name=generatedSectionName($destination['grade'],$destination['strand'],$next++); } while (isset($knownNames[strtolower($name)]));
            $knownNames[strtolower($name)] = true;
            $regularSections[] = ['SECTION_ID'=>0,'SECTION'=>$name,'GRADE'=>$destination['grade'],'STRAND'=>$destination['strand'],
                'CAPACITY'=>$context['default'],'SORT_ORDER'=>1000+$next,'IS_HONOR'=>false,'EXISTING_COUNT'=>0,'VIRTUAL'=>true];
            $available += $context['default'];
        }
        $groups = array_merge($groups,assignStudentsToSections($regular,$regularSections,$context['default']));
        foreach ($groups as $group) foreach ($group['students'] as $id) {
            $plan['students'][$id]['placement'] = ['GRADE'=>$destination['grade'],'STRAND'=>$destination['strand'],'SECTION'=>$group['section']['SECTION']];
        }
        $destination['groups'] = $groups; $destination['honor'] = count($qualified); $destination['regular'] = count($regular);
        $plan['summary']['honor'] += count($qualified); $plan['summary']['regular'] += count($regular);
        $plan['summary']['promoted'] += count($ids);
        unset($destination['ids']);
    }
    unset($destination);
    $plan['blocked'] = (bool)$plan['reviews'] || !$plan['students'];
    $plan['fingerprint'] = hash('sha256',json_encode([$context,$targets],JSON_THROW_ON_ERROR));
    return $plan;
}
