<?php
require 'page-guard.php';if($_SESSION['user-role']!=='administrator'){http_response_code(403);exit('Administrators only.');}
require '../config/db_conn.php';require '../includes/teacher-profile-schema.php';ensureTeacherProfileColumns($conn);
$firstName=trim($_POST['first_name']??'');$lastName=trim($_POST['last_name']??'');$name=trim($firstName.' '.$lastName);$user=trim($_POST['teacher_user']??'');$pass=$_POST['teacher_pass']??'';$confirm=$_POST['teacher_confirm']??'';$section=trim($_POST['section']??'');$subject=trim($_POST['subject']??'');
if($firstName===''||$lastName===''||!filter_var($user,FILTER_VALIDATE_EMAIL)||strlen($pass)<8||$pass!==$confirm||$section===''||!in_array($subject,teacherSubjects(),true))exit('Please provide valid teacher details, assigned section, subject, and matching passwords.');
$hash=password_hash($pass,PASSWORD_DEFAULT);$stmt=$conn->prepare('INSERT INTO teachers (TEACHER_USER,TEACHER_PASS,TEACHER_NAME,FIRST_NAME,LAST_NAME,SECTION,SUBJECT) VALUES (?,?,?,?,?,?,?)');$stmt->bind_param('sssssss',$user,$hash,$name,$firstName,$lastName,$section,$subject);
if(!$stmt->execute()){http_response_code(409);exit('That teacher username already exists.');}
$_SESSION['toast']=['message'=>'Teacher account created successfully.','type'=>'success'];header('Location: teacher-list.php');exit;
