<?php
session_start(); require '../config/db_conn.php';
if(!hash_equals($_SESSION['csrf_token']??'',$_POST['csrf_token']??'')){http_response_code(400);exit('Invalid request.');}
$username=trim($_POST['username']??'');$password=$_POST['password']??'';$stmt=$conn->prepare('SELECT TEACHER_ID,TEACHER_USER,TEACHER_PASS,TEACHER_NAME,SECTION FROM teachers WHERE TEACHER_USER=? LIMIT 1');$stmt->bind_param('s',$username);$stmt->execute();$teacher=$stmt->get_result()->fetch_assoc();
if(!$teacher||!password_verify($password,$teacher['TEACHER_PASS'])){$_SESSION['teacher-login-error']='Invalid email or password.';header('Location: login.php');exit;}
session_regenerate_id(true);$_SESSION['is-authenticated']=true;$_SESSION['user-role']='teacher';$_SESSION['teacher-id']=$teacher['TEACHER_ID'];$_SESSION['teacher-name']=$teacher['TEACHER_NAME'];$_SESSION['teacher-section']=$teacher['SECTION'];header('Location: students.php');
