<?php
/** Stores rollover planning separately so no student placement is changed until an admin confirms it. */
function ensureSchoolYearRolloverSchema(mysqli $conn): void
{
    $columns=[];$result=$conn->query('SHOW COLUMNS FROM attendance');
    while($result&&($column=$result->fetch_assoc()))$columns[strtoupper($column['Field'])]=true;
    if(!isset($columns['GRADE_SNAPSHOT']))$conn->query('ALTER TABLE attendance ADD COLUMN GRADE_SNAPSHOT TINYINT UNSIGNED NULL AFTER STUDENT_NAME');
    if(!isset($columns['STRAND_SNAPSHOT']))$conn->query("ALTER TABLE attendance ADD COLUMN STRAND_SNAPSHOT VARCHAR(20) NOT NULL DEFAULT '' AFTER GRADE_SNAPSHOT");
    if(!isset($columns['SECTION_SNAPSHOT']))$conn->query("ALTER TABLE attendance ADD COLUMN SECTION_SNAPSHOT VARCHAR(80) NOT NULL DEFAULT '' AFTER STRAND_SNAPSHOT");
    $conn->query("CREATE TABLE IF NOT EXISTS school_year_rollover_maps (FROM_GRADE TINYINT UNSIGNED NOT NULL, FROM_STRAND VARCHAR(20) NOT NULL DEFAULT '', FROM_SECTION VARCHAR(80) NOT NULL, TO_GRADE TINYINT UNSIGNED NOT NULL, TO_STRAND VARCHAR(20) NOT NULL DEFAULT '', TO_SECTION VARCHAR(80) NOT NULL, UPDATED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (FROM_GRADE, FROM_STRAND, FROM_SECTION))");
    $conn->query("CREATE TABLE IF NOT EXISTS student_school_history (STUDENT_ID VARCHAR(50) NOT NULL, SCHOOL_YEAR VARCHAR(20) NOT NULL, GRADE TINYINT UNSIGNED NOT NULL, STRAND VARCHAR(20) NOT NULL DEFAULT '', SECTION VARCHAR(80) NOT NULL, ENROLLMENT_STATUS VARCHAR(20) NOT NULL, ARCHIVED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (STUDENT_ID, SCHOOL_YEAR))");
    $conn->query("CREATE TABLE IF NOT EXISTS school_year_settings (ID TINYINT UNSIGNED NOT NULL PRIMARY KEY, ACTIVE_SCHOOL_YEAR VARCHAR(20) NOT NULL)");
    $year=(int)date('Y');$month=(int)date('n');$start=$month>=6?$year:$year-1;$label=$start.'-'.($start+1);
    $seed=$conn->prepare('INSERT IGNORE INTO school_year_settings (ID,ACTIVE_SCHOOL_YEAR) VALUES (1,?)');$seed->bind_param('s',$label);$seed->execute();
}
