<?php
require_once __DIR__ . '/promotion-planner.php';
require_once __DIR__ . '/teacher-access-schema.php';

function promotionPlacementState(array $student): array
{
    $state = [];
    foreach (['GRADE','STRAND','SECTION','ENROLLMENT_STATUS','ARCHIVE_REASON','IS_ARCHIVED','ARCHIVED_AT','ARCHIVED_BY'] as $field) {
        $state[$field] = $student[$field] === null ? null : (string)$student[$field];
    }
    return $state;
}

/** All validation, new sections, history and changes share one transaction. */
function executePromotionRollover(mysqli $conn, string $year, array $targets, string $previewHash, string $administrator): array
{
    $conn->begin_transaction();
    try {
        $context = promotionContext($conn,$year,true);
        $plan = buildPromotionPlan($context,$targets);
        if ($plan['blocked']) throw new RuntimeException('Rollover blocked: review advisory ownership, actual student grades and section capacities in the preview. Subject teaching coverage is reported separately.');
        if ($previewHash === '' || !hash_equals($plan['fingerprint'],$previewHash)) throw new RuntimeException('Grades, placement settings, students or targets changed. Refresh and review the preview before confirming.');
        $parts = explode('-',$year); $nextYear = ((int)$parts[0]+1).'-'.((int)$parts[1]+1);
        $run = $conn->prepare('INSERT INTO school_year_rollover_runs (SOURCE_YEAR,TARGET_YEAR,PREVIEW_HASH) VALUES (?,?,?)');
        $run->bind_param('sss',$year,$nextYear,$previewHash); $run->execute(); $runId = (int)$conn->insert_id;
        $insertSection = $conn->prepare("INSERT INTO sections (SECTION,GRADE,STRAND,BASE_SECTION,IS_ACTIVE,CAPACITY,SORT_ORDER) VALUES (?,?,?,'',1,?,?)");
        $track = $conn->prepare('INSERT INTO school_year_rollover_sections (SCHOOL_YEAR,SECTION,GRADE,STRAND,RUN_ID) VALUES (?,?,?,?,?)');
        $created = 0;
        foreach ($plan['destinations'] as $destination) foreach ($destination['groups'] as $group) {
            $section = $group['section'];
            if (empty($section['VIRTUAL'])) continue;
            $insertSection->bind_param('sisii',$section['SECTION'],$section['GRADE'],$section['STRAND'],$section['CAPACITY'],$section['SORT_ORDER']); $insertSection->execute();
            $track->bind_param('ssisi',$year,$section['SECTION'],$section['GRADE'],$section['STRAND'],$runId); $track->execute(); $created++;
        }
        // Only fill missing active-year snapshots; older attendance is never rewritten.
        $start = $parts[0].'-06-01'; $end = $parts[1].'-06-01';
        $attendance = $conn->prepare("UPDATE attendance a JOIN students s ON TRIM(s.STUDENT_ID)=TRIM(a.STUDENT_ID)
            SET a.GRADE_SNAPSHOT=COALESCE(a.GRADE_SNAPSHOT,s.GRADE),a.STRAND_SNAPSHOT=IF(a.STRAND_SNAPSHOT='',s.STRAND,a.STRAND_SNAPSHOT),a.SECTION_SNAPSHOT=IF(a.SECTION_SNAPSHOT='',s.SECTION,a.SECTION_SNAPSHOT)
            WHERE a.TIME_IN>=? AND a.TIME_IN<? AND (a.SECTION_SNAPSHOT='' OR a.SECTION_SNAPSHOT IS NULL)");
        $attendance->bind_param('ss',$start,$end); $attendance->execute();
        $history = $conn->prepare('INSERT IGNORE INTO student_school_history (STUDENT_ID,SCHOOL_YEAR,GRADE,STRAND,SECTION,ENROLLMENT_STATUS) VALUES (?,?,?,?,?,?)');
        $decision = $conn->prepare('INSERT INTO student_promotion_decisions (STUDENT_ID,SCHOOL_YEAR,FAILED_SUBJECTS,DECISION) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE FAILED_SUBJECTS=VALUES(FAILED_SUBJECTS),DECISION=VALUES(DECISION)');
        $update = $conn->prepare('UPDATE students SET GRADE=?,STRAND=?,SECTION=?,ENROLLMENT_STATUS=?,ARCHIVE_REASON=?,IS_ARCHIVED=?,ARCHIVED_AT=?,ARCHIVED_BY=? WHERE STUDENT_ID=?');
        $snapshot = $conn->prepare('INSERT INTO school_year_rollover_students (RUN_ID,STUDENT_ID,BEFORE_STATE,AFTER_STATE,ACADEMIC_STANDING) VALUES (?,?,?,?,?)');
        foreach ($plan['students'] as $id=>$record) {
            $id = (string)$id; $student=$record['student']; $standing=$record['standing'];
            $before = promotionPlacementState($student); $after = $before;
            if ($record['placement']) foreach ($record['placement'] as $field=>$value) $after[$field]=(string)$value;
            elseif ((int)$student['GRADE']===12 && $standing['decision']==='Promoted') {
                $after['ENROLLMENT_STATUS']='Archived'; $after['ARCHIVE_REASON']='Graduated';
                $after['IS_ARCHIVED']='1'; $after['ARCHIVED_AT']=date('Y-m-d H:i:s'); $after['ARCHIVED_BY']=$administrator;
            }
            $history->bind_param('ssisss',$id,$year,$student['GRADE'],$student['STRAND'],$student['SECTION'],$student['ENROLLMENT_STATUS']); $history->execute();
            $decision->bind_param('ssis',$id,$year,$standing['failed'],$standing['decision']); $decision->execute();
            if ($after !== $before) {
                $update->bind_param('issssisss',$after['GRADE'],$after['STRAND'],$after['SECTION'],$after['ENROLLMENT_STATUS'],$after['ARCHIVE_REASON'],$after['IS_ARCHIVED'],$after['ARCHIVED_AT'],$after['ARCHIVED_BY'],$id); $update->execute();
            }
            $beforeJson=json_encode($before,JSON_THROW_ON_ERROR); $afterJson=json_encode($after,JSON_THROW_ON_ERROR); $academicJson=json_encode($standing,JSON_THROW_ON_ERROR);
            $snapshot->bind_param('issss',$runId,$id,$beforeJson,$afterJson,$academicJson); $snapshot->execute();
        }
        // Save reviewed strand targets without removing historical student records.
        $conn->query('DELETE FROM school_year_promotion_plan');
        $targetInsert=$conn->prepare('INSERT INTO school_year_promotion_plan (FROM_GRADE,FROM_STRAND,TO_GRADE,TO_STRAND,SPLIT_PERCENT) VALUES (?,?,?,?,?)');
        foreach ($targets as $target) {
            $targetInsert->bind_param('isisi',$target['FROM_GRADE'],$target['FROM_STRAND'],$target['TO_GRADE'],$target['TO_STRAND'],$target['SPLIT_PERCENT']); $targetInsert->execute();
        }
        carryForwardTeacherAssignments($conn,$year,$nextYear);
        $curriculum=$conn->prepare('INSERT IGNORE INTO promotion_required_subjects (SCHOOL_YEAR,GRADE,STRAND,SUBJECT) SELECT ?,GRADE,STRAND,SUBJECT FROM promotion_required_subjects WHERE SCHOOL_YEAR=?');
        $curriculum->bind_param('ss',$nextYear,$year); $curriculum->execute();
        $activate=$conn->prepare('UPDATE school_year_settings SET ACTIVE_SCHOOL_YEAR=? WHERE ID=1');
        $activate->bind_param('s',$nextYear); $activate->execute();
        $conn->commit();
        return ['run'=>$runId,'next_year'=>$nextYear,'created'=>$created,'summary'=>$plan['summary']];
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}

/** Immutable per-run snapshots make undo and undo/re-run safe. Caller owns transaction. */
function undoPromotionRun(mysqli $conn, array $run): int
{
    $stmt=$conn->prepare('SELECT * FROM school_year_rollover_students WHERE RUN_ID=? ORDER BY STUDENT_ID');
    $stmt->bind_param('i',$run['ID']); $stmt->execute(); $snapshots=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $find=$conn->prepare('SELECT * FROM students WHERE STUDENT_ID=? FOR UPDATE');
    $restore=$conn->prepare('UPDATE students SET GRADE=?,STRAND=?,SECTION=?,ENROLLMENT_STATUS=?,ARCHIVE_REASON=?,IS_ARCHIVED=?,ARCHIVED_AT=?,ARCHIVED_BY=? WHERE STUDENT_ID=?');
    foreach ($snapshots as $snapshot) {
        $id=$snapshot['STUDENT_ID']; $find->bind_param('s',$id); $find->execute(); $student=$find->get_result()->fetch_assoc();
        if (!$student || promotionPlacementState($student)!==json_decode($snapshot['AFTER_STATE'],true,512,JSON_THROW_ON_ERROR)) throw new RuntimeException('A student placement changed after rollover. Review those changes before undoing.');
        $state=json_decode($snapshot['BEFORE_STATE'],true,512,JSON_THROW_ON_ERROR);
        $restore->bind_param('issssisss',$state['GRADE'],$state['STRAND'],$state['SECTION'],$state['ENROLLMENT_STATUS'],$state['ARCHIVE_REASON'],$state['IS_ARCHIVED'],$state['ARCHIVED_AT'],$state['ARCHIVED_BY'],$id); $restore->execute();
    }
    // Keep section and run history; only unused sections created by this run are deactivated.
    $deactivate=$conn->prepare("UPDATE sections s JOIN school_year_rollover_sections r ON r.SECTION=s.SECTION
        SET s.IS_ACTIVE=0 WHERE r.RUN_ID=? AND s.BASE_SECTION=''
        AND NOT EXISTS (SELECT 1 FROM students st WHERE st.SECTION=s.SECTION AND st.ENROLLMENT_STATUS='Enrolled')
        AND NOT EXISTS (SELECT 1 FROM teacher_advisory_assignments a WHERE a.SECTION_ID=s.SECTION_ID AND a.RELEASED_AT IS NULL)
        AND NOT EXISTS (SELECT 1 FROM teacher_assignments ta WHERE ta.SECTION=s.SECTION)");
    $deactivate->bind_param('i',$run['ID']); $deactivate->execute();
    $mark=$conn->prepare('UPDATE school_year_rollover_runs SET UNDONE_AT=NOW() WHERE ID=?');
    $mark->bind_param('i',$run['ID']); $mark->execute();
    return count($snapshots);
}
