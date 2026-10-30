<?php
/** Independent advisory ownership and subject coverage diagnostics; never infer grades. */
function teacherAssignmentDiagnostics(array $teachers, array $advisories, array $subjects, array $sections, array $students, array $required): array
{
    $active = []; $catalog = []; $byTeacher = []; $bySection = []; $issues = []; $coverage = [];
    foreach ($teachers as $teacher) if ($teacher['TEACHER_STATUS']==='Active') $active[(int)$teacher['TEACHER_ID']]=$teacher;
    foreach ($sections as $section) if ((int)$section['IS_ACTIVE']) $catalog[(int)$section['SECTION_ID']]=$section;
    foreach ($advisories as $assignment) {
        $teacherId=(int)$assignment['TEACHER_ID']; $sectionId=(int)$assignment['SECTION_ID'];
        if (!isset($active[$teacherId]) || $assignment['RELEASED_AT']!==null) continue;
        $byTeacher[$teacherId][]=$sectionId;
        if (isset($catalog[$sectionId])) $bySection[$sectionId][]=$teacherId;
    }
    foreach ($active as $id=>$teacher) {
        $owned=$byTeacher[$id] ?? [];
        if (!$owned) $issues[]=['student'=>'','name'=>$teacher['TEACHER_NAME'],'reason'=>'Missing advisory assignment for the active school year. Subject teaching assignments do not establish advisory ownership.'];
        elseif (count($owned)!==1 || !isset($catalog[$owned[0]])) $issues[]=['student'=>'','name'=>$teacher['TEACHER_NAME'],'reason'=>'Advisory ownership needs review: choose exactly one active section for this teacher and school year.'];
    }
    foreach ($catalog as $id=>$section) {
        $count=count($bySection[$id] ?? []);
        if ($count!==1) $issues[]=['student'=>'','name'=>'Grade '.$section['GRADE'].' '.$section['STRAND'].' / '.$section['SECTION'],
            'reason'=>$count ? 'Advisory conflict: several active advisers own this section.' : 'Missing advisory assignment: this section has no active adviser for this school year.'];
    }
    foreach ($subjects as $assignment) if (isset($active[(int)$assignment['TEACHER_ID']])) {
        $coverage[$assignment['GRADE'].'|'.$assignment['STRAND'].'|'.$assignment['SECTION'].'|'.strtolower(trim($assignment['SUBJECT']))]=true;
    }
    $classes=[]; $missing=[];
    foreach ($students as $student) if ($student['ENROLLMENT_STATUS']==='Enrolled' && empty($student['IS_ARCHIVED'])) {
        $classes[$student['GRADE'].'|'.$student['STRAND'].'|'.$student['SECTION']]=$student;
    }
    foreach ($classes as $key=>$student) foreach ($required as $subject) {
        if ((int)$subject['GRADE']!==(int)$student['GRADE'] || $subject['STRAND']!==$student['STRAND']) continue;
        if (!isset($coverage[$key.'|'.strtolower(trim($subject['SUBJECT']))])) $missing[]=[
            'grade'=>$student['GRADE'],'strand'=>$student['STRAND'],'section'=>$student['SECTION'],'subject'=>$subject['SUBJECT']];
    }
    return ['active_teachers'=>count($active),'assigned_teachers'=>count($byTeacher),'active_sections'=>count($catalog),
        'advisory_reviews'=>$issues,'missing_subject_assignments'=>$missing];
}
