<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/school-year-schema.php';
ensureSchoolYearRolloverSchema($conn);

if(!hash_equals($_SESSION['csrf_token']??'',$_POST['csrf_token']??'')){
    $_SESSION['toast']=['message'=>'The rollover request expired. Please try again.','type'=>'error'];
    header('Location: school-year-rollover.php');exit;
}

$mappings=$_POST['mappings']??[];
if(!is_array($mappings)||!$mappings){
    $_SESSION['toast']=['message'=>'Add at least one valid class mapping.','type'=>'error'];
    header('Location: school-year-rollover.php');exit;
}

$validated=[];
foreach($mappings as $mapping){
    $fromGrade=(int)($mapping['from_grade']??0);
    $fromStrand=trim((string)($mapping['from_strand']??''));
    $fromSection=trim((string)($mapping['from_section']??''));
    $target=explode('|',(string)($mapping['target']??''),3);
    $toGrade=(int)($target[0]??0);
    $toStrand=trim((string)($target[1]??''));
    $toSection=trim((string)($target[2]??''));
    if($fromGrade<7||$fromGrade>11||$fromSection===''||$toGrade!==$fromGrade+1||$toSection===''){
        $_SESSION['toast']=['message'=>'Choose a valid next-grade section for every class before saving.','type'=>'error'];
        header('Location: school-year-rollover.php');exit;
    }
    $validated[]=[[$fromGrade,$fromStrand,$fromSection],[$toGrade,$toStrand,$toSection]];
}

$conn->begin_transaction();
try{
    $stmt=$conn->prepare('INSERT INTO school_year_rollover_maps (FROM_GRADE,FROM_STRAND,FROM_SECTION,TO_GRADE,TO_STRAND,TO_SECTION) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE TO_GRADE=VALUES(TO_GRADE),TO_STRAND=VALUES(TO_STRAND),TO_SECTION=VALUES(TO_SECTION)');
    foreach($validated as [$from,$to]){
        [$fromGrade,$fromStrand,$fromSection]=$from;
        [$toGrade,$toStrand,$toSection]=$to;
        $stmt->bind_param('ississ',$fromGrade,$fromStrand,$fromSection,$toGrade,$toStrand,$toSection);
        $stmt->execute();
    }
    $conn->commit();
    $_SESSION['toast']=['message'=>count($validated).' class mappings saved successfully.','type'=>'success'];
    header('Location: school-year-rollover.php?saved=1');exit;
}catch(Throwable $error){
    $conn->rollback();
    $_SESSION['toast']=['message'=>'Could not save the mappings. No changes were made.','type'=>'error'];
}
header('Location: school-year-rollover.php');exit;
