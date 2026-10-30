<?php
require 'page-guard.php';
require_once '../config/db_conn.php';
require_once '../includes/promotion-planner.php';
if ($_SERVER['REQUEST_METHOD']!=='POST' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],(string)($_POST['csrf_token'] ?? ''))) { http_response_code(400); exit('Invalid request.'); }
$conn->begin_transaction();
try {
    $year=$conn->query('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1 FOR UPDATE')->fetch_assoc()['ACTIVE_SCHOOL_YEAR'];
    if ($year!==($_POST['expected_school_year'] ?? '')) throw new RuntimeException('The school year changed. Refresh before saving.');
    if (($_POST['action'] ?? '')==='subjects') {
        [$grade,$strand]=array_pad(explode('|',(string)($_POST['placement'] ?? ''),2),2,''); $grade=(int)$grade;
        if ($grade<7 || $grade>12 || ($grade>=11 ? !in_array($strand,studentStrandsForGrade($grade),true) : $strand!=='')) throw new RuntimeException('Choose a valid grade and strand.');
        $subjects=array_values(array_filter(array_map('trim',preg_split('/[\r\n,]+/',(string)($_POST['subjects'] ?? '')))));
        if (!$subjects || count($subjects)>40 || count(array_unique(array_map('promotionSubjectKey',$subjects)))!==count($subjects)) throw new RuntimeException('Enter 1–40 unique subject names.');
        foreach ($subjects as $subject) if (mb_strlen($subject)>80 || preg_match('/[\x00-\x1f]/',$subject)) throw new RuntimeException('Use valid subject names of at most 80 characters.');
        $delete=$conn->prepare('DELETE FROM promotion_required_subjects WHERE SCHOOL_YEAR=? AND GRADE=? AND STRAND=?');
        $delete->bind_param('sis',$year,$grade,$strand); $delete->execute();
        $insert=$conn->prepare('INSERT INTO promotion_required_subjects (SCHOOL_YEAR,GRADE,STRAND,SUBJECT) VALUES (?,?,?,?)');
        foreach ($subjects as $subject) { $insert->bind_param('siss',$year,$grade,$strand,$subject); $insert->execute(); }
    } elseif (($_POST['action'] ?? '')==='honor') {
        $id=(int)($_POST['honor_section_id'] ?? 0);
        $find=$conn->prepare('SELECT GRADE,STRAND FROM sections WHERE SECTION_ID=? AND IS_ACTIVE=1');
        $find->bind_param('i',$id); $find->execute(); $section=$find->get_result()->fetch_assoc();
        if (!$section || (int)$section['GRADE']<7 || (int)$section['GRADE']>12) throw new RuntimeException('Choose an existing active section.');
        $insert=$conn->prepare('INSERT INTO promotion_honor_sections (GRADE,STRAND,SECTION_ID) VALUES (?,?,?) ON DUPLICATE KEY UPDATE SECTION_ID=VALUES(SECTION_ID)');
        $insert->bind_param('isi',$section['GRADE'],$section['STRAND'],$id); $insert->execute();
    } else throw new RuntimeException('Choose a valid settings action.');
    $conn->commit(); $_SESSION['toast']=['message'=>'Promotion settings saved. Refresh and review the resulting preview.','type'=>'success'];
} catch (Throwable $exception) { $conn->rollback(); $_SESSION['toast']=['message'=>'Settings were not saved. '.($exception instanceof mysqli_sql_exception ? 'Review the selected section and subject names.' : $exception->getMessage()),'type'=>'error']; }
header('Location: school-year-rollover.php'); exit;
