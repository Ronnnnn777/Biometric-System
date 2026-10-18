<?php
/** Stores teacher-recorded subject results and the decision made at rollover. */
function ensureStudentPromotionSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS student_subject_results (STUDENT_ID VARCHAR(50) NOT NULL, SCHOOL_YEAR VARCHAR(20) NOT NULL, SUBJECT VARCHAR(80) NOT NULL, RESULT VARCHAR(10) NOT NULL, TEACHER_ID INT NOT NULL, GRADE TINYINT UNSIGNED NOT NULL, STRAND VARCHAR(20) NOT NULL DEFAULT '', SECTION VARCHAR(80) NOT NULL, UPDATED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (STUDENT_ID,SCHOOL_YEAR,SUBJECT))");
    $conn->query("CREATE TABLE IF NOT EXISTS student_promotion_decisions (STUDENT_ID VARCHAR(50) NOT NULL, SCHOOL_YEAR VARCHAR(20) NOT NULL, FAILED_SUBJECTS TINYINT UNSIGNED NOT NULL DEFAULT 0, DECISION VARCHAR(40) NOT NULL, UPDATED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (STUDENT_ID,SCHOOL_YEAR))");
}

function studentPromotionDecision(int $failedSubjects): string
{
    if ($failedSubjects >= 3) return 'Retained';
    if ($failedSubjects === 2) return 'Summer class required';
    return 'Promoted';
}
