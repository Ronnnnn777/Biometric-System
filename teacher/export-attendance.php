<?php
require 'page-guard.php';require '../config/db_conn.php';require '../includes/xlsx-export.php';
$section=$_SESSION['teacher-section'];$date=$_GET['date']??date('Y-m-d');$student=trim($_GET['student']??'');
$allDates=($_GET['all_dates']??'')==='1';
if(!$allDates&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){http_response_code(400);exit('Invalid date.');}
if($allDates&&$student===''){http_response_code(400);exit('A student is required when exporting all dates.');}
if($allDates){
    $stmt=$conn->prepare("SELECT a.STUDENT_ID,COALESCE(s.STUDENT_NAME,a.STUDENT_NAME) STUDENT_NAME,s.SECTION,a.TIME_IN FROM attendance a INNER JOIN students s ON TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID) WHERE s.SECTION=? AND a.STUDENT_ID=? ORDER BY a.TIME_IN DESC");
    $stmt->bind_param('ss',$section,$student);
}else{
    $stmt=$conn->prepare("SELECT a.STUDENT_ID,COALESCE(s.STUDENT_NAME,a.STUDENT_NAME) STUDENT_NAME,s.SECTION,a.TIME_IN FROM attendance a INNER JOIN students s ON TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID) WHERE s.SECTION=? AND DATE(a.TIME_IN)=? AND (?='' OR a.STUDENT_ID=?) ORDER BY STUDENT_NAME");
    $stmt->bind_param('ssss',$section,$date,$student,$student);
}
$stmt->execute();
$result=$stmt->get_result();
$rows=[];
while($row=$result->fetch_assoc()) {
    $capturedAt=new DateTimeImmutable($row['TIME_IN']);
    $rows[]=[ $row['STUDENT_ID'],$row['STUDENT_NAME'],$row['SECTION'],$capturedAt->format('Y-m-d'),$capturedAt->format('g:i:s A'),'Present'];
}
$fileSuffix=$allDates?'all-dates':$date;
downloadAttendanceXlsx('attendance-'.preg_replace('/[^A-Za-z0-9_-]/','_',$section).'-'.$fileSuffix.'.xlsx',['Student ID','Student Name','Section','Date','Time In','Status'],$rows);
