<?php
/** Stores teacher-recorded subject results and the decision made at rollover. */
function ensureStudentPromotionSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS student_subject_results (STUDENT_ID VARCHAR(50) NOT NULL, SCHOOL_YEAR VARCHAR(20) NOT NULL, SUBJECT VARCHAR(80) NOT NULL, RESULT VARCHAR(10) NOT NULL, TEACHER_ID INT NOT NULL, GRADE TINYINT UNSIGNED NOT NULL, STRAND VARCHAR(20) NOT NULL DEFAULT '', SECTION VARCHAR(80) NOT NULL, UPDATED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (STUDENT_ID,SCHOOL_YEAR,SUBJECT))");
    $conn->query("CREATE TABLE IF NOT EXISTS student_promotion_decisions (STUDENT_ID VARCHAR(50) NOT NULL, SCHOOL_YEAR VARCHAR(20) NOT NULL, FAILED_SUBJECTS TINYINT UNSIGNED NOT NULL DEFAULT 0, DECISION VARCHAR(40) NOT NULL, UPDATED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (STUDENT_ID,SCHOOL_YEAR))");

    // Quarterly grades. Older databases only stored a Passed/Failed RESULT, so
    // these columns are added lazily and leave historical rows untouched.
    $columns = [];
    $columnResult = $conn->query("SHOW COLUMNS FROM student_subject_results");
    while ($columnResult && ($column = $columnResult->fetch_assoc())) {
        $columns[strtoupper((string) $column["Field"])] = true;
    }
    $quarterColumns = [
        "Q1_GRADE" => "DECIMAL(5,2) NULL DEFAULT NULL",
        "Q2_GRADE" => "DECIMAL(5,2) NULL DEFAULT NULL",
        "Q3_GRADE" => "DECIMAL(5,2) NULL DEFAULT NULL",
        "Q4_GRADE" => "DECIMAL(5,2) NULL DEFAULT NULL",
        "FINAL_GRADE" => "DECIMAL(5,2) NULL DEFAULT NULL",
    ];
    foreach ($quarterColumns as $column => $definition) {
        if (!isset($columns[$column])) {
            $conn->query("ALTER TABLE student_subject_results ADD COLUMN {$column} {$definition} AFTER SECTION");
        }
    }
}

/**
 * Average the quarterly grades that have been entered so far.
 *
 * With all four quarters present this is exactly (Q1 + Q2 + Q3 + Q4) / 4.
 * Partially graded students are averaged over the quarters recorded so far so
 * teachers can still see the running standing of the class.
 */
function studentFinalGrade(array $quarters): ?float
{
    $values = [];
    foreach ($quarters as $grade) {
        if ($grade === null || $grade === "") {
            continue;
        }
        $values[] = (float) $grade;
    }
    if (!$values) {
        return null;
    }
    return round(array_sum($values) / count($values), 2);
}

function studentResultRemark(?float $finalGrade): string
{
    if ($finalGrade === null) {
        return "";
    }
    return $finalGrade >= 75 ? "Passed" : "Failed";
}

function studentPromotionDecision(int $failedSubjects): string
{
    if ($failedSubjects >= 3) return 'Retained';
    if ($failedSubjects === 2) return 'Summer class required';
    return 'Promoted';
}
