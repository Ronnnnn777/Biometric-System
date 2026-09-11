<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/attendance-schema.php';
ensureAttendanceTimeOutColumn($conn);
header('Content-Type: application/json');
$id=trim($_GET['id'] ?? '');
if($id===''){echo json_encode(['student'=>null,'message'=>'Enter a student ID.']);exit;}
$stmt=$conn->prepare('SELECT STUDENT_ID, STUDENT_NAME, IMAGE_PATH FROM students WHERE STUDENT_ID=? LIMIT 1');$stmt->bind_param('s',$id);$stmt->execute();$student=$stmt->get_result()->fetch_assoc();
if(!$student){echo json_encode(['student'=>null,'message'=>'No student was found with that ID.']);exit;}
$scanType=$_GET['scan_type']??'time_in';if(!in_array($scanType,['time_in','time_out'],true))$scanType='time_in';$today=date('Y-m-d');$check=$conn->prepare('SELECT ID,TIME_OUT FROM attendance WHERE STUDENT_ID=? AND DATE(TIME_IN)=? ORDER BY TIME_IN DESC LIMIT 1');$check->bind_param('ss',$student['STUDENT_ID'],$today);$check->execute();$record=$check->get_result()->fetch_assoc();$already=$scanType==='time_in'?(bool)$record:(!$record||$record['TIME_OUT']!==null);echo json_encode(['student'=>$student,'already_logged'=>$already]);
