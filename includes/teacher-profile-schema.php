<?php
function teacherSubjects(): array
{
    return ['English','Filipino','Math','Science','Values','TLE','MAPEH','Araling Panlipunan'];
}

/** Adds structured teacher profile fields without disrupting existing logins. */
function ensureTeacherProfileColumns(mysqli $conn): void
{
    $columns=[];$result=$conn->query('SHOW COLUMNS FROM teachers');
    while($result&&($column=$result->fetch_assoc()))$columns[strtoupper($column['Field'])]=true;
    if(!isset($columns['FIRST_NAME']))$conn->query("ALTER TABLE teachers ADD COLUMN FIRST_NAME VARCHAR(80) NOT NULL DEFAULT '' AFTER TEACHER_NAME");
    if(!isset($columns['LAST_NAME']))$conn->query("ALTER TABLE teachers ADD COLUMN LAST_NAME VARCHAR(80) NOT NULL DEFAULT '' AFTER FIRST_NAME");
    if(!isset($columns['SUBJECT']))$conn->query("ALTER TABLE teachers ADD COLUMN SUBJECT VARCHAR(80) NULL AFTER SECTION");

    $teachers=$conn->query("SELECT TEACHER_ID,TEACHER_NAME FROM teachers WHERE FIRST_NAME='' OR LAST_NAME=''");
    $update=$conn->prepare('UPDATE teachers SET FIRST_NAME=?,LAST_NAME=? WHERE TEACHER_ID=?');
    while($teachers&&($teacher=$teachers->fetch_assoc())){
        $parts=preg_split('/\\s+/',trim($teacher['TEACHER_NAME']),2);
        $firstName=$parts[0]??'';$lastName=$parts[1]??$firstName;
        $update->bind_param('ssi',$firstName,$lastName,$teacher['TEACHER_ID']);$update->execute();
    }
}
