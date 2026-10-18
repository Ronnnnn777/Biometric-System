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
ensureTeacherProfileColumns($conn);

$teacherId = (int) ($_SESSION['teacher-id'] ?? 0);
$teacherStatus = $conn->prepare("SELECT 1 FROM teachers WHERE TEACHER_ID = ? AND COALESCE(TEACHER_STATUS, 'Active') = 'Active' LIMIT 1");
$teacherStatus->bind_param('i', $teacherId);
$teacherStatus->execute();
$teacherCanUsePortal = $teacherStatus->get_result()->num_rows === 1;
$teacherStatus->close();

if (!$teacherCanUsePortal) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php', true, 302);
    exit;
}
