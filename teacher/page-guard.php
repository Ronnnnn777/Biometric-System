<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent browser Back from restoring protected pages after logout.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');

if (empty($_SESSION['is-authenticated']) || ($_SESSION['user-role'] ?? '') !== 'teacher') {
    header('Location: login.php', true, 302);
    exit;
}

require_once '../config/db_conn.php';
require_once '../includes/teacher-profile-schema.php';
require_once '../includes/teacher-assignment-schema.php';
ensureTeacherAssignmentSchema($conn);
ensureTeacherProfileColumns($conn);

$teacherId = (int) ($_SESSION['teacher-id'] ?? 0);
$teacherStatus = $conn->prepare("SELECT MUST_CHANGE_PASSWORD,TEACHER_NAME FROM teachers WHERE TEACHER_ID = ? AND COALESCE(TEACHER_STATUS, 'Active') = 'Active' LIMIT 1");
$teacherStatus->bind_param('i', $teacherId);
$teacherStatus->execute();
$portalTeacher = $teacherStatus->get_result()->fetch_assoc();
$teacherCanUsePortal = (bool)$portalTeacher;
$teacherStatus->close();

if (!$teacherCanUsePortal) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php', true, 302);
    exit;
}

if (!empty($portalTeacher['MUST_CHANGE_PASSWORD']) && !in_array(basename($_SERVER['SCRIPT_NAME']), ['settings.php','settings-save.php'], true)) {
    $_SESSION['toast'] = ['message' => 'Change your temporary password before using the teacher portal.', 'type' => 'error'];
    header('Location: settings.php'); exit;
}

$_SESSION['teacher-name'] = $portalTeacher['TEACHER_NAME'];
