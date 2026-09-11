<?php
session_start();
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/attendance-schema.php';
ensureAttendanceTimeOutColumn($conn);
header('Content-Type: application/json');
if(!hash_equals($_SESSION['csrf_token'] ?? '',$_POST['csrf_token'] ?? '')){http_response_code(403);echo json_encode(['message'=>'Invalid request.']);exit;}
$id=trim($_POST['student_id'] ?? '');$stmt=$conn->prepare('SELECT STUDENT_ID, STUDENT_NAME, IMAGE_PATH FROM students WHERE STUDENT_ID=? LIMIT 1');$stmt->bind_param('s',$id);$stmt->execute();$student=$stmt->get_result()->fetch_assoc();if(!$student){echo json_encode(['message'=>'Student not found.']);exit;}
$scanType=$_POST['scan_type']??'time_in';if(!in_array($scanType,['time_in','time_out'],true))$scanType='time_in';$today=date('Y-m-d');$check=$conn->prepare('SELECT ID,TIME_OUT FROM attendance WHERE STUDENT_ID=? AND DATE(TIME_IN)=? ORDER BY TIME_IN DESC LIMIT 1');$check->bind_param('ss',$student['STUDENT_ID'],$today);$check->execute();$record=$check->get_result()->fetch_assoc();$logged=false;$message='';if($scanType==='time_in'){if(!$record){$log=$conn->prepare('INSERT INTO attendance (STUDENT_ID,STUDENT_NAME,TIME_IN) VALUES (?,?,NOW())');$log->bind_param('ss',$student['STUDENT_ID'],$student['STUDENT_NAME']);$log->execute();$logged=true;$message='Time in recorded';}else{$message='Time in already recorded';}}elseif(!$record){$message='No time-in record today';}elseif($record['TIME_OUT']!==null){$message='Time out already recorded';}else{$log=$conn->prepare('UPDATE attendance SET TIME_OUT=NOW() WHERE ID=? AND TIME_OUT IS NULL');$log->bind_param('i',$record['ID']);$log->execute();$logged=$log->affected_rows>0;$message=$logged?'Time out recorded':'Time out already recorded';}echo json_encode(['student'=>$student,'logged'=>$logged,'action'=>$scanType,'message'=>$message]);
