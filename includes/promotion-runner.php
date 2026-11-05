<?php
require_once __DIR__ . '/promotion-planner.php';
require_once __DIR__ . '/teacher-access-schema.php';

function promotionPlacementState(array $student): array
{
    $state = [];
    foreach (['GRADE','STRAND','SECTION','ENROLLMENT_STATUS','STUDENT_STATUS','ARCHIVE_REASON','IS_ARCHIVED','ARCHIVED_AT','ARCHIVED_BY'] as $field) {
        $state[$field] = ($student[$field] ?? null) === null ? null : (string) $student[$field];
    }
    return $state;
}

/** All validation, new sections, history and changes share one transaction. */
function executePromotionRollover(mysqli $conn, string $year, array $targets, array $choices, string $previewHash, string $administrator): array
{
    $conn->begin_transaction();
    try {
        $context = promotionContext($conn,$year,true);
        $choices['require_grade10_strands'] = true;
        $plan = buildPromotionPlan($context,$targets,$choices);
        if ($plan['blocked']) throw new RuntimeException('Rollover blocked: no enrolled students are available for promotion.');
        if ($previewHash === '' || !hash_equals($plan['fingerprint'],$previewHash)) throw new RuntimeException('Grades, placement settings, students or targets changed. Refresh and review the preview before confirming.');
        $parts = explode('-',$year); $nextYear = ((int)$parts[0]+1).'-'.((int)$parts[1]+1);
        $run = $conn->prepare('INSERT INTO school_year_rollover_runs (SOURCE_YEAR,TARGET_YEAR,PREVIEW_HASH) VALUES (?,?,?)');
        $run->bind_param('sss',$year,$nextYear,$previewHash); $run->execute(); $runId = (int)$conn->insert_id;
        $insertSection = $conn->prepare("INSERT INTO sections (SECTION,GRADE,STRAND,BASE_SECTION,IS_ACTIVE,CAPACITY,SORT_ORDER) VALUES (?,?,?,'',1,?,?)");
        $track = $conn->prepare('INSERT INTO school_year_rollover_sections (SCHOOL_YEAR,SECTION,GRADE,STRAND,RUN_ID) VALUES (?,?,?,?,?)');
        $created = 0;
        $sectionDestinations = array_merge(
            array_values($plan['destinations']),
            array_values($plan['retained_destinations'] ?? []),
        );
        foreach ($sectionDestinations as $destination) foreach ($destination['groups'] as $group) {
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
        $decision = $conn->prepare('INSERT INTO student_promotion_decisions (STUDENT_ID,SCHOOL_YEAR,FAILED_SUBJECTS,DECISION,IS_RETAINED) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE FAILED_SUBJECTS=VALUES(FAILED_SUBJECTS),DECISION=VALUES(DECISION),IS_RETAINED=VALUES(IS_RETAINED)');
        $update = $conn->prepare('UPDATE students SET GRADE=?,STRAND=?,SECTION=?,ENROLLMENT_STATUS=?,STUDENT_STATUS=?,ARCHIVE_REASON=?,IS_ARCHIVED=?,ARCHIVED_AT=?,ARCHIVED_BY=? WHERE STUDENT_ID=?');
        $snapshot = $conn->prepare('INSERT INTO school_year_rollover_students (RUN_ID,STUDENT_ID,BEFORE_STATE,AFTER_STATE,ACADEMIC_STANDING) VALUES (?,?,?,?,?)');
        foreach ($plan['students'] as $id=>$record) {
            $id = (string)$id; $student=$record['student']; $standing=$record['standing'];
            $before = promotionPlacementState($student); $after = $before;
            $isRetained = $standing['decision'] === 'Retained';
            $isGraduate = (int) $student['GRADE'] === 12 && !$isRetained;
            if ($record['placement']) {
                foreach ($record['placement'] as $field=>$value) $after[$field]=(string)$value;
                $after['STUDENT_STATUS'] = 'Active';
            } elseif ($isGraduate) {
                // Grade 12 closes out for both Academic and Techpro. The
                // enrollment marker records graduation while the profile is
                // removed from every active roster via STUDENT_STATUS/is_archived.
                $after['ENROLLMENT_STATUS']='Graduated';
                $after['STUDENT_STATUS']='Archived';
                $after['ARCHIVE_REASON']='Graduated';
                $after['IS_ARCHIVED']='1';
                $after['ARCHIVED_AT']=date('Y-m-d H:i:s');
                $after['ARCHIVED_BY']=$administrator;
            }
            $historyStatus = $isGraduate ? 'Graduated' : (string) $student['ENROLLMENT_STATUS'];
            $history->bind_param('ssisss',$id,$year,$student['GRADE'],$student['STRAND'],$student['SECTION'],$historyStatus); $history->execute();
            $decisionValue = $isGraduate ? 'Graduated' : $standing['decision'];
            $retainedFlag = $isRetained ? 1 : 0;
            $decision->bind_param('ssisi',$id,$year,$standing['failed'],$decisionValue,$retainedFlag); $decision->execute();
            if ($after !== $before) {
                $update->bind_param('isssssisss',$after['GRADE'],$after['STRAND'],$after['SECTION'],$after['ENROLLMENT_STATUS'],$after['STUDENT_STATUS'],$after['ARCHIVE_REASON'],$after['IS_ARCHIVED'],$after['ARCHIVED_AT'],$after['ARCHIVED_BY'],$id); $update->execute();
            }
            $beforeJson=json_encode($before,JSON_THROW_ON_ERROR); $afterJson=json_encode($after,JSON_THROW_ON_ERROR); $academicJson=json_encode($standing,JSON_THROW_ON_ERROR);
            $snapshot->bind_param('issss',$runId,$id,$beforeJson,$afterJson,$academicJson); $snapshot->execute();
        }
        foreach ($plan['archives'] as $id => $record) {
            $id = (string) $id; $student = $record['student']; $reason = $record['reason'];
            $before = promotionPlacementState($student); $after = $before;
            $after['STUDENT_STATUS'] = 'Archived';
            $after['ARCHIVE_REASON'] = $reason;
            $after['IS_ARCHIVED'] = '1';
            $after['ARCHIVED_AT'] = date('Y-m-d H:i:s');
            $after['ARCHIVED_BY'] = $administrator;
            $history->bind_param('ssisss',$id,$year,$student['GRADE'],$student['STRAND'],$student['SECTION'],$student['ENROLLMENT_STATUS']); $history->execute();
            $decisionValue = $reason;
            $failed = 0;
            $retainedFlag = 0;
            $decision->bind_param('ssisi',$id,$year,$failed,$decisionValue,$retainedFlag); $decision->execute();
            $update->bind_param('isssssisss',$after['GRADE'],$after['STRAND'],$after['SECTION'],$after['ENROLLMENT_STATUS'],$after['STUDENT_STATUS'],$after['ARCHIVE_REASON'],$after['IS_ARCHIVED'],$after['ARCHIVED_AT'],$after['ARCHIVED_BY'],$id); $update->execute();
            $beforeJson=json_encode($before,JSON_THROW_ON_ERROR); $afterJson=json_encode($after,JSON_THROW_ON_ERROR);
            $academicJson=json_encode(['decision'=>$reason,'archived'=>true],JSON_THROW_ON_ERROR);
            $snapshot->bind_param('issss',$runId,$id,$beforeJson,$afterJson,$academicJson); $snapshot->execute();
        }
        carryForwardTeacherAssignments($conn,$year,$nextYear);
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
    $restore=$conn->prepare('UPDATE students SET GRADE=?,STRAND=?,SECTION=?,ENROLLMENT_STATUS=?,STUDENT_STATUS=?,ARCHIVE_REASON=?,IS_ARCHIVED=?,ARCHIVED_AT=?,ARCHIVED_BY=? WHERE STUDENT_ID=?');
    foreach ($snapshots as $snapshot) {
        $id=$snapshot['STUDENT_ID']; $find->bind_param('s',$id); $find->execute(); $student=$find->get_result()->fetch_assoc();
        $expectedAfter=json_decode($snapshot['AFTER_STATE'],true,512,JSON_THROW_ON_ERROR);
        $current= promotionPlacementState($student ?: []);
        // Compare only the fields captured by this run. Older audit snapshots
        // predate STUDENT_STATUS and must still be undoable.
        foreach ($expectedAfter as $field=>$value) {
            if (!$student || ($current[$field] ?? null) !== $value) throw new RuntimeException('A student placement changed after rollover. Review those changes before undoing.');
        }
        $state=json_decode($snapshot['BEFORE_STATE'],true,512,JSON_THROW_ON_ERROR);
        $grade=(string)($state['GRADE'] ?? '');
        $strand=(string)($state['STRAND'] ?? '');
        $section=(string)($state['SECTION'] ?? '');
        $enrollment=(string)($state['ENROLLMENT_STATUS'] ?? 'Enrolled');
        $studentStatus=(string)($state['STUDENT_STATUS'] ?? 'Active');
        $archiveReason=(string)($state['ARCHIVE_REASON'] ?? '');
        $isArchived=(string)($state['IS_ARCHIVED'] ?? '0');
        $archivedAt=$state['ARCHIVED_AT'] ?? null;
        $archivedBy=$state['ARCHIVED_BY'] ?? null;
        $restore->bind_param('isssssisss',$grade,$strand,$section,$enrollment,$studentStatus,$archiveReason,$isArchived,$archivedAt,$archivedBy,$id); $restore->execute();
    }
    $mark=$conn->prepare('UPDATE school_year_rollover_runs SET UNDONE_AT=NOW() WHERE ID=?');
    $mark->bind_param('i',$run['ID']); $mark->execute();
    return count($snapshots);
}

/**
 * Remove empty virtual sections made by an undone rollover, without letting a
 * foreign-key reference prevent the student rollback. The caller owns the
 * transaction and invokes this only after student placements are restored.
 *
 * @return array{deleted:int,deactivated:int,retained:int}
 */
function cleanupUndoneRolloverSections(mysqli $conn, ?int $runId, string $sourceYear): array
{
    $sections = $conn->prepare(
        "SELECT s.SECTION_ID, s.SECTION
           FROM school_year_rollover_sections r
           JOIN sections s ON s.SECTION=r.SECTION
                         AND s.GRADE=r.GRADE
                         AND s.STRAND=r.STRAND
          WHERE s.BASE_SECTION=''
            AND (r.RUN_ID=? OR (r.RUN_ID IS NULL AND r.SCHOOL_YEAR=?))
          FOR UPDATE",
    );
    $sections->bind_param('is', $runId, $sourceYear);
    $sections->execute();
    $trackedSections = $sections->get_result()->fetch_all(MYSQLI_ASSOC);

    $enrolled = $conn->prepare(
        "SELECT COUNT(*) total FROM students
          WHERE SECTION=? AND ENROLLMENT_STATUS='Enrolled' AND COALESCE(IS_ARCHIVED,0)=0",
    );
    $delete = $conn->prepare("DELETE FROM sections WHERE SECTION_ID=? AND BASE_SECTION=''");
    $deactivate = $conn->prepare("UPDATE sections SET IS_ACTIVE=0 WHERE SECTION_ID=? AND BASE_SECTION=''");
    $result = ['deleted'=>0, 'deactivated'=>0, 'retained'=>0];

    foreach ($trackedSections as $section) {
        $sectionId = (int) $section['SECTION_ID'];
        $sectionName = (string) $section['SECTION'];
        $enrolled->bind_param('s', $sectionName);
        $enrolled->execute();
        if ((int) ($enrolled->get_result()->fetch_assoc()['total'] ?? 0) > 0) {
            $result['retained']++;
            continue;
        }

        try {
            $delete->bind_param('i', $sectionId);
            $delete->execute();
            if ($delete->affected_rows > 0) $result['deleted']++;
        } catch (mysqli_sql_exception $exception) {
            // A historical foreign-key reference must survive; hide the empty
            // virtual section instead of failing an otherwise valid undo.
            if ((int) $exception->getCode() !== 1451) throw $exception;
            $deactivate->bind_param('i', $sectionId);
            $deactivate->execute();
            if ($deactivate->affected_rows > 0) $result['deactivated']++;
        }
    }

    $removeTracking = $conn->prepare(
        "DELETE FROM school_year_rollover_sections
          WHERE RUN_ID=? OR (RUN_ID IS NULL AND SCHOOL_YEAR=?)",
    );
    $removeTracking->bind_param('is', $runId, $sourceYear);
    $removeTracking->execute();
    $conn->query('DELETE FROM school_year_promotion_plan');
    return $result;
}
