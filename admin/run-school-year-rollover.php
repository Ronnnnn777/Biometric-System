<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/school-year-schema.php";
require "../includes/student-promotion-schema.php";
require "../includes/student-placement-options.php";
require "../includes/section-catalog-schema.php";
require "../includes/sectioning.php";

ensureSchoolYearRolloverSchema($conn);
ensureStudentPromotionSchema($conn);
ensureSectionCatalogColumns($conn);
seedDefaultTeacherSections($conn);

function rolloverFail(string $message): void
{
    $_SESSION["toast"] = ["message" => $message, "type" => "error"];
    header("Location: school-year-rollover.php");
    exit();
}

if (!hash_equals($_SESSION["csrf_token"] ?? "", $_POST["csrf_token"] ?? "")) {
    rolloverFail("The rollover request expired.");
}
if (($_POST["end_school_year_confirmation"] ?? "") !== "1") {
    rolloverFail("Confirm that the school year has ended before graduating Grade 12 students.");
}

$admin = $conn->prepare("SELECT ADMIN_PASS FROM admins WHERE ADMIN_USER=? LIMIT 1");
$admin->bind_param("s", $_SESSION["admin-user"]);
$admin->execute();
$account = $admin->get_result()->fetch_assoc();
if (!$account || !password_verify($_POST["current_password"] ?? "", $account["ADMIN_PASS"])) {
    rolloverFail("Administrator password is incorrect. No student records were changed.");
}

$setting = $conn->query("SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1")->fetch_assoc();
$currentYear = (string) ($setting["ACTIVE_SCHOOL_YEAR"] ?? "");
$parts = array_map("intval", explode("-", $currentYear));
if (count($parts) !== 2) {
    rolloverFail("Active school year is invalid.");
}
$nextYear = ($parts[0] + 1) . "-" . ($parts[1] + 1);

/* ---------------------------------------------------------------
 * Validate the grade-level promotion plan.
 * A source may target one strand, or two for the Grade 10 -> 11 split.
 * --------------------------------------------------------------- */
$postedPlans = $_POST["plans"] ?? [];
if (!is_array($postedPlans)) {
    $postedPlans = [];
}
$validated = normalisePromotionPlans($postedPlans, "rolloverFail");
$plans = [];
foreach ($validated as $row) {
    $plans[$row["FROM_GRADE"] . "|" . $row["FROM_STRAND"]][] = $row;
}

$sourceGroups = [];
$sourceRows = $conn->query(
    "SELECT GRADE, STRAND FROM students
      WHERE ENROLLMENT_STATUS='Enrolled' AND GRADE BETWEEN 7 AND 11
      GROUP BY GRADE, STRAND
      ORDER BY GRADE, STRAND",
);
while ($sourceRows && ($source = $sourceRows->fetch_assoc())) {
    $key = $source["GRADE"] . "|" . $source["STRAND"];
    if (!isset($plans[$key])) {
        rolloverFail("Every enrolled class must have a target grade before the rollover can run.");
    }
    $sourceGroups[] = [
        "GRADE" => (int) $source["GRADE"],
        "STRAND" => (string) $source["STRAND"],
    ];
}
$grade12Count = (int) ($conn->query(
    "SELECT COUNT(*) total FROM students WHERE GRADE=12 AND ENROLLMENT_STATUS='Enrolled'",
)->fetch_assoc()["total"] ?? 0);
if (!$sourceGroups && $grade12Count === 0) {
    rolloverFail("There are no enrolled students available for rollover.");
}

/* ---------------------------------------------------------------
 * Promotion decisions come from recorded subject results.
 * --------------------------------------------------------------- */
$failedByStudent = [];
$failedQuery = $conn->prepare(
    "SELECT STUDENT_ID, COUNT(DISTINCT SUBJECT) FAILED_SUBJECTS
       FROM student_subject_results
      WHERE SCHOOL_YEAR=? AND RESULT='Failed'
      GROUP BY STUDENT_ID",
);
$failedQuery->bind_param("s", $currentYear);
$failedQuery->execute();
$failedRows = $failedQuery->get_result();
while ($failedRows && ($failedRow = $failedRows->fetch_assoc())) {
    $failedByStudent[(string) $failedRow["STUDENT_ID"]] = (int) $failedRow["FAILED_SUBJECTS"];
}

$defaultCapacity = defaultSectionCapacity($conn);
$promoted = 0;
$summerClass = 0;
$retained = 0;
$graduated = 0;
$distribution = [];

