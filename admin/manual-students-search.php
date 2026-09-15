<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/student-profile-schema.php';
ensureStudentProfileColumns($conn);
header('Content-Type: application/json');
$query=trim($_GET['student_id'] ?? '');
if(mb_strlen($query)<2){echo json_encode(['students'=>[]]);exit;}
$like=$query.'%';$stmt=$conn->prepare("SELECT STUDENT_ID, STUDENT_NAME, IMAGE_PATH FROM students WHERE STUDENT_ID LIKE ? AND ENROLLMENT_STATUS='Enrolled' ORDER BY STUDENT_ID LIMIT 8");$stmt->bind_param('s',$like);$stmt->execute();$students=[];$result=$stmt->get_result();while($student=$result->fetch_assoc())$students[]=$student;echo json_encode(['students'=>$students]);
