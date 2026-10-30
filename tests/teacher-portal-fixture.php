<?php
// CLI helper for the isolated HTTP integration suite; never connects to the live database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$database = getenv('TEACHER_TEST_DATABASE');
if (!preg_match('/^biometric_teacher_test_[a-f0-9]+$/', (string)$database)) throw new RuntimeException('An isolated teacher test database is required.');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli('localhost','root','',$database,3306);
$action = $argv[1] ?? '';
if ($action === 'setup') {
    $hash = password_hash('FixtureOnly!HazelPassword29', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE teachers SET TEACHER_PASS=? WHERE TEACHER_USER IN ('hazel@biometrics.com','julius@gmail.com')");
    $stmt->bind_param('s',$hash); $stmt->execute();
    // Synthetic credentials belong only to this disposable schema, never Teachers.md.
    $fixtureHash = password_hash('FixtureOnly!TeacherPassword29', PASSWORD_DEFAULT);
    $fixturePasswords = $conn->prepare("UPDATE teachers SET TEACHER_PASS=? WHERE TEACHER_STATUS='Active' AND TEACHER_USER<>'hazel@biometrics.com'");
    $fixturePasswords->bind_param('s', $fixtureHash); $fixturePasswords->execute();
    $adminHash = password_hash('FixtureOnly!AdminPassword29', PASSWORD_DEFAULT);
    $adminUpdate = $conn->prepare('UPDATE admins SET ADMIN_PASS=?');
    $adminUpdate->bind_param('s', $adminHash); $adminUpdate->execute();
    echo json_encode([
        'teachers'=>$conn->query("SELECT TEACHER_ID,TEACHER_NAME,TEACHER_USER,GRADE,STRAND,SECTION FROM teachers WHERE TEACHER_STATUS='Active' ORDER BY TEACHER_ID")->fetch_all(MYSQLI_ASSOC),
        'students'=>$conn->query('SELECT ID,STUDENT_ID,GRADE,STRAND,SECTION,ENROLLMENT_STATUS FROM students ORDER BY ID')->fetch_all(MYSQLI_ASSOC),
    ]);
} elseif ($action === 'plan') {
    require_once __DIR__.'/../includes/promotion-planner.php';
    $context=promotionContext($conn,'2026-2027');
    echo json_encode(buildPromotionPlan($context,promotionValidatedTargets(promotionDefaultTargets($context['students']))),JSON_THROW_ON_ERROR);
} elseif ($action === 'promotion-ready') {
    // Complete synthetic grading only in the disposable test database.
    require_once __DIR__ . '/../includes/promotion-planner.php';
    foreach ([11,12] as $grade) foreach (['Academic','Techpro'] as $strand) {
        $required=$conn->prepare("INSERT IGNORE INTO promotion_required_subjects (SCHOOL_YEAR,GRADE,STRAND,SUBJECT) VALUES ('2026-2027',?,?,'General Mathematics')");
        $required->bind_param('is',$grade,$strand);$required->execute();
    }
    $conn->query("INSERT INTO student_subject_results (STUDENT_ID,SCHOOL_YEAR,SUBJECT,RESULT,TEACHER_ID,GRADE,STRAND,SECTION,Q1_GRADE,Q2_GRADE,Q3_GRADE,Q4_GRADE,FINAL_GRADE)
        SELECT s.STUDENT_ID,'2026-2027',r.SUBJECT,'Passed',6,s.GRADE,s.STRAND,s.SECTION,85,85,85,85,85 FROM students s
        JOIN promotion_required_subjects r ON r.SCHOOL_YEAR='2026-2027' AND r.GRADE=s.GRADE AND r.STRAND=s.STRAND
        WHERE s.ENROLLMENT_STATUS='Enrolled' AND s.IS_ARCHIVED=0
        ON DUPLICATE KEY UPDATE Q1_GRADE=85,Q2_GRADE=85,Q3_GRADE=85,Q4_GRADE=85,FINAL_GRADE=85,RESULT='Passed'");
    echo 'Synthetic complete grades prepared for the teacher rollover regression.';
} elseif ($action === 'carry-year') {
    require_once __DIR__ . '/../includes/teacher-access-schema.php';
    $conn->begin_transaction();
    try {
        carryForwardTeacherAssignments($conn,'2026-2027','2027-2028');
        carryForwardTeacherAssignments($conn,'2026-2027','2027-2028');
        $conn->commit();
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
    echo 'Teacher assignments carried forward twice without duplicates.';
} elseif ($action === 'admin-session') {
    $sessionDirectory = __DIR__ . '/../tmp/' . $database . '/sessions';
    session_save_path($sessionDirectory);
    session_id('teachertestadmin' . bin2hex(random_bytes(8)));
    session_start();
    $_SESSION = ['is-authenticated'=>true,'user-role'=>'administrator','admin-user'=>$conn->query('SELECT ADMIN_USER FROM admins LIMIT 1')->fetch_assoc()['ADMIN_USER'],'csrf_token'=>bin2hex(random_bytes(32))];
    echo json_encode(['cookie'=>'PHPSESSID='.session_id(),'csrf'=>$_SESSION['csrf_token']]);
    session_write_close();
} elseif ($action === 'sql') {
    $sql = stream_get_contents(STDIN);
    $result = $conn->query($sql);
    echo json_encode($result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : ['affected'=>$conn->affected_rows]);
} elseif ($action === 'stale-archived-advisory') {
    require_once __DIR__.'/../includes/teacher-access-schema.php';
    $conn->begin_transaction();
    try {
        $archived=(int)$conn->query("SELECT TEACHER_ID FROM teachers WHERE TEACHER_STATUS='Archived' ORDER BY TEACHER_ID LIMIT 1")->fetch_assoc()['TEACHER_ID'];
        $section=(int)$conn->query("SELECT SECTION_ID FROM sections WHERE SECTION='Camia'")->fetch_assoc()['SECTION_ID'];
        $active=(int)$conn->query("SELECT TEACHER_ID FROM current_teacher_advisory_assignments WHERE SECTION='Rose'")->fetch_assoc()['TEACHER_ID'];
        $conn->query("INSERT INTO teacher_advisory_assignments (TEACHER_ID,SECTION_ID,SCHOOL_YEAR) VALUES ($archived,$section,'2026-2027')");
        $stale=(int)$conn->insert_id;
        setTeacherAdvisory($conn,$active,$section);
        if ($conn->query('SELECT RELEASED_AT FROM teacher_advisory_assignments WHERE ID='.$stale)->fetch_assoc()['RELEASED_AT']===null) throw new RuntimeException('Archived ownership was not released.');
        requireTeacherAdvisory($conn,$active);
        echo 'Stale archived reservation released without an active advisory conflict.';
    } finally { $conn->rollback(); }
} elseif ($action === 'constraints') {
    $teacher = (int)$conn->query("SELECT TEACHER_ID FROM teachers WHERE SECTION='Camia' AND TEACHER_STATUS='Active'")->fetch_assoc()['TEACHER_ID'];
    $camia = (int)$conn->query("SELECT SECTION_ID FROM sections WHERE SECTION='Camia'")->fetch_assoc()['SECTION_ID'];
    $rose = (int)$conn->query("SELECT SECTION_ID FROM sections WHERE SECTION='Rose'")->fetch_assoc()['SECTION_ID'];
    $other = (int)$conn->query("SELECT TEACHER_ID FROM teachers WHERE SECTION='Rose' AND TEACHER_STATUS='Active'")->fetch_assoc()['TEACHER_ID'];
    $archived = (int)$conn->query("SELECT TEACHER_ID FROM teachers WHERE TEACHER_STATUS='Archived' LIMIT 1")->fetch_assoc()['TEACHER_ID'];
    foreach ([[$teacher,$rose],[$archived,$camia]] as [$id,$section]) {
        try { $conn->query("INSERT INTO teacher_advisory_assignments (TEACHER_ID,SECTION_ID,SCHOOL_YEAR) VALUES ($id,$section,'2026-2027')"); throw new RuntimeException('Duplicate assignment was accepted.'); }
        catch (mysqli_sql_exception $error) { if ($error->getCode() !== 1062) throw $error; }
    }
    echo 'Both advisory uniqueness constraints rejected duplicates.';
}
