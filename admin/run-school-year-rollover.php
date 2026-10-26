<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/school-year-schema.php";
require "../includes/student-promotion-schema.php";
require "../includes/student-profile-schema.php";
ensureSchoolYearRolloverSchema($conn);
ensureStudentPromotionSchema($conn);
ensureStudentProfileColumns($conn);
if (!hash_equals($_SESSION["csrf_token"] ?? "", $_POST["csrf_token"] ?? "")) {
    $_SESSION["toast"] = [
        "message" => "The rollover request expired.",
        "type" => "error",
    ];
    header("Location: school-year-rollover.php");
    exit();
}
if (($_POST["end_school_year_confirmation"] ?? "") !== "1") {
    $_SESSION["toast"] = [
        "message" => "Confirm that the school year has ended before graduating Grade 12 students.",
        "type" => "error",
    ];
    header("Location: school-year-rollover.php");
    exit();
}
$admin = $conn->prepare(
    "SELECT ADMIN_PASS FROM admins WHERE ADMIN_USER=? LIMIT 1",
);
$admin->bind_param("s", $_SESSION["admin-user"]);
$admin->execute();
$account = $admin->get_result()->fetch_assoc();
if (
    !$account ||
    !password_verify($_POST["current_password"] ?? "", $account["ADMIN_PASS"])
) {
    $_SESSION["toast"] = [
        "message" =>
            "Administrator password is incorrect. No student records were changed.",
        "type" => "error",
    ];
    header("Location: school-year-rollover.php");
    exit();
}
$setting = $conn
    ->query("SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1")
    ->fetch_assoc();
$currentYear = $setting["ACTIVE_SCHOOL_YEAR"] ?? "";
$parts = array_map("intval", explode("-", $currentYear));
if (count($parts) !== 2) {
    $_SESSION["toast"] = [
        "message" => "Active school year is invalid.",
        "type" => "error",
    ];
    header("Location: school-year-rollover.php");
    exit();
}
$nextYear = $parts[0] + 1 . "-" . ($parts[1] + 1);
$postedMappings = $_POST["mappings"] ?? [];
if (!is_array($postedMappings) || !$postedMappings) {
    $_SESSION["toast"] = [
        "message" => "Choose a next-school-year section for every class.",
        "type" => "error",
    ];
    header("Location: school-year-rollover.php");
    exit();
}

$maps = [];
foreach ($postedMappings as $mapping) {
    $fromGrade = (int) ($mapping["from_grade"] ?? 0);
    $fromStrand = trim((string) ($mapping["from_strand"] ?? ""));
    $fromSection = trim((string) ($mapping["from_section"] ?? ""));
    $target = explode("|", (string) ($mapping["target"] ?? ""), 3);
    $toGrade = (int) ($target[0] ?? 0);
    $toStrand = trim((string) ($target[1] ?? ""));
    $toSection = trim((string) ($target[2] ?? ""));
    $key = $fromGrade . "|" . $fromStrand . "|" . $fromSection;
    if (
        $fromGrade < 7 ||
        $fromGrade > 11 ||
        $fromSection === "" ||
        $toGrade !== $fromGrade + 1 ||
        $toSection === "" ||
        isset($maps[$key])
    ) {
        $_SESSION["toast"] = [
            "message" => "Choose a valid next-school-year section for every class.",
            "type" => "error",
        ];
        header("Location: school-year-rollover.php");
        exit();
    }
    $maps[$key] = [
        "FROM_GRADE" => $fromGrade,
        "FROM_STRAND" => $fromStrand,
        "FROM_SECTION" => $fromSection,
        "TO_GRADE" => $toGrade,
        "TO_STRAND" => $toStrand,
        "TO_SECTION" => $toSection,
    ];
}
$sources = [];
$rows = $conn->query(
    "SELECT GRADE,STRAND,SECTION FROM students WHERE ENROLLMENT_STATUS='Enrolled' AND GRADE BETWEEN 7 AND 11 GROUP BY GRADE,STRAND,SECTION",
);
while ($rows && ($source = $rows->fetch_assoc())) {
    if (
        !isset(
            $maps[
                $source["GRADE"] .
                    "|" .
                    $source["STRAND"] .
                    "|" .
                    $source["SECTION"]
            ],
        )
    ) {
        $_SESSION["toast"] = [
            "message" =>
                "Every enrolled class must have a selected next-school-year section.",
            "type" => "error",
        ];
        header("Location: school-year-rollover.php");
        exit();
    }
    $sources[] = $source;
}
$failedByStudent = [];
$failedQuery = $conn->prepare("SELECT STUDENT_ID,COUNT(DISTINCT SUBJECT) FAILED_SUBJECTS FROM student_subject_results WHERE SCHOOL_YEAR=? AND RESULT='Failed' GROUP BY STUDENT_ID");
$failedQuery->bind_param('s', $currentYear);
$failedQuery->execute();
$failedRows = $failedQuery->get_result();
while ($failedRows && ($failedRow = $failedRows->fetch_assoc())) {
    $failedByStudent[(string) $failedRow['STUDENT_ID']] = (int) $failedRow['FAILED_SUBJECTS'];
}

