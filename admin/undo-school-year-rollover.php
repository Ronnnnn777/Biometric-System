<?php
require 'page-guard.php';
require_once '../config/db_conn.php';
require_once '../includes/promotion-runner.php';
function undoRolloverRedirect(string $message,string $type='error'): void {
    $_SESSION['toast']=['message'=>$message,'type'=>$type]; header('Location: school-year-rollover.php'); exit;
}
if ($_SERVER['REQUEST_METHOD']!=='POST' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],(string)($_POST['csrf_token'] ?? ''))) undoRolloverRedirect('The undo request expired.');
$admin=$conn->prepare('SELECT ADMIN_PASS FROM admins WHERE ADMIN_USER=?'); $admin->bind_param('s',$_SESSION['admin-user']); $admin->execute(); $account=$admin->get_result()->fetch_assoc();
if (!$account || !password_verify((string)($_POST['current_password'] ?? ''),$account['ADMIN_PASS'])) undoRolloverRedirect('Administrator password is incorrect.');
$conn->begin_transaction();
try {
    $year=$conn->query('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1 FOR UPDATE')->fetch_assoc()['ACTIVE_SCHOOL_YEAR'];
    if (!validPromotionSchoolYear($year)) throw new RuntimeException('The active school year is invalid.');
    if (isset($_POST['expected_school_year']) && $_POST['expected_school_year']!==$year) throw new RuntimeException('The school year changed. Refresh before undoing.');
    $parts=explode('-',$year); $previous=((int)$parts[0]-1).'-'.((int)$parts[1]-1);
    $target=(string)($_POST['target_school_year'] ?? $previous);
    if ($target!==$previous) throw new RuntimeException('Undo the latest rollover first.');
    $find=$conn->prepare('SELECT * FROM school_year_rollover_runs WHERE SOURCE_YEAR=? AND TARGET_YEAR=? AND UNDONE_AT IS NULL ORDER BY ID DESC LIMIT 1 FOR UPDATE');
    $find->bind_param('ss',$target,$year); $find->execute(); $run=$find->get_result()->fetch_assoc();
    if ($run) $count=undoPromotionRun($conn,$run);
    else {
        // Compatibility with snapshots created before run-specific audit records existed.
        $has=$conn->prepare('SELECT COUNT(*) n FROM student_school_history WHERE SCHOOL_YEAR=?');
        $has->bind_param('s',$target); $has->execute();
        if (!(int)$has->get_result()->fetch_assoc()['n']) throw new RuntimeException('No saved student snapshot is available for the previous school year.');
        $restore=$conn->prepare("UPDATE students s JOIN student_school_history h ON TRIM(h.STUDENT_ID)=TRIM(s.STUDENT_ID)
            SET s.GRADE=h.GRADE,s.STRAND=h.STRAND,s.SECTION=h.SECTION,s.ENROLLMENT_STATUS=h.ENROLLMENT_STATUS,
                s.IS_ARCHIVED=IF(h.ENROLLMENT_STATUS='Archived',1,0),s.ARCHIVE_REASON=IF(h.ENROLLMENT_STATUS='Enrolled','',s.ARCHIVE_REASON),s.ARCHIVED_AT=IF(h.ENROLLMENT_STATUS='Enrolled',NULL,s.ARCHIVED_AT)
            WHERE h.SCHOOL_YEAR=?");
        $restore->bind_param('s',$target); $restore->execute(); $count=$restore->affected_rows;
    }
    $activate=$conn->prepare('UPDATE school_year_settings SET ACTIVE_SCHOOL_YEAR=? WHERE ID=1'); $activate->bind_param('s',$target); $activate->execute();
    $conn->commit();
} catch (Throwable $exception) { $conn->rollback(); undoRolloverRedirect($exception instanceof mysqli_sql_exception ? 'Undo failed. No student records were changed.' : $exception->getMessage()); }
undoRolloverRedirect("Rollover undone. $count student placements restored. Grades and rollover history were preserved; active school year is $target.",'success');
