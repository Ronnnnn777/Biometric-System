<?php
require_once __DIR__ . '/attendance-schema.php';

/** Stores rollover planning separately so no student placement is changed until an admin confirms it. */
function ensureSchoolYearRolloverSchema(mysqli $conn): void
{
    ensureAttendanceSnapshotColumns($conn);
    // Retired: per-section rollover mapping was replaced by grade-level auto-sectioning.
    // Preserve retired tables for historical reference; schema setup never deletes them.
    // Grade-level destination plan. One source may target several strands
    // (Grade 10 -> Grade 11 split), hence TO_STRAND is part of the key.
    $conn->query("CREATE TABLE IF NOT EXISTS school_year_promotion_plan (FROM_GRADE TINYINT UNSIGNED NOT NULL, FROM_STRAND VARCHAR(20) NOT NULL DEFAULT '', TO_GRADE TINYINT UNSIGNED NOT NULL, TO_STRAND VARCHAR(20) NOT NULL DEFAULT '', SPLIT_PERCENT TINYINT UNSIGNED NOT NULL DEFAULT 100, UPDATED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (FROM_GRADE, FROM_STRAND, TO_STRAND))");
    ensurePromotionPlanColumns($conn);
    // Sections created automatically by a rollover, so undo can clean them up.
    $conn->query("CREATE TABLE IF NOT EXISTS school_year_rollover_sections (SCHOOL_YEAR VARCHAR(20) NOT NULL, SECTION VARCHAR(80) NOT NULL, GRADE TINYINT UNSIGNED NOT NULL, STRAND VARCHAR(20) NOT NULL DEFAULT '', PRIMARY KEY (SCHOOL_YEAR, SECTION))");
    $conn->query("CREATE TABLE IF NOT EXISTS student_school_history (STUDENT_ID VARCHAR(50) NOT NULL, SCHOOL_YEAR VARCHAR(20) NOT NULL, GRADE TINYINT UNSIGNED NOT NULL, STRAND VARCHAR(20) NOT NULL DEFAULT '', SECTION VARCHAR(80) NOT NULL, ENROLLMENT_STATUS VARCHAR(20) NOT NULL, ARCHIVED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (STUDENT_ID, SCHOOL_YEAR))");
    $conn->query("CREATE TABLE IF NOT EXISTS school_year_settings (ID TINYINT UNSIGNED NOT NULL PRIMARY KEY, ACTIVE_SCHOOL_YEAR VARCHAR(20) NOT NULL)");
    $year=(int)date('Y');$month=(int)date('n');$start=$month>=6?$year:$year-1;$label=$start.'-'.($start+1);
    $seed=$conn->prepare('INSERT IGNORE INTO school_year_settings (ID,ACTIVE_SCHOOL_YEAR) VALUES (1,?)');$seed->bind_param('s',$label);$seed->execute();
    schoolYearBackfillAttendanceSnapshots($conn);
}

/**
 * Migrates an older promotion plan (one target per source) to the
 * multi-target shape needed for Grade 10 -> Grade 11 strand splits.
 */
function ensurePromotionPlanColumns(mysqli $conn): void
{
    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM school_year_promotion_plan');
    while ($result && ($column = $result->fetch_assoc())) $columns[strtoupper($column['Field'])] = true;
    if (!isset($columns['SPLIT_PERCENT'])) {
        $conn->query('ALTER TABLE school_year_promotion_plan ADD COLUMN SPLIT_PERCENT TINYINT UNSIGNED NOT NULL DEFAULT 100 AFTER TO_STRAND');
    }

    $primary = [];
    $keys = $conn->query("SHOW KEYS FROM school_year_promotion_plan WHERE Key_name='PRIMARY'");
    while ($keys && ($key = $keys->fetch_assoc())) $primary[] = strtoupper($key['Column_name']);
    sort($primary);
    if ($primary !== ['FROM_GRADE', 'FROM_STRAND', 'TO_STRAND']) {
        $conn->query('ALTER TABLE school_year_promotion_plan DROP PRIMARY KEY, ADD PRIMARY KEY (FROM_GRADE,FROM_STRAND,TO_STRAND)');
    }
}

/**
 * Fills missing attendance snapshots for the active school year only.
 * Older records are left untouched so their recorded placement is never rewritten.
 */
function schoolYearBackfillAttendanceSnapshots(mysqli $conn): void
{
    $setting=$conn->query('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1 LIMIT 1');
    $row=$setting?$setting->fetch_assoc():null;
    $activeYear=(string)($row['ACTIVE_SCHOOL_YEAR']??'');
    if(!preg_match('/^(\d{4})-(\d{4})$/',$activeYear,$years))return;
    $startDate=$years[1].'-06-01';
    $stmt=$conn->prepare("UPDATE attendance a INNER JOIN students s ON TRIM(s.STUDENT_ID)=TRIM(a.STUDENT_ID) SET a.GRADE_SNAPSHOT=COALESCE(a.GRADE_SNAPSHOT,s.GRADE),a.STRAND_SNAPSHOT=IF(a.STRAND_SNAPSHOT='',s.STRAND,a.STRAND_SNAPSHOT),a.SECTION_SNAPSHOT=IF(a.SECTION_SNAPSHOT='',s.SECTION,a.SECTION_SNAPSHOT) WHERE (a.SECTION_SNAPSHOT='' OR a.SECTION_SNAPSHOT IS NULL) AND DATE(a.TIME_IN)>=?");
    $stmt->bind_param('s',$startDate);
    $stmt->execute();
}
