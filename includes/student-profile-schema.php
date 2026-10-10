<?php
/** Keeps older installations compatible with structured student profiles. */
function ensureStudentProfileColumns(mysqli $conn): void
{
    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM students');
    while ($result && ($column = $result->fetch_assoc())) {
        $columns[strtoupper($column['Field'])] = true;
    }
    if (!isset($columns['FIRST_NAME'])) $conn->query("ALTER TABLE students ADD COLUMN FIRST_NAME VARCHAR(80) NOT NULL DEFAULT '' AFTER STUDENT_NAME");
    if (!isset($columns['MIDDLE_NAME'])) $conn->query("ALTER TABLE students ADD COLUMN MIDDLE_NAME VARCHAR(80) NOT NULL DEFAULT '' AFTER FIRST_NAME");
    if (!isset($columns['LAST_NAME'])) $conn->query("ALTER TABLE students ADD COLUMN LAST_NAME VARCHAR(80) NOT NULL DEFAULT '' AFTER MIDDLE_NAME");
    if (!isset($columns['GRADE'])) $conn->query("ALTER TABLE students ADD COLUMN GRADE TINYINT UNSIGNED NULL AFTER LAST_NAME");
    if (!isset($columns['AGE'])) $conn->query("ALTER TABLE students ADD COLUMN AGE TINYINT UNSIGNED NULL AFTER GRADE");
    if (!isset($columns['STRAND'])) $conn->query("ALTER TABLE students ADD COLUMN STRAND VARCHAR(20) NOT NULL DEFAULT '' AFTER AGE");
    if (!isset($columns['ADDRESS'])) $conn->query("ALTER TABLE students ADD COLUMN ADDRESS VARCHAR(255) NOT NULL DEFAULT '' AFTER STRAND");
    if (!isset($columns['ENROLLMENT_STATUS'])) $conn->query("ALTER TABLE students ADD COLUMN ENROLLMENT_STATUS VARCHAR(20) NOT NULL DEFAULT 'Enrolled' AFTER SECTION");

    $students = $conn->query("SELECT ID, STUDENT_NAME FROM students WHERE FIRST_NAME='' OR LAST_NAME=''");
    $update = $conn->prepare('UPDATE students SET FIRST_NAME=?, LAST_NAME=? WHERE ID=?');
    while ($students && ($student = $students->fetch_assoc())) {
        $parts = preg_split('/\\s+/', trim($student['STUDENT_NAME']), 2);
        $firstName = $parts[0] ?? '';
        $lastName = $parts[1] ?? $firstName;
        $update->bind_param('ssi', $firstName, $lastName, $student['ID']);
        $update->execute();
    }
}

function isValidStudentAge(int $age): bool
{
    return $age >= 3 && $age <= 120;
}

function studentEnrollmentStatuses(): array
{
    return ['Enrolled', 'Transferred', 'Dropped', 'Graduated', 'Archived'];
}

function isValidStudentEnrollmentStatus(string $status): bool
{
    return in_array($status, studentEnrollmentStatuses(), true);
}