try {
    $conn->begin_transaction();

    if ($validated) {
        // Replace the plan so removed split targets do not linger.
        $conn->query("DELETE FROM school_year_promotion_plan");
        $savePlan = $conn->prepare(
            "INSERT INTO school_year_promotion_plan (FROM_GRADE,FROM_STRAND,TO_GRADE,TO_STRAND,SPLIT_PERCENT)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE TO_GRADE=VALUES(TO_GRADE), SPLIT_PERCENT=VALUES(SPLIT_PERCENT)",
        );
        foreach ($validated as $row) {
            $savePlan->bind_param(
                "isisi",
                $row["FROM_GRADE"],
                $row["FROM_STRAND"],
                $row["TO_GRADE"],
                $row["TO_STRAND"],
                $row["SPLIT_PERCENT"],
            );
            $savePlan->execute();
        }
    }

    // Freeze each existing record's grade/strand/section before any student moves.
    $conn->query(
        "UPDATE attendance a
         INNER JOIN students s ON TRIM(s.STUDENT_ID)=TRIM(a.STUDENT_ID)
         SET a.GRADE_SNAPSHOT=COALESCE(a.GRADE_SNAPSHOT,s.GRADE),
             a.STRAND_SNAPSHOT=IF(a.STRAND_SNAPSHOT='',s.STRAND,a.STRAND_SNAPSHOT),
             a.SECTION_SNAPSHOT=IF(a.SECTION_SNAPSHOT='',s.SECTION,a.SECTION_SNAPSHOT)",
    );

    $archive = $conn->prepare(
        "INSERT IGNORE INTO student_school_history (STUDENT_ID,SCHOOL_YEAR,GRADE,STRAND,SECTION,ENROLLMENT_STATUS)
         SELECT STUDENT_ID,?,GRADE,STRAND,SECTION,ENROLLMENT_STATUS
           FROM students
          WHERE GRADE=? AND STRAND=? AND ENROLLMENT_STATUS='Enrolled'",
    );
    $sourceStudents = $conn->prepare(
        "SELECT STUDENT_ID,LAST_NAME,FIRST_NAME,SECTION
           FROM students
          WHERE GRADE=? AND STRAND=? AND ENROLLMENT_STATUS='Enrolled'
          ORDER BY SECTION,LAST_NAME,FIRST_NAME,STUDENT_ID",
    );
    $saveDecision = $conn->prepare(
        "INSERT INTO student_promotion_decisions (STUDENT_ID,SCHOOL_YEAR,FAILED_SUBJECTS,DECISION)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE FAILED_SUBJECTS=VALUES(FAILED_SUBJECTS), DECISION=VALUES(DECISION)",
    );
    $promote = $conn->prepare(
        "UPDATE students SET GRADE=?,STRAND=?,SECTION=?
          WHERE STUDENT_ID=? AND ENROLLMENT_STATUS='Enrolled'",
    );
    $trackSection = $conn->prepare(
        "INSERT IGNORE INTO school_year_rollover_sections (SCHOOL_YEAR,SECTION,GRADE,STRAND) VALUES (?,?,?,?)",
    );

    foreach ($sourceGroups as $source) {
        $fromGrade = (int) $source["GRADE"];
        $fromStrand = (string) $source["STRAND"];
        $targets = $plans[$fromGrade . "|" . $fromStrand];
        $toGrade = (int) $targets[0]["TO_GRADE"];

        $archive->bind_param("sis", $currentYear, $fromGrade, $fromStrand);
        $archive->execute();

        $sourceStudents->bind_param("is", $fromGrade, $fromStrand);
        $sourceStudents->execute();
        $classStudents = $sourceStudents->get_result();
        $promotedIds = [];
        while ($classStudents && ($classStudent = $classStudents->fetch_assoc())) {
            $studentId = (string) $classStudent["STUDENT_ID"];
            $failedSubjects = (int) ($failedByStudent[$studentId] ?? 0);
            $decision = studentPromotionDecision($failedSubjects);
            $saveDecision->bind_param("ssis", $studentId, $currentYear, $failedSubjects, $decision);
            $saveDecision->execute();

            if ($decision === "Promoted") {
                $promotedIds[] = $studentId;
            } elseif ($decision === "Summer class required") {
                $summerClass++;
            } else {
                $retained++;
            }
        }

        if (!$promotedIds) {
            continue;
        }

        // Divide the batch across the planned target strands (Grade 10 -> 11 split).
        $percents = array_map(static fn(array $target): int => (int) $target["SPLIT_PERCENT"], $targets);
        $chunks = splitStudentsByWeights($promotedIds, $percents);

        foreach ($targets as $targetIndex => $target) {
            $toStrand = (string) $target["TO_STRAND"];
            $chunk = $chunks[$targetIndex] ?? [];
            if (!$chunk) {
                continue;
            }

            $targetSections = orderedTargetSections($conn, $toGrade, $toStrand);
            $createdSections = ensureSectionCapacity($conn, $toGrade, $toStrand, count($chunk), $targetSections, $defaultCapacity);
            foreach ($createdSections as $created) {
                $trackSection->bind_param("ssis", $currentYear, $created["SECTION"], $toGrade, $toStrand);
                $trackSection->execute();
            }

            $sectionGroups = assignStudentsToSections($chunk, $targetSections, $defaultCapacity);
            foreach ($sectionGroups as $sectionGroup) {
                $toSection = (string) $sectionGroup["section"]["SECTION"];
                $label = "Grade {$toGrade}" . ($toStrand !== "" ? " · {$toStrand}" : "") . " · {$toSection}";
                $distribution[$label] = ($distribution[$label] ?? 0) + count($sectionGroup["students"]);
                foreach ($sectionGroup["students"] as $studentId) {
                    $promote->bind_param("isss", $toGrade, $toStrand, $toSection, $studentId);
                    $promote->execute();
                    $promoted += $promote->affected_rows;
                }
            }
        }
    }

    /* Grade 12 graduates are archived after their history is captured. */
    $archiveGrade12 = $conn->prepare(
        "INSERT IGNORE INTO student_school_history (STUDENT_ID,SCHOOL_YEAR,GRADE,STRAND,SECTION,ENROLLMENT_STATUS)
         SELECT STUDENT_ID,?,GRADE,STRAND,SECTION,ENROLLMENT_STATUS
           FROM students
          WHERE GRADE=12 AND ENROLLMENT_STATUS='Enrolled'",
    );
    $archiveGrade12->bind_param("s", $currentYear);
    $archiveGrade12->execute();

    $grade12Students = $conn->query(
        "SELECT STUDENT_ID FROM students WHERE GRADE=12 AND ENROLLMENT_STATUS='Enrolled'",
    );
    $graduate = $conn->prepare(
        "UPDATE students SET ENROLLMENT_STATUS='Archived',ARCHIVE_REASON='Graduated',ARCHIVED_AT=NOW()
          WHERE STUDENT_ID=? AND GRADE=12 AND ENROLLMENT_STATUS='Enrolled'",
    );
    while ($grade12Students && ($grade12Student = $grade12Students->fetch_assoc())) {
        $studentId = (string) $grade12Student["STUDENT_ID"];
        $failedSubjects = (int) ($failedByStudent[$studentId] ?? 0);
        $decision = studentPromotionDecision($failedSubjects);
        $saveDecision->bind_param("ssis", $studentId, $currentYear, $failedSubjects, $decision);
        $saveDecision->execute();
        if ($decision === "Promoted") {
            $graduate->bind_param("s", $studentId);
            $graduate->execute();
            $graduated += $graduate->affected_rows;
        } elseif ($decision === "Summer class required") {
            $summerClass++;
        } else {
            $retained++;
        }
    }

    $update = $conn->prepare("UPDATE school_year_settings SET ACTIVE_SCHOOL_YEAR=? WHERE ID=1");
    $update->bind_param("s", $nextYear);
    $update->execute();

    $conn->commit();

    $sectionsSummary = "";
    if ($distribution) {
        $partsOut = [];
        foreach ($distribution as $label => $count) {
            $partsOut[] = $label . " (" . $count . ")";
        }
        $sectionsSummary = " Sections: " . implode(", ", $partsOut) . ".";
    }
    $_SESSION["toast"] = [
        "message" => "School year rollover complete: {$promoted} promoted, {$summerClass} assigned to summer class, {$retained} retained, and {$graduated} Grade 12 students graduated and archived.{$sectionsSummary}",
        "type" => "success",
    ];
} catch (Throwable $error) {
    $conn->rollback();
    $_SESSION["toast"] = [
        "message" => "Rollover could not be completed. No student records were changed.",
        "type" => "error",
    ];
}
header("Location: school-year-rollover.php");
exit();
