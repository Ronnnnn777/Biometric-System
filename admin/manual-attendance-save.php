<?php
session_start();
require 'page-guard.php';
require '../config/db_conn.php';
header('Content-Type: application/json');
if(!hash_equals($_SESSION['csrf_token'] ?? '',$_POST['csrf_token'] ?? '')){http_response_code(403);echo json_encode(['message'=>'Invalid request.']);exit;}
$id=trim($_POST['student_id'] ?? '');$stmt=$conn->prepare('SELECT STUDENT_ID, STUDENT_NAME, IMAGE_PATH FROM students WHERE STUDENT_ID=? LIMIT 1');$stmt->bind_param('s',$id);$stmt->execute();$student=$stmt->get_result()->fetch_assoc();if(!$student){echo json_encode(['message'=>'Student not found.']);exit;}
$today=date('Y-m-d');$check=$conn->prepare('SELECT ID FROM attendance WHERE STUDENT_ID=? AND DATE(TIME_IN)=? LIMIT 1');$check->bind_param('ss',$student['STUDENT_ID'],$today);$check->execute();$already=$check->get_result()->num_rows>0;if(!$already){$log=$conn->prepare('INSERT INTO attendance (STUDENT_ID,STUDENT_NAME,TIME_IN) VALUES (?,?,NOW())');$log->bind_param('ss',$student['STUDENT_ID'],$student['STUDENT_NAME']);$log->execute();}echo json_encode(['student'=>$student,'logged'=>!$already]);