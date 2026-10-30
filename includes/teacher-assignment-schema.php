<?php
require_once __DIR__ . "/teacher-profile-schema.php";
require_once __DIR__ . "/teacher-access-schema.php";

/** Stores every grade-and-section placement assigned to a teacher. */
function ensureTeacherAssignmentSchema(mysqli $conn): void
{
    ensureTeacherProfileColumns($conn);
    $conn->query(
        "CREATE TABLE IF NOT EXISTS teacher_assignments (TEACHER_ID INT NOT NULL, GRADE TINYINT UNSIGNED NOT NULL, STRAND VARCHAR(20) NOT NULL DEFAULT '', SECTION VARCHAR(80) NOT NULL, SUBJECT VARCHAR(80) NOT NULL DEFAULT '', PRIMARY KEY (TEACHER_ID,GRADE,STRAND,SECTION))",
    );
    $conn->query(
        "UPDATE teacher_assignments SET SUBJECT='Values Education' WHERE SUBJECT='Values'",
    );
    $conn->query(
        "UPDATE teachers SET SUBJECT='Values Education' WHERE SUBJECT='Values'",
    );
    ensureTeacherAccessSchema($conn);

}

function teacherAssignments(mysqli $conn, int $teacherId): array
{
    $stmt = $conn->prepare(
        "SELECT GRADE,STRAND,SECTION,SUBJECT FROM teacher_assignments WHERE SCHOOL_YEAR=(SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1) AND TEACHER_ID=? ORDER BY GRADE,SECTION",
    );
    $stmt->bind_param("i", $teacherId);
    $stmt->execute();
    $result = $stmt->get_result();
    $assignments = [];
    while ($row = $result->fetch_assoc()) {
        $assignments[] = $row;
    }
    return $assignments;
}

function postedTeacherAssignments(mysqli $conn): array
{
    $grades = $_POST["assignment_grade"] ?? [];
    $strands = $_POST["assignment_strand"] ?? [];
    $sections = $_POST["assignment_section"] ?? [];
    $subjects = $_POST["assignment_subject"] ?? [];
    $seniorSubjects = $_POST["assignment_senior_subject"] ?? [];
    if (
        !is_array($grades) ||
        !is_array($strands) ||
        !is_array($sections) ||
        !is_array($subjects) ||
        count($grades) === 0 ||
        count($grades) !== count($strands) ||
        count($grades) !== count($sections) ||
        count($grades) !== count($subjects)
    ) {
        return [];
    }
    $assignments = [];
    $seen = [];
    foreach ($grades as $index => $rawGrade) {
        $grade = (int) $rawGrade;
        $strand = trim($strands[$index] ?? "");
        $section = trim($sections[$index] ?? "");
        $subject = trim($subjects[$index] ?? "");
        $senior = in_array($grade, [11, 12], true);
        if ($senior && isset($seniorSubjects[$index])) $subject = trim((string)$seniorSubjects[$index]);
        $valid = $senior
            ? mb_strlen($subject) <= 80 && !preg_match('/[\x00-\x1f]/', $subject) &&
                in_array($strand, studentStrandsForGrade($grade), true)
            : $strand === "" && in_array($subject, teacherSubjects(), true);
        $key = $grade . "|" . $strand . "|" . $section;
        if (
            !$valid ||
            isset($seen[$key]) ||
            !isApprovedTeacherSection($conn, $grade, $strand, $section)
        ) {
            return [];
        }
        $seen[$key] = true;
        $assignments[] = [
            "grade" => $grade,
            "strand" => $strand,
            "section" => $section,
            "subject" => $subject,
        ];
    }
    return $assignments;
}

function replaceTeacherAssignments(
    mysqli $conn,
    int $teacherId,
    array $assignments,
): void {
    $delete = $conn->prepare(
        "DELETE FROM teacher_assignments WHERE SCHOOL_YEAR=(SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1) AND TEACHER_ID=?",
    );
    $delete->bind_param("i", $teacherId);
    $delete->execute();
    $add = $conn->prepare(
        "INSERT INTO teacher_assignments (TEACHER_ID,GRADE,STRAND,SECTION,SUBJECT,SCHOOL_YEAR) VALUES (?,?,?,?,?,(SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1))",
    );
    foreach ($assignments as $assignment) {
        $grade = (int) $assignment["grade"];
        $strand = $assignment["strand"];
        $section = $assignment["section"];
        $subject = $assignment["subject"];
        $add->bind_param(
            "iisss",
            $teacherId,
            $grade,
            $strand,
            $section,
            $subject,
        );
        $add->execute();
    }
}
