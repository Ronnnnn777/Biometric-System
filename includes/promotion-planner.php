<?php
require_once __DIR__ . '/sectioning.php';
require_once __DIR__ . '/student-promotion-schema.php';
require_once __DIR__ . '/promotion-policy-schema.php';

function validPromotionSchoolYear(string $year): bool
{
    return preg_match('/^(\d{4})-(\d{4})$/', $year, $parts) && (int)$parts[2] === (int)$parts[1]+1;
}

/** Resolve a recorded final grade, or the available-quarter fallback. */
function promotionResultGrade(array $result): ?float
{
    // Prefer the exact four-quarter average when it is available. Stored final
    // grades are display-rounded in older records and must not promote an
    // 89.9975 GWA into the 90.00 honor threshold.
    $quarters = [];
    foreach (['Q1_GRADE', 'Q2_GRADE', 'Q3_GRADE', 'Q4_GRADE'] as $column) {
        $value = $result[$column] ?? null;
        if ($value !== null && $value !== '' && is_numeric($value)
            && (float) $value >= 0 && (float) $value <= 100) {
            $quarters[] = (float) $value;
        }
    }
    if (count($quarters) === 4) return array_sum($quarters) / 4;

    $final = $result['FINAL_GRADE'] ?? null;
    if ($final !== null && $final !== '' && is_numeric($final)
        && (float) $final >= 0 && (float) $final <= 100) {
        return (float) $final;
    }

    return $quarters ? array_sum($quarters) / count($quarters) : null;
}

/**
 * Dynamically compute a student's GWA from every numerical grade that exists.
 *
 * GWA = AVG(FINAL_GRADE), with the available-quarter average used only when a
 * final grade has not yet been recorded. This has no subject prerequisite or
 * pass/fail gate: an ungraded student receives standard administrative
 * promotion and a student with a GWA below 90.00 simply joins regular sections.
 */
function promotionAcademicStanding(array $student, array $results, array $required = []): array
{
    $graded = [];
    foreach ($results as $result) {
        $grade = promotionResultGrade($result);
        if ($grade !== null) $graded[] = $grade;
    }

    $gradedCount = count($graded);
    $average = $gradedCount > 0 ? array_sum($graded) / $gradedCount : null;

    $failed = 0;
    foreach ($graded as $grade) if ($grade < 75.00) $failed++;

    return [
        'complete' => true,
        'has_grades' => $gradedCount > 0,
        'issues' => [],
        'notes' => [],
        'missing_subjects' => [],
        'incomplete_subjects' => [],
        'failed' => $failed,
        'decision' => studentPromotionDecision($failed),
        'sum' => $gradedCount > 0 ? (int) round(array_sum($graded) * 10000) : 0,
        'denominator' => $gradedCount,
        'average' => $average,
        'honor' => $average !== null && $average >= 90.00,
    ];
}

/** Read-only unless requested to lock rows by the transactional runner. No DDL or backfills. */
function promotionContext(mysqli $conn, string $year, bool $lock = false): array
{
    if (!validPromotionSchoolYear($year)) throw new RuntimeException('The active school year is invalid.');
    $suffix = $lock ? ' FOR UPDATE' : '';
    $setting = $conn->query('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1'.$suffix)->fetch_assoc();
    if (($setting['ACTIVE_SCHOOL_YEAR'] ?? '') !== $year) throw new RuntimeException('The school year changed. Refresh the preview.');
    $students = $conn->query("SELECT * FROM students WHERE ENROLLMENT_STATUS='Enrolled' AND IS_ARCHIVED=0 AND GRADE BETWEEN 7 AND 12 ORDER BY STUDENT_ID".$suffix)->fetch_all(MYSQLI_ASSOC);
    $archiveStudents = $conn->query("SELECT * FROM students WHERE ENROLLMENT_STATUS IN ('Transferred','Dropped') AND COALESCE(IS_ARCHIVED,0)=0 ORDER BY STUDENT_ID".$suffix)->fetch_all(MYSQLI_ASSOC);
    $stmt = $conn->prepare('SELECT * FROM student_subject_results WHERE SCHOOL_YEAR=? ORDER BY STUDENT_ID'.$suffix);
    $stmt->bind_param('s',$year); $stmt->execute(); $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sections = $conn->query('SELECT * FROM sections ORDER BY GRADE,STRAND,SORT_ORDER,SECTION_ID'.$suffix)->fetch_all(MYSQLI_ASSOC);
    $honors = $conn->query('SELECT * FROM promotion_honor_sections ORDER BY GRADE,STRAND'.$suffix)->fetch_all(MYSQLI_ASSOC);
    $capacity = $conn->query('SELECT DEFAULT_CAPACITY FROM section_settings WHERE ID=1'.$suffix)->fetch_assoc();
    return ['year'=>$year,'students'=>$students,'archive_students'=>$archiveStudents,'results'=>$results,'sections'=>$sections,'honors'=>$honors,'default'=>(int)($capacity['DEFAULT_CAPACITY'] ?? 40)];
}

