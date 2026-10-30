<?php
function teacherSubjects(): array
{
    return ['English','Filipino','Math','Science','Values Education','TLE','MAPEH','Araling Panlipunan'];
}

/** Adds structured teacher profile fields without disrupting existing logins. */
function ensureTeacherProfileColumns(mysqli $conn): void
{
    $columns=[];$result=$conn->query('SHOW COLUMNS FROM teachers');
    while($result&&($column=$result->fetch_assoc()))$columns[strtoupper($column['Field'])]=true;
    if(!isset($columns['FIRST_NAME']))$conn->query("ALTER TABLE teachers ADD COLUMN FIRST_NAME VARCHAR(80) NOT NULL DEFAULT '' AFTER TEACHER_NAME");
    if(!isset($columns['LAST_NAME']))$conn->query("ALTER TABLE teachers ADD COLUMN LAST_NAME VARCHAR(80) NOT NULL DEFAULT '' AFTER FIRST_NAME");
    if(!isset($columns['SUBJECT']))$conn->query("ALTER TABLE teachers ADD COLUMN SUBJECT VARCHAR(80) NULL AFTER SECTION");
    if(!isset($columns['GRADE']))$conn->query("ALTER TABLE teachers ADD COLUMN GRADE TINYINT UNSIGNED NULL AFTER LAST_NAME");
    if(!isset($columns['STRAND']))$conn->query("ALTER TABLE teachers ADD COLUMN STRAND VARCHAR(20) NOT NULL DEFAULT '' AFTER GRADE");
    if(!isset($columns['TEACHER_STATUS']))$conn->query("ALTER TABLE teachers ADD COLUMN TEACHER_STATUS VARCHAR(20) NOT NULL DEFAULT 'Active' AFTER SUBJECT");
    if(!isset($columns['ARCHIVED_AT']))$conn->query("ALTER TABLE teachers ADD COLUMN ARCHIVED_AT DATETIME NULL AFTER TEACHER_STATUS");

    if(!isset($columns['MUST_CHANGE_PASSWORD']))$conn->query("ALTER TABLE teachers ADD MUST_CHANGE_PASSWORD TINYINT(1) NOT NULL DEFAULT 0");
    $teachers=$conn->query("SELECT TEACHER_ID,TEACHER_NAME FROM teachers WHERE FIRST_NAME='' OR LAST_NAME=''");
    $update=$conn->prepare('UPDATE teachers SET FIRST_NAME=?,LAST_NAME=? WHERE TEACHER_ID=?');
    while($teachers&&($teacher=$teachers->fetch_assoc())){
        $parts=preg_split('/\\s+/',trim($teacher['TEACHER_NAME']),2);
        $firstName=$parts[0]??'';$lastName=$parts[1]??$firstName;
        $update->bind_param('ssi',$firstName,$lastName,$teacher['TEACHER_ID']);$update->execute();
    }
}
