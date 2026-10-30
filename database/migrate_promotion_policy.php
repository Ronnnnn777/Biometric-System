<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../config/db_conn.php';
require_once __DIR__ . '/../includes/promotion-policy-schema.php';
$year = $conn->query('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1')->fetch_assoc()['ACTIVE_SCHOOL_YEAR'];
if (!preg_match('/^(\d{4})-(\d{4})$/', $year, $match) || (int)$match[2] !== (int)$match[1]+1) throw new RuntimeException('Invalid active school year.');
ensurePromotionPolicySchema($conn);
$conn->begin_transaction();
try {
    // Confirm the existing junior-high honor labels explicitly; SHS has no implied honor policy.
    $honor = $conn->prepare("INSERT INTO promotion_honor_sections (GRADE,STRAND,SECTION_ID)
        SELECT GRADE,STRAND,SECTION_ID FROM sections WHERE GRADE=? AND STRAND='' AND SECTION=? AND IS_ACTIVE=1
        AND NOT EXISTS (SELECT 1 FROM promotion_honor_sections p WHERE p.GRADE=? AND p.STRAND='')");
    foreach ([8=>'Amethyst',9=>'Peace',10=>'Aristotle'] as $grade=>$name) {
        $honor->bind_param('isi',$grade,$name,$grade); $honor->execute();
    }
    $has = $conn->prepare("SELECT 1 FROM promotion_required_subjects WHERE SCHOOL_YEAR=? AND GRADE=? AND STRAND='' LIMIT 1");
    $subject = $conn->prepare("INSERT INTO promotion_required_subjects (SCHOOL_YEAR,GRADE,STRAND,SUBJECT) VALUES (?,?,'',?)");
    foreach ([7,8,9,10] as $grade) {
        $has->bind_param('si',$year,$grade); $has->execute();
        if ($has->get_result()->num_rows) continue; // Never overwrite an administrator's curriculum.
        foreach (teacherSubjects() as $name) { $subject->bind_param('sis',$year,$grade,$name); $subject->execute(); }
    }
    $conn->commit();
    echo "Promotion policy ready for $year. Junior-high honor designations and subject requirements installed idempotently. No student, grade, attendance, or teacher records changed.\n";
} catch (Throwable $error) { $conn->rollback(); throw $error; }
