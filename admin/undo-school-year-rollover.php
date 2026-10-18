<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/school-year-schema.php';
require '../includes/student-profile-schema.php';

ensureSchoolYearRolloverSchema($conn);
ensureStudentProfileColumns($conn);

function undoRolloverRedirect(string $message, string $type = 'error'): void
{
    $_SESSION['toast'] = ['message' => $message, 'type' => $type];
    header('Location: school-year-rollover.php');
    exit;
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    undoRolloverRedirect('The undo request expired.');
}

$admin = $conn->prepare('SELECT ADMIN_PASS FROM admins WHERE ADMIN_USER=? LIMIT 1');
$admin->bind_param('s', $_SESSION['admin-user']);
$admin->execute();
$account = $admin->get_result()->fetch_assoc();
if (!$account || !password_verify($_POST['current_password'] ?? '', $account['ADMIN_PASS'])) {
    undoRolloverRedirect('Administrator password is incorrect. No records were changed.');
}

$setting = $conn->query('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1')->fetch_assoc();
$currentSchoolYear = (string) ($setting['ACTIVE_SCHOOL_YEAR'] ?? '');
if (!preg_match('/^(\d{4})-(\d{4})$/', $currentSchoolYear, $years)) {
    undoRolloverRedirect('The active school year is invalid.');
}
$previousSchoolYear = ((int) $years[1] - 1) . '-' . ((int) $years[2] - 1);
$count = $conn->prepare('SELECT COUNT(*) total FROM student_school_history WHERE SCHOOL_YEAR=?');
$count->bind_param('s', $previousSchoolYear);
$count->execute();
$studentCount = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
if ($studentCount === 0) {
    undoRolloverRedirect('No saved student snapshot is available for ' . $previousSchoolYear . '.');
}

try {
    $conn->begin_transaction();
    $restore = $conn->prepare(
        "UPDATE students s INNER JOIN student_school_history h ON TRIM(h.STUDENT_ID)=TRIM(s.STUDENT_ID) SET s.GRADE=h.GRADE,s.STRAND=h.STRAND,s.SECTION=h.SECTION,s.ENROLLMENT_STATUS=h.ENROLLMENT_STATUS,s.ARCHIVE_REASON=CASE WHEN h.ENROLLMENT_STATUS='Enrolled' THEN '' ELSE s.ARCHIVE_REASON END,s.ARCHIVED_AT=CASE WHEN h.ENROLLMENT_STATUS='Enrolled' THEN NULL ELSE s.ARCHIVED_AT END WHERE h.SCHOOL_YEAR=?",
    );
    $restore->bind_param('s', $previousSchoolYear);
    $restore->execute();
    $restored = $restore->affected_rows;

    $updateYear = $conn->prepare('UPDATE school_year_settings SET ACTIVE_SCHOOL_YEAR=? WHERE ID=1');
    $updateYear->bind_param('s', $previousSchoolYear);
    $updateYear->execute();
    $conn->commit();
    undoRolloverRedirect(
        'Rollover undone. ' . $restored . ' student record' . ($restored === 1 ? ' was' : 's were') . ' restored and the active school year is now ' . $previousSchoolYear . '.',
        'success',
    );
} catch (Throwable $error) {
    $conn->rollback();
    undoRolloverRedirect('The rollover could not be undone. No student records were changed.');
}
