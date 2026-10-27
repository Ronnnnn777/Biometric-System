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
    if (!isset($columns['ARCHIVE_REASON'])) $conn->query("ALTER TABLE students ADD COLUMN ARCHIVE_REASON VARCHAR(40) NOT NULL DEFAULT '' AFTER ENROLLMENT_STATUS");
    if (!isset($columns['ARCHIVED_AT'])) $conn->query("ALTER TABLE students ADD COLUMN ARCHIVED_AT DATETIME NULL AFTER ARCHIVE_REASON");
    // Graduation is performed only by the year-end rollover and is kept in
    // the archive with its reason, not as an active manual status.
    $conn->query("UPDATE students SET ENROLLMENT_STATUS='Archived', ARCHIVE_REASON='Graduated', ARCHIVED_AT=COALESCE(ARCHIVED_AT, NOW()) WHERE ENROLLMENT_STATUS='Graduated'");

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
    return ['Enrolled', 'Transferred', 'Dropped', 'Archived'];
}

function isValidStudentEnrollmentStatus(string $status): bool
{
    return in_array($status, studentEnrollmentStatuses(), true);
}

const STUDENT_SECTION_CAPACITY = 50;

/** Returns whether an active student can be placed in the requested class. */
function studentSectionHasCapacity(mysqli $conn, int $grade, string $strand, string $section, int $excludeStudentId = 0): bool
{
    $sql = "SELECT COUNT(*) AS total FROM students WHERE GRADE=? AND STRAND=? AND SECTION=? AND COALESCE(ENROLLMENT_STATUS,'Enrolled')='Enrolled'";
    if ($excludeStudentId > 0) $sql .= ' AND ID<>?';
    $statement = $conn->prepare($sql);
    if ($excludeStudentId > 0) {
        $statement->bind_param('issi', $grade, $strand, $section, $excludeStudentId);
    } else {
        $statement->bind_param('iss', $grade, $strand, $section);
    }
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    return (int) ($row['total'] ?? 0) < STUDENT_SECTION_CAPACITY;
}

function studentSectionCapacityMessage(int $grade, string $section): string
{
    return "Grade $grade - $section already has the maximum of " . STUDENT_SECTION_CAPACITY . ' enrolled students.';
}
