<?php
require 'page-guard.php';
require_once __DIR__ . '/../includes/csrf-token.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(400); exit('Invalid request.');
}
require '../config/db_conn.php';
require '../includes/teacher-profile-schema.php';
require_once '../includes/teacher-assignment-schema.php';
ensureTeacherAssignmentSchema($conn);
ensureTeacherProfileColumns($conn);
$id=(int)($_POST['id']??0);
if($id){
    $conn->begin_transaction();
    try {
    $release=$conn->prepare('UPDATE teacher_advisory_assignments SET RELEASED_AT=NOW() WHERE TEACHER_ID=? AND RELEASED_AT IS NULL');
    $release->bind_param('i',$id);$release->execute();
    $stmt=$conn->prepare("UPDATE teachers SET TEACHER_STATUS='Archived', ARCHIVED_AT=NOW() WHERE TEACHER_ID=? AND COALESCE(TEACHER_STATUS,'Active')='Active'");
    $stmt->bind_param('i',$id);$stmt->execute();
    $_SESSION['toast']=['message'=>$stmt->affected_rows ? 'Teacher archived. The account and assignments have been retained.' : 'Teacher was already archived or could not be found.','type'=>$stmt->affected_rows ? 'success' : 'error'];
    $conn->commit();
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}
header('Location: teacher-list.php');exit;
