<?php
require "page-guard.php";
require "../config/db_conn.php";
$date = $_GET['date'] ?? date('Y-m-d');
$requestedSection = $_GET['section'] ?? 'all';
$section = $_SESSION['user-role'] === 'teacher' ? ($_SESSION['teacher-section'] ?? '') : $requestedSection;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { http_response_code(400); exit('Invalid date.'); }
$stmt = $conn->prepare("SELECT a.STUDENT_ID, COALESCE(s.STUDENT_NAME,a.STUDENT_NAME) AS STUDENT_NAME, s.SECTION, a.TIME_IN FROM attendance a LEFT JOIN students s ON TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID) WHERE DATE(a.TIME_IN)=? AND (?='all' OR s.SECTION=?) ORDER BY s.SECTION, STUDENT_NAME");
$stmt->bind_param('sss', $date, $section, $section);
$stmt->execute(); $rows = $stmt->get_result();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="attendance-' . $date . '.csv"');
$out = fopen('php://output', 'w'); fputcsv($out, ['Student ID','Student Name','Section','Date','Time In','Status']);
while ($row = $rows->fetch_assoc()) fputcsv($out, [$row['STUDENT_ID'],$row['STUDENT_NAME'],$row['SECTION'] ?? 'N/A',$date,$row['TIME_IN'],'Present']);
fclose($out);
?>
