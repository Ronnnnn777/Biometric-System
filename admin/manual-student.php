<?php
require 'page-guard.php';
require '../config/db_conn.php';
header('Content-Type: application/json');
$id=trim($_GET['id'] ?? '');
if($id===''){echo json_encode(['student'=>null,'message'=>'Enter a student ID.']);exit;}
$stmt=$conn->prepare('SELECT STUDENT_ID, STUDENT_NAME, IMAGE_PATH FROM students WHERE STUDENT_ID=? LIMIT 1');$stmt->bind_param('s',$id);$stmt->execute();$student=$stmt->get_result()->fetch_assoc();
if(!$student){echo json_encode(['student'=>null,'message'=>'No student was found with that ID.']);exit;}
$today=date('Y-m-d');$check=$conn->prepare('SELECT ID FROM attendance WHERE STUDENT_ID=? AND DATE(TIME_IN)=? LIMIT 1');$check->bind_param('ss',$student['STUDENT_ID'],$today);$check->execute();echo json_encode(['student'=>$student,'already_logged'=>$check->get_result()->num_rows>0]);