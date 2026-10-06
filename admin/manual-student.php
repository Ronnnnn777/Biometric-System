<?php
require 'scanner-guard.php';
require '../config/db_conn.php';
require '../includes/student-profile-schema.php';
ensureStudentProfileColumns($conn);
require '../includes/attendance-schema.php';
ensureAttendanceTimeOutColumn($conn);
header('Content-Type: application/json');
$id=trim($_GET['id'] ?? '');
if($id===''){echo json_encode(['student'=>null,'message'=>'Enter a student ID.']);exit;}
$stmt=$conn->prepare('SELECT STUDENT_ID, STUDENT_NAME, IMAGE_PATH, ENROLLMENT_STATUS FROM students WHERE STUDENT_ID=? LIMIT 1');$stmt->bind_param('s',$id);$stmt->execute();$student=$stmt->get_result()->fetch_assoc();
if(!$student){echo json_encode(['student'=>null,'message'=>'No student was found with that ID.']);exit;}
if(($student['ENROLLMENT_STATUS'] ?? 'Enrolled')!=='Enrolled'){echo json_encode(['student'=>null,'message'=>'This student is '.strtolower($student['ENROLLMENT_STATUS']).' and cannot record attendance.']);exit;}
$today=date('Y-m-d');$check=$conn->prepare('SELECT ID,TIME_OUT FROM attendance WHERE STUDENT_ID=? AND DATE(TIME_IN)=? ORDER BY TIME_IN DESC LIMIT 1');$check->bind_param('ss',$student['STUDENT_ID'],$today);$check->execute();$record=$check->get_result()->fetch_assoc();echo json_encode(['student'=>$student,'has_time_in'=>(bool)$record,'has_time_out'=>$record&&$record['TIME_OUT']!==null]);
