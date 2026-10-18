<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/teacher-profile-schema.php';
ensureTeacherProfileColumns($conn);
$id=(int)($_POST['id']??0);
if($id){
    $stmt=$conn->prepare("UPDATE teachers SET TEACHER_STATUS='Archived', ARCHIVED_AT=NOW() WHERE TEACHER_ID=? AND COALESCE(TEACHER_STATUS,'Active')='Active'");
    $stmt->bind_param('i',$id);$stmt->execute();
    $_SESSION['toast']=['message'=>$stmt->affected_rows ? 'Teacher archived. The account and assignments have been retained.' : 'Teacher was already archived or could not be found.','type'=>$stmt->affected_rows ? 'success' : 'error'];
}
header('Location: teacher-list.php');exit;
