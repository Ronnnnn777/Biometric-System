<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/csrf-token.php';
require '../includes/student-profile-schema.php';
require_once '../includes/teacher-profile-schema.php';
require '../includes/teacher-assignment-schema.php';
require '../includes/attendance-schema.php';

ensureStudentProfileColumns($conn);ensureTeacherProfileColumns($conn);ensureTeacherAssignmentSchema($conn);ensureAttendanceTimeOutColumn($conn);
$grade=(int)($_POST['grade']??0);$section=trim($_POST['section']??'');$studentId=(int)($_POST['student_id']??0);$redirect='students.php?grade='.$grade.'&section='.urlencode($section);
if(!hash_equals($_SESSION['csrf_token']??'',$_POST['csrf_token']??'')){$_SESSION['toast']=['message'=>'The absence request expired. Please try again.','type'=>'error'];header('Location: '.$redirect);exit;}
$teacherId=(int)($_SESSION['teacher-id']??0);$student=$conn->prepare("SELECT ID,STUDENT_ID,STUDENT_NAME,GRADE,SECTION FROM students WHERE ID=? AND GRADE=? AND SECTION=? AND ENROLLMENT_STATUS='Enrolled' LIMIT 1");$student->bind_param('iis',$studentId,$grade,$section);$student->execute();$row=$student->get_result()->fetch_assoc();
$allowed=$conn->prepare('SELECT 1 FROM teacher_assignments WHERE TEACHER_ID=? AND GRADE=? AND SECTION=? LIMIT 1');$allowed->bind_param('iis',$teacherId,$grade,$section);$allowed->execute();
if(!$row||!$allowed->get_result()->fetch_assoc()){$_SESSION['toast']=['message'=>'You can mark absences only for students in your assigned section.','type'=>'error'];header('Location: '.$redirect);exit;}
$today=date('Y-m-d');$existing=$conn->prepare('SELECT ID,ATTENDANCE_REMARK FROM attendance WHERE TRIM(STUDENT_ID)=TRIM(?) AND DATE(TIME_IN)=? ORDER BY ID DESC LIMIT 1');$existing->bind_param('ss',$row['STUDENT_ID'],$today);$existing->execute();$record=$existing->get_result()->fetch_assoc();
if($record){$_SESSION['toast']=['message'=>($record['ATTENDANCE_REMARK']??'')==='Absent'?'This student is already marked absent today.':'Attendance is already recorded for this student today.','type'=>'error'];header('Location: '.$redirect);exit;}
$insert=$conn->prepare("INSERT INTO attendance (STUDENT_ID,STUDENT_NAME,TIME_IN,ATTENDANCE_REMARK,ATTENDANCE_NOTE,IS_AUTO_ABSENT) VALUES (?,?,CONCAT(CURDATE(),' 00:00:00'),'Absent','Marked absent',0)");$insert->bind_param('ss',$row['STUDENT_ID'],$row['STUDENT_NAME']);$insert->execute();
$_SESSION['toast']=['message'=>$insert->affected_rows?'Student marked absent for today.':'Could not mark the student absent.','type'=>$insert->affected_rows?'success':'error'];header('Location: '.$redirect);exit;