/** Default one-to-one promotion targets. Grade 10 is routed per student. */
function promotionDefaultTargets(array $students): array
{
    $sources = [];
    foreach ($students as $student) {
        if (($student['ENROLLMENT_STATUS'] ?? '')!=='Enrolled' || !empty($student['IS_ARCHIVED'])) continue;
        $grade = (int)$student['GRADE']; $strand = $student['STRAND'];
        if ($grade >= 12 || $grade === 10) continue;
        $sources[$grade.'|'.$strand] = ['from_grade'=>$grade,'from_strand'=>$strand,'targets'=>[]];
    }
    foreach ($sources as $key=>&$source) {
        $grade = $source['from_grade'];
        $source['targets'][] = ['strand'=>$grade===11 ? $source['from_strand'] : ''];
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
function buildPromotionPlan(array $context, array $targets, array $choices = []): array
{
    if ($context['default'] < 1) throw new RuntimeException('Configure a positive default section capacity.');
    $byStudent = []; $targetMap = []; $honorIds = [];
    foreach ($context['results'] as $result) $byStudent[trim($result['STUDENT_ID'])][] = $result;
    foreach ($targets as $target) $targetMap[$target['FROM_GRADE'].'|'.$target['FROM_STRAND']][] = $target;
    foreach ($context['honors'] as $row) $honorIds[$row['GRADE'].'|'.$row['STRAND']] = (int)$row['SECTION_ID'];
    $outcomes = is_array($choices['outcomes'] ?? null) ? $choices['outcomes'] : [];
    $strandChoices = is_array($choices['strand_choices'] ?? null) ? $choices['strand_choices'] : [];
    $requireGrade10Strands = !empty($choices['require_grade10_strands']);
    $plan = ['year'=>$context['year'],'students'=>[],'archives'=>[],'destinations'=>[],'reviews'=>[],
        'summary'=>['promoted'=>0,'honor'=>0,'regular'=>0,'retained'=>0,'remedial'=>0,'graduated'=>0,'transferred'=>0,'dropped'=>0,'incomplete'=>0],
        'targets'=>$targets,'choices'=>['outcomes'=>$outcomes,'strand_choices'=>$strandChoices],'blocked'=>false];

    $sectionsByDestination = []; $knownNames = [];
    foreach ($context['sections'] as $section) {
        $knownNames[strtolower($section['SECTION'])] = true;
        if (!(int) $section['IS_ACTIVE']) continue;
        $sectionsByDestination[$section['GRADE'].'|'.$section['STRAND']][] = $section;
    }
    foreach ($sectionsByDestination as &$destinationSections) {
        usort($destinationSections, static fn(array $left, array $right): int =>
            ((int) ($left['SORT_ORDER'] ?? 0) <=> (int) ($right['SORT_ORDER'] ?? 0))
            ?: ((int) $left['SECTION_ID'] <=> (int) $right['SECTION_ID'])
            ?: strcmp((string) $left['SECTION'], (string) $right['SECTION']));
    }
    unset($destinationSections);

    $sources = []; $occupied = [];
    foreach ($context['students'] as $student) {
        if ($student['ENROLLMENT_STATUS'] !== 'Enrolled' || !empty($student['IS_ARCHIVED'])) continue;
        $id = trim($student['STUDENT_ID']); $key = $student['GRADE'].'|'.$student['STRAND'];
        $standing = promotionAcademicStanding($student, $byStudent[$id] ?? []);
        $override = trim((string) ($outcomes[$id] ?? ''));
        if ($override !== '' && $override !== 'Retained') {
            throw new RuntimeException('Invalid promotion outcome for '.$id.'.');
        }
        if ($override === 'Retained') {
            $standing['decision'] = 'Retained';
            $standing['manual_retained'] = true;
        }
        $plan['students'][$id] = ['student'=>$student,'standing'=>$standing,'placement'=>null];

        if ($standing['decision'] === 'Retained') {
            $plan['summary']['retained']++;
            continue;
        }
        // Grade 12 is always closed out, regardless of strand or missing rows.
        // It never enters next year's sectioning pipeline.
        if ((int) $student['GRADE'] === 12) {
            $plan['summary']['graduated']++;
            continue;
        }
        if ((int) $student['GRADE'] === 10) {
            $postedStrand = $strandChoices[$id] ?? '';
            $strand = is_string($postedStrand) ? trim($postedStrand) : '';
            if ($strand === '' && !$requireGrade10Strands) $strand = 'Academic';
            if (!in_array($strand, ['Academic','Techpro'], true)) throw new RuntimeException('Choose Academic or Techpro for Grade 10 student '.$id.'.');
            $destinationKey = '11|'.$strand;
            $plan['destinations'][$destinationKey] ??= ['grade'=>11,'strand'=>$strand,'ids'=>[]];
            $plan['destinations'][$destinationKey]['ids'][] = $id;
            continue;
        }
        $sources[$key][] = $id;
    }

    foreach (($context['archive_students'] ?? []) as $student) {
        $id = trim((string) $student['STUDENT_ID']);
        $status = (string) $student['ENROLLMENT_STATUS'];
        if (!in_array($status, ['Transferred','Dropped'], true)) continue;
        $plan['archives'][$id] = ['student'=>$student,'reason'=>$status];
        $plan['summary'][strtolower($status)]++;
    }

    foreach ($sources as $key => $ids) {
        if (!isset($targetMap[$key])) throw new RuntimeException('Every eligible source grade must have a promotion target.');
        sort($ids, SORT_STRING);
        $sourceTargets = $targetMap[$key];
        if (count($sourceTargets) !== 1) throw new RuntimeException('Every eligible source grade must have one promotion target.');
        $target = $sourceTargets[0];
        $destinationKey = $target['TO_GRADE'].'|'.$target['TO_STRAND'];
        if (!isset($plan['destinations'][$destinationKey])) {
            $plan['destinations'][$destinationKey] = [
                'grade'=>(int) $target['TO_GRADE'],
                'strand'=>$target['TO_STRAND'],
                'ids'=>[],
            ];
        }
        $plan['destinations'][$destinationKey]['ids'] = array_merge(
            $plan['destinations'][$destinationKey]['ids'], $ids,
        );
    }

    foreach ($plan['destinations'] as $key => &$destination) {
        $sections = $sectionsByDestination[$key] ?? [];
        foreach ($sections as &$section) {
            $section['EXISTING_COUNT'] = count($occupied[$key.'|'.$section['SECTION']] ?? []);
        }
        unset($section);

        $ids = $destination['ids'];
        sort($ids, SORT_STRING);

        // Section 1 is the honor section. An explicit administrator
        // designation wins; otherwise the first catalog section (lowest
        // SORT_ORDER/SECTION_ID) is used automatically.
        $honor = null;
        $honorId = $honorIds[$key] ?? 0;
        foreach ($sections as $section) {
            if ($honorId > 0 && (int) $section['SECTION_ID'] === $honorId) { $honor = $section; break; }
        }
        if (!$honor && $sections) $honor = $sections[0];
        if (!$honor && $ids) {
            $next = 1;
            do { $name = generatedSectionName($destination['grade'], $destination['strand'], $next++); }
            while (isset($knownNames[strtolower($name)]));
            $knownNames[strtolower($name)] = true;
            $honor = ['SECTION_ID'=>0,'SECTION'=>$name,'GRADE'=>$destination['grade'],'STRAND'=>$destination['strand'],
                'CAPACITY'=>$context['default'],'SORT_ORDER'=>0,'IS_HONOR'=>true,'EXISTING_COUNT'=>0,'VIRTUAL'=>true];
            $sections[] = $honor;
        }
        foreach ($sections as &$section) {
            $section['IS_HONOR'] = $honor !== null && $section['SECTION'] === $honor['SECTION']
                && (int) $section['SECTION_ID'] === (int) $honor['SECTION_ID'];
        }
        unset($section);

        $regularSections = array_values(array_filter(
            $sections,
            static fn(array $section): bool => empty($section['IS_HONOR']),
        ));

        if (!$honor) throw new RuntimeException('Section 1 could not be prepared for the destination grade.');

        // Section 1 is based only on the computed GWA. Missing grade rows are
        // regular administrative placements, never an execution error.
        $gwaByStudent = [];
        foreach ($ids as $id) $gwaByStudent[$id] = $plan['students'][$id]['standing']['average'];
        $honorCount = 0;
        $regular = [];
        foreach ($ids as $id) {
            $gwa = $gwaByStudent[$id] ?? null;
            if ($gwa !== null && (float) $gwa >= 90.00) $honorCount++;
            else $regular[] = $id;
        }

        // Section 1 is uncapped, so no honor qualifier is routed to the regular
        // pool. The overflow term documents the reviewed capacity rule: reserve
        // room for the regular students plus any honor load beyond Section 1's
        // configured capacity, so a large honor batch cannot under-provision
        // the regular sections.
        $spillover = max(0, $honorCount - sectionCapacity($honor, $context['default']));

        $available = 0;
        foreach ($regularSections as $section) {
            $available += max(0, sectionCapacity($section, $context['default']) - $section['EXISTING_COUNT']);
        }
        $next = count($sections) + 1;
        while ($available < count($regular) + $spillover) {
            do { $name = generatedSectionName($destination['grade'], $destination['strand'], $next++); }
            while (isset($knownNames[strtolower($name)]));
            $knownNames[strtolower($name)] = true;
            $regularSections[] = [
                'SECTION_ID'=>0,'SECTION'=>$name,'GRADE'=>$destination['grade'],'STRAND'=>$destination['strand'],
                'CAPACITY'=>$context['default'],'SORT_ORDER'=>1000+$next,'IS_HONOR'=>false,
                'EXISTING_COUNT'=>0,'VIRTUAL'=>true,
            ];
            $available += $context['default'];
        }

        $sectioned = assignStudentsByAcademicSections(
            $ids, array_merge([$honor], $regularSections), $gwaByStudent, $context['default'],
        );
        $qualified = $sectioned['honor'];
        $regular = $sectioned['regular'];
        // Uncapped honor means reserved spillover capacity is usually never
        // occupied; drop empty generated sections before they are persisted.
        $groups = array_values(array_filter(
            $sectioned['groups'],
            static fn(array $group): bool => !empty($group['students']) || empty($group['section']['VIRTUAL']),
        ));
        foreach ($groups as $group) {
            foreach ($group['students'] as $id) {
                $plan['students'][$id]['placement'] = [
                    'GRADE'=>$destination['grade'],
                    'STRAND'=>$destination['strand'],
                    'SECTION'=>$group['section']['SECTION'],
                ];
            }
        }
        $destination['groups'] = $groups;
        $destination['honor'] = count($qualified);
        $destination['regular'] = count($regular);
        $plan['summary']['honor'] += count($qualified);
        $plan['summary']['regular'] += count($regular);
        $plan['summary']['promoted'] += count($ids);
        unset($destination['ids']);
    }
    unset($destination);

    // Only an empty enrolled roster stops the advisory-class rollover.
    $plan['blocked'] = !$plan['students'];
    $plan['fingerprint'] = hash('sha256', json_encode([$context, $targets, $plan['choices']], JSON_THROW_ON_ERROR));
    return $plan;
}
