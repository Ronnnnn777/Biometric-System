<?php
/** Read-only presentation of the complete plan. Filtering never changes eligibility. */
function promotionReviewPage(array $plan, int $grade = 0, string $placement = '', int $page = 1): array
{
    $students = []; $other = []; $missing = 0; $incomplete = 0; $sections = []; $grades = []; $ready = 0;
    foreach ($plan['students'] as $record) {
        $student = $record['student'];
        $grades[(int)$student['GRADE']] = (int)$student['GRADE'];
        $key = $student['GRADE'].'|'.$student['STRAND'].'|'.$student['SECTION'];
        $sections[$key] = 'Grade '.$student['GRADE'].' '.$student['STRAND'].' / '.$student['SECTION'];
        $missing += count($record['standing']['missing_subjects']);
        $incomplete += count($record['standing']['incomplete_subjects']);
        if ($record['standing']['complete'] && $record['standing']['decision']==='Promoted'
            && ((int)$student['GRADE']===12 || $record['placement']!==null)) $ready++;
    }
    foreach ($plan['reviews'] as $review) {
        $id = $review['student'];
        if ($id === '' || !isset($plan['students'][$id])) { $other[] = $review; continue; }
        if (!isset($students[$id])) $students[$id] = $plan['students'][$id] + ['reasons'=>[]];
        $students[$id]['reasons'][] = $review['reason'];
    }
    uasort($students, static fn(array $a, array $b): int =>
        strcasecmp($a['student']['STUDENT_NAME'], $b['student']['STUDENT_NAME'])
        ?: strcmp((string)$a['student']['STUDENT_ID'], (string)$b['student']['STUDENT_ID']));
    $filtered = array_filter($students, static function(array $record) use ($grade, $placement): bool {
        $student = $record['student'];
        return (!$grade || (int)$student['GRADE'] === $grade)
            && ($placement === '' || $placement === $student['GRADE'].'|'.$student['STRAND'].'|'.$student['SECTION']);
    });
    $total = count($filtered); $pages = max(1, (int)ceil($total / 15));
    $page = max(1, min($page, $pages)); ksort($grades); asort($sections);
    return ['students'=>count($students), 'ready'=>$ready, 'missing'=>$missing, 'incomplete'=>$incomplete,
        'other'=>$other, 'grades'=>$grades, 'sections'=>$sections, 'total'=>$total,
        'page'=>$page, 'pages'=>$pages, 'rows'=>array_slice($filtered, ($page-1)*15, 15, true)];
}
