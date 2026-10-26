<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/csrf-token.php';
require '../includes/teacher-profile-schema.php';

ensureTeacherProfileColumns($conn);

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    exit('Invalid request.');
}

$id = (int) ($_POST['id'] ?? 0);
$restore = $conn->prepare(
    "UPDATE teachers SET TEACHER_STATUS='Active', ARCHIVED_AT=NULL
     WHERE TEACHER_ID=? AND TEACHER_STATUS='Archived'",
);
$restore->bind_param('i', $id);
$restore->execute();

$_SESSION['toast'] = [
    'message' => $restore->affected_rows
        ? 'Teacher restored to Active status.'
        : 'Archived teacher was not found.',
    'type' => $restore->affected_rows ? 'success' : 'error',
];
header('Location: teacher-list.php?view=archived');
exit;
