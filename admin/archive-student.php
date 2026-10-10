<?php
session_start();
require '../config/db_conn.php';
require 'page-guard.php';
require '../includes/student-profile-schema.php';
ensureStudentProfileColumns($conn);

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    exit('Invalid request.');
}

$id = (int)($_POST['id'] ?? 0);
if ($id < 1) {
    $_SESSION['toast'] = ['message' => 'Student profile not found.', 'type' => 'error'];
    header('Location: student-list.php');
    exit;
}

// Keep the profile, photo, and all attendance records. Archived students are
// excluded from all scanning endpoints because only Enrolled students can scan.
$archive = $conn->prepare("UPDATE students SET ENROLLMENT_STATUS='Archived' WHERE ID=?");
$archive->bind_param('i', $id);
$archive->execute();

$_SESSION['toast'] = ['message' => $archive->affected_rows ? 'Student archived. The profile and attendance history have been retained.' : 'Student was already archived or could not be found.', 'type' => $archive->affected_rows ? 'success' : 'error'];
header('Location: student-list.php');
exit;
?>
