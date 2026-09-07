<?php
session_start();require '../config/db_conn.php';require '../includes/validation.php';
if(!hash_equals($_SESSION['csrf_token']??'',$_POST['csrf_token']??'')){exit('Invalid request.');}$email=sanitize($_POST['username']??'');$inputPassword=$_POST['password']??'';
$stmt=$conn->prepare('SELECT ADMIN_USER,ADMIN_PASS FROM admins WHERE ADMIN_USER=? LIMIT 1');$stmt->bind_param('s',$email);$stmt->execute();$admin=$stmt->get_result()->fetch_assoc();
if($admin&&password_verify($inputPassword,$admin['ADMIN_PASS'])){session_regenerate_id(true);$_SESSION['is-authenticated']=true;$_SESSION['user-role']='administrator';$_SESSION['admin-user']=$admin['ADMIN_USER'];header('Location: home.php');exit;}
$stmt=$conn->prepare('SELECT TEACHER_ID,TEACHER_USER,TEACHER_PASS,TEACHER_NAME,SECTION FROM teachers WHERE TEACHER_USER=? LIMIT 1');$stmt->bind_param('s',$email);$stmt->execute();$teacher=$stmt->get_result()->fetch_assoc();
if($teacher&&password_verify($inputPassword,$teacher['TEACHER_PASS'])){session_regenerate_id(true);$_SESSION['is-authenticated']=true;$_SESSION['user-role']='teacher';$_SESSION['teacher-id']=$teacher['TEACHER_ID'];$_SESSION['teacher-name']=$teacher['TEACHER_NAME'];$_SESSION['teacher-section']=$teacher['SECTION'];header('Location: ../teacher/home.php');exit;}
$_SESSION['login-error']='Invalid email address or password.';header('Location: login.php');