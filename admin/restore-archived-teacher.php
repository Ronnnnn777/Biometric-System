<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/csrf-token.php';
require '../includes/teacher-profile-schema.php';
require_once '../includes/teacher-assignment-schema.php';

ensureTeacherProfileColumns($conn);
ensureTeacherAssignmentSchema($conn);

if ($_SERVER['REQUEST_METHOD']!=='POST' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(400);
    exit('Invalid request.');
}

$id = (int) ($_POST['id'] ?? 0);
$conn->begin_transaction();
try {
$restore = $conn->prepare(
    "UPDATE teachers SET TEACHER_STATUS='Active', ARCHIVED_AT=NULL
     WHERE TEACHER_ID=? AND TEACHER_STATUS='Archived'",
);
$restore->bind_param('i', $id);
$restore->execute();
if (!$restore->affected_rows) throw new RuntimeException('Archived teacher was not found.');
setTeacherAdvisory($conn,$id,(int)($_POST['advisory_section_id'] ?? 0));
requireTeacherAdvisory($conn,$id);
$conn->commit();
$_SESSION['toast'] = [
    'message' => 'Teacher restored with one advisory section for the active school year.',
    'type' => 'success',
];
} catch (Throwable $error) {
    $conn->rollback();
    $_SESSION['toast']=['message'=>$error instanceof mysqli_sql_exception ? 'No changes saved. Choose an available advisory section.' : 'No changes saved. '.$error->getMessage(),'type'=>'error'];
}
header('Location: teacher-list.php?view=archived');
exit;
