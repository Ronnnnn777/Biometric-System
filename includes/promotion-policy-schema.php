<?php
require_once __DIR__ . '/teacher-profile-schema.php';

/** Explicit configuration and immutable rollover audit records. Run outside transactions. */
function ensurePromotionPolicySchema(mysqli $conn): void
{
    // Some legacy installations have only the enrollment status archive marker.
    if (!$conn->query("SHOW COLUMNS FROM students LIKE 'IS_ARCHIVED'")->num_rows) $conn->query('ALTER TABLE students ADD IS_ARCHIVED TINYINT(1) NOT NULL DEFAULT 0');
    if (!$conn->query("SHOW COLUMNS FROM students LIKE 'ARCHIVED_BY'")->num_rows) $conn->query('ALTER TABLE students ADD ARCHIVED_BY VARCHAR(100) NULL');
    $conn->query("CREATE TABLE IF NOT EXISTS promotion_honor_sections (
        GRADE TINYINT UNSIGNED NOT NULL, STRAND VARCHAR(20) NOT NULL DEFAULT '', SECTION_ID INT NOT NULL,
        PRIMARY KEY (GRADE,STRAND), UNIQUE KEY (SECTION_ID), FOREIGN KEY (SECTION_ID) REFERENCES sections(SECTION_ID)
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE IF NOT EXISTS promotion_required_subjects (
        SCHOOL_YEAR VARCHAR(20) NOT NULL, GRADE TINYINT UNSIGNED NOT NULL, STRAND VARCHAR(20) NOT NULL DEFAULT '', SUBJECT VARCHAR(80) NOT NULL,
        PRIMARY KEY (SCHOOL_YEAR,GRADE,STRAND,SUBJECT)
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE IF NOT EXISTS school_year_rollover_runs (
        ID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, SOURCE_YEAR VARCHAR(20) NOT NULL, TARGET_YEAR VARCHAR(20) NOT NULL,
        PREVIEW_HASH CHAR(64) NOT NULL, COMPLETED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNDONE_AT DATETIME NULL,
        ACTIVE_SOURCE_YEAR VARCHAR(20) GENERATED ALWAYS AS (IF(UNDONE_AT IS NULL,SOURCE_YEAR,NULL)) STORED,
        UNIQUE KEY uq_completed_source (ACTIVE_SOURCE_YEAR)
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE IF NOT EXISTS school_year_rollover_students (
        RUN_ID BIGINT UNSIGNED NOT NULL, STUDENT_ID VARCHAR(50) NOT NULL, BEFORE_STATE LONGTEXT NOT NULL, AFTER_STATE LONGTEXT NOT NULL, ACADEMIC_STANDING LONGTEXT NOT NULL,
        PRIMARY KEY (RUN_ID,STUDENT_ID), FOREIGN KEY (RUN_ID) REFERENCES school_year_rollover_runs(ID)
    ) ENGINE=InnoDB");
    if (!$conn->query("SHOW COLUMNS FROM school_year_rollover_sections LIKE 'RUN_ID'")->num_rows) {
        $conn->query('ALTER TABLE school_year_rollover_sections ADD RUN_ID BIGINT UNSIGNED NULL');
    }
}

function promotionHonorSectionMap(mysqli $conn): array
{
    $map = [];
    foreach ($conn->query('SELECT p.GRADE,p.STRAND,s.SECTION FROM promotion_honor_sections p JOIN sections s ON s.SECTION_ID=p.SECTION_ID AND s.GRADE=p.GRADE AND s.STRAND=p.STRAND AND s.IS_ACTIVE=1') as $row) {
        $map[(int)$row['GRADE']][$row['STRAND']] = $row['SECTION'];
    }
    return $map;
}
