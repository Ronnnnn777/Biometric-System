<?php
/** Run with C:\xampp\php\php.exe database/migrate_teacher_portal.php. CLI only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../config/db_conn.php';
require_once __DIR__ . '/../includes/teacher-assignment-schema.php';
require_once __DIR__ . '/../includes/adviser-assignment-schema.php';

ensureTeacherAssignmentSchema($conn);
ensureAdviserAssignmentSchema($conn);
if (activeTeacherSchoolYear($conn) !== '2026-2027') throw new RuntimeException('This seed is specifically for SY 2026-2027. No accounts were changed.');

$placements = [
    ['Maria Cristina','Santos',7,'','Camia'],
    ['Jose Miguel','Reyes',7,'','Orchids'],
    ['Angela Mae','Cruz',7,'','Rose'],
    ['Patricia Anne','Mendoza',8,'','Emerald'],
    ['Rafael Luis','Garcia',8,'','Jade'],
    ['Camille Rose','Bautista',9,'','Peace'],
    ['Daniel Joseph','Ramos',9,'','Faith'],
    ['Kristine Joy','Aquino',9,'','Hope'],
    ['Mark Anthony','Villanueva',10,'','Aristotle'],
    ['Andrea Nicole','Navarro',10,'','Newton'],
    ['Paolo Gabriel','Castillo',10,'','Einsten'],
    ['Isabella Marie','Torres',11,'Academic','Kepler'],
    ['Vincent Carlo','Dela Cruz',11,'Academic','Smith'],
    ['Alyssa Grace','Fernandez',11,'Techpro','Carver'],
    ['Nathaniel James','Soriano',11,'Techpro','Prescott'],
    ['Beatrice Anne','Mercado',12,'Academic','Pythagoras'],
    ['Adrian Rafael','Domingo',12,'Techpro','Hilton'],
    ['Sofia Camille','Velasco',12,'Techpro','Burbank'],
];
$reportPath = __DIR__ . '/../Teachers.md';
$oldReport = is_file($reportPath) ? file_get_contents($reportPath) : '';
$credentials = [];
foreach (explode("\n", $oldReport) as $line) {
    $cells = array_map('trim', explode('|', $line));
    if (count($cells) >= 10) $credentials[$cells[2]] = $cells[3];
}
$hazel = $conn->query("SELECT * FROM teachers WHERE TEACHER_USER='hazel@biometrics.com' AND TEACHER_STATUS='Active'")->fetch_assoc();
if (!$hazel || $hazel['TEACHER_NAME'] !== 'Hazel Charise Leoncio' || (int)$hazel['GRADE'] !== 8 || $hazel['SECTION'] !== 'Amethyst' || $hazel['SUBJECT'] !== 'Filipino') throw new RuntimeException('Hazel must retain her existing Grade 8 Amethyst Filipino account. Review the database.');
$beforeGrades = hash('sha256', json_encode($conn->query('SELECT * FROM student_subject_results ORDER BY STUDENT_ID,SCHOOL_YEAR,SUBJECT')->fetch_all(MYSQLI_ASSOC)));
$archivedBefore = $conn->query("SELECT * FROM teachers WHERE TEACHER_STATUS='Archived' ORDER BY TEACHER_ID")->fetch_all(MYSQLI_ASSOC);
$conn->begin_transaction();
try {
    $sectionQuery = $conn->prepare('SELECT SECTION_ID FROM sections WHERE GRADE=? AND STRAND=? AND SECTION=? AND IS_ACTIVE=1');
    $find = $conn->prepare('SELECT * FROM teachers WHERE TEACHER_USER=? FOR UPDATE');
    $insert = $conn->prepare("INSERT INTO teachers (TEACHER_USER,TEACHER_PASS,TEACHER_NAME,FIRST_NAME,LAST_NAME,GRADE,STRAND,SECTION,SUBJECT,TEACHER_STATUS,MUST_CHANGE_PASSWORD) VALUES (?,?,?,?,?,?,?,?,'','Active',1)");
    $rows = [[$hazel['TEACHER_NAME'],$hazel['TEACHER_USER'],'Existing password unchanged',8,'Amethyst','N/A','Filipino','Active']];
    $sectionId = (int)$conn->query("SELECT SECTION_ID FROM sections WHERE GRADE=8 AND STRAND='' AND SECTION='Amethyst'")->fetch_assoc()['SECTION_ID'];
    setTeacherAdvisory($conn, (int)$hazel['TEACHER_ID'], $sectionId);
    $created = 0;
    foreach ($placements as [$first,$last,$grade,$strand,$section]) {
        $name = "$first $last";
        $user = strtolower(str_replace(' ', '.', $first . '.' . $last)) . '@teachers.maruhat.example';
        $sectionQuery->bind_param('iss', $grade, $strand, $section); $sectionQuery->execute();
        $sectionRow = $sectionQuery->get_result()->fetch_assoc();
        if (!$sectionRow) throw new RuntimeException("Existing section not found: $grade $strand $section");
        $find->bind_param('s', $user); $find->execute(); $teacher = $find->get_result()->fetch_assoc();
        if ($teacher) {
            if ($teacher['TEACHER_NAME'] !== $name || $teacher['TEACHER_STATUS'] !== 'Active' || (int)$teacher['GRADE'] !== $grade || $teacher['SECTION'] !== $section || $teacher['STRAND'] !== $strand) throw new RuntimeException("Existing account differs from seed: $user. Nothing was overwritten.");
            $password = $credentials[$user] ?? '';
            if ($teacher['MUST_CHANGE_PASSWORD'] && ($password === '' || !password_verify($password, $teacher['TEACHER_PASS']))) throw new RuntimeException("Local initial credentials are missing or invalid for $user. Existing passwords were not reset.");
            if (!$teacher['MUST_CHANGE_PASSWORD']) $password = 'Password changed by teacher';
            $teacherId = (int)$teacher['TEACHER_ID'];
        } else {
            $password = 'Aa1!' . rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if (!password_verify($password, $hash)) throw new RuntimeException('Password verification failed.');
            $insert->bind_param('sssssiss', $user,$hash,$name,$first,$last,$grade,$strand,$section); $insert->execute();
            $teacherId = (int)$conn->insert_id;
            $created++;
        }
        setTeacherAdvisory($conn,$teacherId,(int)$sectionRow['SECTION_ID']);
        $verify = $conn->query('SELECT TEACHER_PASS,MUST_CHANGE_PASSWORD FROM teachers WHERE TEACHER_ID='.$teacherId)->fetch_assoc();
        if ($verify['MUST_CHANGE_PASSWORD'] && !password_verify($password,$verify['TEACHER_PASS'])) throw new RuntimeException('Stored login hash verification failed.');
        $rows[] = [$name,$user,$password,$grade,$section,$strand ?: 'N/A','Not assigned','Active'];
    }
    $active = (int)$conn->query("SELECT COUNT(*) n FROM teachers WHERE TEACHER_STATUS='Active'")->fetch_assoc()['n'];
    $advisers = (int)$conn->query('SELECT COUNT(*) n FROM current_teacher_advisory_assignments')->fetch_assoc()['n'];
    if ($active !== 19 || $advisers !== 19) throw new RuntimeException('Expected exactly 19 active teachers and 19 advisory classes.');
    if ($conn->query('SELECT TEACHER_PASS FROM teachers WHERE TEACHER_ID='.(int)$hazel['TEACHER_ID'])->fetch_assoc()['TEACHER_PASS'] !== $hazel['TEACHER_PASS']) throw new RuntimeException('Hazel password changed.');
    if ($beforeGrades !== hash('sha256',json_encode($conn->query('SELECT * FROM student_subject_results ORDER BY STUDENT_ID,SCHOOL_YEAR,SUBJECT')->fetch_all(MYSQLI_ASSOC)))) throw new RuntimeException('Grade preservation check failed.');
    if ($archivedBefore !== $conn->query("SELECT * FROM teachers WHERE TEACHER_STATUS='Archived' ORDER BY TEACHER_ID")->fetch_all(MYSQLI_ASSOC)) throw new RuntimeException('Archived teacher preservation check failed.');
    $report = "# Teacher accounts — SY 2026–2027\n\nPrivate local credentials. New teachers must change their password at first login.\n\nThe new names are fictional; .example usernames are login identifiers, not deliverable email addresses. Subject teaching permissions must be assigned separately by an administrator.\n\n| Teacher name | Username/email | Initial temporary password | Grade | Advisory section | Strand | Assigned subject | Status |\n|---|---|---|---|---|---|---|---|\n";
    foreach ($rows as $row) $report .= '| '.implode(' | ', $row)." |\n";
    $staging = __DIR__ . '/../storage/teacher-upgrade/Teachers.md';
    if (!is_dir(dirname($staging))) mkdir(dirname($staging),0700,true);
    if (file_put_contents($staging,$report,LOCK_EX) === false) throw new RuntimeException('Cannot write credential report.');
    if (!rename($staging,$reportPath)) throw new RuntimeException('Cannot publish local credential report.');
    $conn->commit();
    echo "Created $created teachers. Verified 19 active teachers, 19 unique advisory classes, all initial password hashes, Hazel unchanged, archived teachers unchanged, and existing grades unchanged.\n";
} catch (Throwable $error) {
    $conn->rollback();
    if ($oldReport !== '') file_put_contents($reportPath,$oldReport,LOCK_EX);
    throw $error;
}