// Check the final placements before changing anything. This also prevents two
// source classes from being mapped into a next-year section beyond its limit.
$finalSectionCounts = [];
$enrolledStudents = $conn->query("SELECT STUDENT_ID,GRADE,STRAND,SECTION FROM students WHERE ENROLLMENT_STATUS='Enrolled' AND GRADE BETWEEN 7 AND 12");
while ($enrolledStudents && ($student = $enrolledStudents->fetch_assoc())) {
    $studentId = (string) $student['STUDENT_ID'];
    $grade = (int) $student['GRADE'];
    $strand = (string) $student['STRAND'];
    $section = (string) $student['SECTION'];
    $decision = studentPromotionDecision((int) ($failedByStudent[$studentId] ?? 0));

    // A Grade 12 student who passed graduates; retained and summer students stay.
    if ($grade === 12 && $decision === 'Promoted') continue;
    if ($grade <= 11 && $decision === 'Promoted') {
        $mapKey = $grade . '|' . $strand . '|' . $section;
        $map = $maps[$mapKey];
        $grade = (int) $map['TO_GRADE'];
        $strand = (string) $map['TO_STRAND'];
        $section = (string) $map['TO_SECTION'];
    }
    $key = $grade . '|' . $strand . '|' . $section;
    $finalSectionCounts[$key] = (int) ($finalSectionCounts[$key] ?? 0) + 1;
    if ($finalSectionCounts[$key] > STUDENT_SECTION_CAPACITY) {
        $_SESSION['toast'] = [
            'message' => studentSectionCapacityMessage($grade, $section) . ' Adjust the rollover section mappings first.',
            'type' => 'error',
        ];
        header('Location: school-year-rollover.php');
        exit();
    }
}
try {
    $conn->begin_transaction();
    $saveMap = $conn->prepare(
        "INSERT INTO school_year_rollover_maps (FROM_GRADE,FROM_STRAND,FROM_SECTION,TO_GRADE,TO_STRAND,TO_SECTION) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE TO_GRADE=VALUES(TO_GRADE),TO_STRAND=VALUES(TO_STRAND),TO_SECTION=VALUES(TO_SECTION)",
    );
    foreach ($maps as $map) {
        $saveMap->bind_param(
            "ississ",
            $map["FROM_GRADE"],
            $map["FROM_STRAND"],
            $map["FROM_SECTION"],
            $map["TO_GRADE"],
            $map["TO_STRAND"],
            $map["TO_SECTION"],
        );
        $saveMap->execute();
    }
    $conn->query(
        "UPDATE attendance a INNER JOIN students s ON TRIM(s.STUDENT_ID)=TRIM(a.STUDENT_ID) SET a.GRADE_SNAPSHOT=COALESCE(a.GRADE_SNAPSHOT,s.GRADE),a.STRAND_SNAPSHOT=IF(a.STRAND_SNAPSHOT='',s.STRAND,a.STRAND_SNAPSHOT),a.SECTION_SNAPSHOT=IF(a.SECTION_SNAPSHOT='',s.SECTION,a.SECTION_SNAPSHOT)",
    );
    $archive = $conn->prepare(
        'INSERT IGNORE INTO student_school_history (STUDENT_ID,SCHOOL_YEAR,GRADE,STRAND,SECTION,ENROLLMENT_STATUS) SELECT STUDENT_ID,?,?,?,?,ENROLLMENT_STATUS FROM students WHERE GRADE=? AND STRAND=? AND SECTION=? AND ENROLLMENT_STATUS=\'Enrolled\'',
    );
    $promote = $conn->prepare(
        "UPDATE students SET GRADE=?,STRAND=?,SECTION=? WHERE STUDENT_ID=? AND GRADE=? AND STRAND=? AND SECTION=? AND ENROLLMENT_STATUS='Enrolled'",
    );
    $sourceStudents = $conn->prepare("SELECT STUDENT_ID FROM students WHERE GRADE=? AND STRAND=? AND SECTION=? AND ENROLLMENT_STATUS='Enrolled'");
    $saveDecision = $conn->prepare('INSERT INTO student_promotion_decisions (STUDENT_ID,SCHOOL_YEAR,FAILED_SUBJECTS,DECISION) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE FAILED_SUBJECTS=VALUES(FAILED_SUBJECTS),DECISION=VALUES(DECISION)');
    $moved = 0;
    $summerClass = 0;
    $retained = 0;
    foreach ($sources as $source) {
        $map =
            $maps[
                $source["GRADE"] .
                    "|" .
                    $source["STRAND"] .
                    "|" .
                    $source["SECTION"]
            ];
        $grade = (int) $source["GRADE"];
        $strand = $source["STRAND"];
        $section = $source["SECTION"];
        $archive->bind_param(
            "sississ",
            $currentYear,
            $grade,
            $strand,
            $section,
            $grade,
            $strand,
            $section,
        );
        $archive->execute();
        $toGrade = (int) $map["TO_GRADE"];
        $toStrand = $map["TO_STRAND"];
        $toSection = $map["TO_SECTION"];
        $sourceStudents->bind_param('iss', $grade, $strand, $section);
        $sourceStudents->execute();
        $classStudents = $sourceStudents->get_result();
        while ($classStudents && ($classStudent = $classStudents->fetch_assoc())) {
            $studentId = (string) $classStudent['STUDENT_ID'];
            $failedSubjects = (int) ($failedByStudent[$studentId] ?? 0);
            $decision = studentPromotionDecision($failedSubjects);
            $saveDecision->bind_param('ssis', $studentId, $currentYear, $failedSubjects, $decision);
            $saveDecision->execute();
            if ($decision === 'Promoted') {
                $promote->bind_param('isssiss', $toGrade, $toStrand, $toSection, $studentId, $grade, $strand, $section);
                $promote->execute();
                $moved += $promote->affected_rows;
            } elseif ($decision === 'Summer class required') {
                $summerClass++;
            } else {
                $retained++;
            }
        }
    }
    $conn->query(
        "INSERT IGNORE INTO student_school_history (STUDENT_ID,SCHOOL_YEAR,GRADE,STRAND,SECTION,ENROLLMENT_STATUS) SELECT STUDENT_ID,'$currentYear',GRADE,STRAND,SECTION,ENROLLMENT_STATUS FROM students WHERE GRADE=12 AND ENROLLMENT_STATUS='Enrolled'",
    );
    $grade12Students = $conn->query("SELECT STUDENT_ID FROM students WHERE GRADE=12 AND ENROLLMENT_STATUS='Enrolled'");
    $graduate = $conn->prepare("UPDATE students SET ENROLLMENT_STATUS='Archived',ARCHIVE_REASON='Graduated',ARCHIVED_AT=NOW() WHERE STUDENT_ID=? AND GRADE=12 AND ENROLLMENT_STATUS='Enrolled'");
    $graduated = 0;
    while ($grade12Students && ($grade12Student = $grade12Students->fetch_assoc())) {
        $studentId = (string) $grade12Student['STUDENT_ID'];
        $failedSubjects = (int) ($failedByStudent[$studentId] ?? 0);
        $decision = studentPromotionDecision($failedSubjects);
        $saveDecision->bind_param('ssis', $studentId, $currentYear, $failedSubjects, $decision);
        $saveDecision->execute();
        if ($decision === 'Promoted') {
            $graduate->bind_param('s', $studentId);
            $graduate->execute();
            $graduated += $graduate->affected_rows;
        } elseif ($decision === 'Summer class required') {
            $summerClass++;
        } else {
            $retained++;
        }
    }
    $update = $conn->prepare(
        "UPDATE school_year_settings SET ACTIVE_SCHOOL_YEAR=? WHERE ID=1",
    );
    $update->bind_param("s", $nextYear);
    $update->execute();
    $conn->commit();
    $_SESSION["toast"] = [
        "message" => "School year rollover complete: $moved promoted, $summerClass assigned to summer class, $retained retained, and $graduated Grade 12 students graduated and archived.",
        "type" => "success",
    ];
} catch (Throwable $error) {
    $conn->rollback();
    $_SESSION["toast"] = [
        "message" =>
            "Rollover could not be completed. No student records were changed.",
        "type" => "error",
    ];
}
header("Location: school-year-rollover.php");
exit();
