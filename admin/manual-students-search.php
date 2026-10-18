<?php
http_response_code(410);
exit('Manual attendance is managed by teachers from their student lists.');
require 'scanner-guard.php';
require '../config/db_conn.php';
require '../includes/student-profile-schema.php';
ensureStudentProfileColumns($conn);
header('Content-Type: application/json');
$query=trim($_GET['q'] ?? ($_GET['student_id'] ?? ''));
if(mb_strlen($query)<2){echo json_encode(['students'=>[]]);exit;}
$like='%'.$query.'%';$stmt=$conn->prepare("SELECT STUDENT_ID, STUDENT_NAME, IMAGE_PATH FROM students WHERE ENROLLMENT_STATUS='Enrolled' AND (STUDENT_ID LIKE ? OR STUDENT_NAME LIKE ? OR FIRST_NAME LIKE ? OR LAST_NAME LIKE ?) ORDER BY LAST_NAME, FIRST_NAME, STUDENT_NAME LIMIT 12");$stmt->bind_param('ssss',$like,$like,$like,$like);$stmt->execute();$students=[];$result=$stmt->get_result();while($student=$result->fetch_assoc())$students[]=$student;echo json_encode(['students'=>$students]);
