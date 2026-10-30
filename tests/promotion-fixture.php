<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$database=getenv('PROMOTION_TEST_DATABASE');
if (!preg_match('/^biometric_promotion_test_[a-f0-9]+$/',(string)$database)) throw new RuntimeException('An isolated promotion test database is required.');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn=new mysqli('localhost','root','',$database,3306);
require_once __DIR__.'/../includes/promotion-runner.php';
$action=$argv[1] ?? '';
function fixtureSql(mysqli $conn,string $query): void { $result=$conn->query($query); echo json_encode($result instanceof mysqli_result?$result->fetch_all(MYSQLI_ASSOC):['affected'=>$conn->affected_rows],JSON_THROW_ON_ERROR); }
if ($action==='sql') fixtureSql($conn,stream_get_contents(STDIN));
elseif ($action==='plan') {
    $context=promotionContext($conn,'2026-2027');$controls=promotionDefaultTargets($context['students']);
    echo json_encode(['controls'=>$controls,'plan'=>buildPromotionPlan($context,promotionValidatedTargets($controls))],JSON_THROW_ON_ERROR);
} elseif ($action==='admin-session') {
    session_save_path(__DIR__.'/../tmp/'.$database.'/sessions');session_id('promotionadmin'.bin2hex(random_bytes(8)));session_start();
    $_SESSION=['is-authenticated'=>true,'user-role'=>'administrator','admin-user'=>$conn->query('SELECT ADMIN_USER FROM admins LIMIT 1')->fetch_assoc()['ADMIN_USER'],'csrf_token'=>bin2hex(random_bytes(32))];
    echo json_encode(['cookie'=>'PHPSESSID='.session_id(),'csrf'=>$_SESSION['csrf_token']]);session_write_close();
} elseif ($action==='setup') {
    // Only the disposable clone is changed. All existing records are retained.
    $conn->query("UPDATE students SET ENROLLMENT_STATUS='Transferred' WHERE ENROLLMENT_STATUS='Enrolled'");
    $hash=password_hash('PromotionFixtureOnly!29',PASSWORD_DEFAULT);$update=$conn->prepare('UPDATE admins SET ADMIN_PASS=?');$update->bind_param('s',$hash);$update->execute();
    foreach ([11,12] as $grade) foreach (['Academic','Techpro'] as $strand) {
        $stmt=$conn->prepare("INSERT IGNORE INTO promotion_required_subjects (SCHOOL_YEAR,GRADE,STRAND,SUBJECT) VALUES ('2026-2027',?,?,'General Mathematics')");$stmt->bind_param('is',$grade,$strand);$stmt->execute();
    }
    $studentInsert=$conn->prepare("INSERT INTO students (STUDENT_ID,STUDENT_NAME,FIRST_NAME,LAST_NAME,GRADE,STRAND,SECTION,IMAGE_PATH,ENROLLMENT_STATUS,IS_ARCHIVED) VALUES (?,?,'Test','Student',?,?,?,'',?,?)");
    $gradeInsert=$conn->prepare("INSERT INTO student_subject_results (STUDENT_ID,SCHOOL_YEAR,SUBJECT,RESULT,TEACHER_ID,GRADE,STRAND,SECTION,Q1_GRADE,Q2_GRADE,Q3_GRADE,Q4_GRADE,FINAL_GRADE) VALUES (?,'2026-2027',?,?,6,?,?,?,?,?,?,?,?)");
    $add=static function(string $id,int $grade,string $strand,string $section,float $average,string $kind='normal') use ($conn,$studentInsert,$gradeInsert): void {
        $status=$kind==='archived'?'Archived':($kind==='transferred'?'Transferred':'Enrolled');$archived=$kind==='archived'?1:0;$name='Synthetic '.$id;
        $studentInsert->bind_param('ssisssi',$id,$name,$grade,$strand,$section,$status,$archived);$studentInsert->execute();
        if ($status!=='Enrolled') return;
        $required=$conn->prepare("SELECT SUBJECT FROM promotion_required_subjects WHERE SCHOOL_YEAR='2026-2027' AND GRADE=? AND STRAND=? ORDER BY SUBJECT");$required->bind_param('is',$grade,$strand);$required->execute();
        foreach ($required->get_result()->fetch_all(MYSQLI_ASSOC) as $index=>$row) {
            $subject=$row['SUBJECT'];$value=($kind==='retained'&&$index<3)||($kind==='summer'&&$index<2)?70:$average;
            $quarters=$kind==='rounding'?[90,90,90,89.99]:array_fill(0,4,$value);
            if ($kind==='incomplete' && $index===0) $quarters[3]=null;
            [$q1,$q2,$q3,$q4]=$quarters;$final=studentFinalGrade($quarters);$remark=studentResultRemark($final);
            $gradeInsert->bind_param('sssissddddd',$id,$subject,$remark,$grade,$strand,$section,$q1,$q2,$q3,$q4,$final);$gradeInsert->execute();
        }
    };
    foreach (range(1,15) as $i) $add(sprintf('QA_H%03d',$i),7,'','Camia',$i===1?90:95);
    foreach (range(1,100) as $i) $add(sprintf('QA_R%03d',$i),7,'','Camia',$i===1?89.99:($i===2?89.50:85),$i===3?'rounding':'normal');
    $add('QA_INCOMPLETE',7,'','Camia',85,'incomplete');$add('QA_RETAINED',7,'','Camia',85,'retained');$add('QA_SUMMER',7,'','Camia',85,'summer');
    $add('QA_ARCHIVED',7,'','Camia',99,'archived');$add('QA_TRANSFERRED',7,'','Camia',99,'transferred');
    $add('QA_GRADE8',8,'','Amethyst',85);$add('QA_GRADE11',11,'Academic','Kepler',95);$add('QA_GRADE12',12,'Academic','Pythagoras',85);
    echo 'Synthetic promotion fixtures ready.';
} elseif ($action==='complete') {
    $conn->query("UPDATE student_subject_results SET Q4_GRADE=85,FINAL_GRADE=85,RESULT='Passed' WHERE STUDENT_ID='QA_INCOMPLETE'");
    $conn->query("UPDATE student_subject_results SET Q1_GRADE=70,Q2_GRADE=70,Q3_GRADE=70,Q4_GRADE=70,FINAL_GRADE=70,RESULT='Failed' WHERE STUDENT_ID='QA_INCOMPLETE' ORDER BY SUBJECT LIMIT 2");
    echo 'Missing fourth quarter resolved in the isolated clone.';
} elseif ($action==='failure-trigger') {
    $conn->query("CREATE TRIGGER promotion_test_failure BEFORE UPDATE ON students FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic rollover write failure'");echo 'Failure trigger installed in isolated database.';
} elseif ($action==='remove-trigger') {$conn->query('DROP TRIGGER promotion_test_failure');echo 'Synthetic failure trigger removed.';}
