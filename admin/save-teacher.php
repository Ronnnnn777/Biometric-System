<?php
require "page-guard.php";
if ($_SESSION['user-role'] !== 'administrator') { http_response_code(403); exit('Administrators only.'); }
require "../config/db_conn.php";
$name=trim($_POST['teacher_name'] ?? ''); $user=trim($_POST['teacher_user'] ?? ''); $pass=$_POST['teacher_pass'] ?? ''; $confirm=$_POST['teacher_confirm'] ?? ''; $section=trim($_POST['section'] ?? '');
if ($name==='' || !filter_var($user,FILTER_VALIDATE_EMAIL) || strlen($pass)<8 || $pass !== $confirm || $section==='') exit('Please provide valid teacher details and matching passwords.');
$hash=password_hash($pass,PASSWORD_DEFAULT); $stmt=$conn->prepare('INSERT INTO teachers (TEACHER_USER,TEACHER_PASS,TEACHER_NAME,SECTION) VALUES (?,?,?,?)'); $stmt->bind_param('ssss',$user,$hash,$name,$section);
if(!$stmt->execute()) { http_response_code(409); exit('That teacher username already exists.'); }
$_SESSION['toast'] = ['message' => 'Teacher account created successfully.', 'type' => 'success'];
header('Location: teacher-list.php'); exit;
?>
