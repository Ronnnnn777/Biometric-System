<?php
require_once __DIR__ . '/teacher-assignment-schema.php';

/** Remembers School Form advisers so an account created later can still receive its class. */
function ensureAdviserAssignmentSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS imported_adviser_assignments (ADVISER_KEY VARCHAR(255) NOT NULL, ADVISER_NAME VARCHAR(160) NOT NULL, GRADE TINYINT UNSIGNED NOT NULL, STRAND VARCHAR(20) NOT NULL DEFAULT '', SECTION VARCHAR(80) NOT NULL, IMPORTED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (ADVISER_KEY, GRADE, STRAND, SECTION))");
}

function adviserAssignmentNameKey(string $name): string
{
    $tokens = preg_split('/\\s+/', preg_replace('/[^a-z]+/i', ' ', strtoupper($name)) ?: '') ?: [];
    $tokens = array_values(array_filter($tokens, static fn(string $token): bool => strlen($token) > 1));
    return implode(' ', $tokens);
}

function adviserAssignmentNamesMatch(string $left, string $right): bool
{
    $leftKey = adviserAssignmentNameKey($left);
    $rightKey = adviserAssignmentNameKey($right);
    if ($leftKey === '' || $rightKey === '') return false;
    if ($leftKey === $rightKey) return true;
    $leftTokens = explode(' ', $leftKey);
    $rightTokens = explode(' ', $rightKey);
    return $leftTokens[0] === $rightTokens[0] && end($leftTokens) === end($rightTokens);
}

function rememberImportedAdviserAssignment(mysqli $conn, string $adviserName, int $grade, string $strand, string $section): void
{
    $key = adviserAssignmentNameKey($adviserName);
    if ($key === '' || $grade < 7 || $grade > 12 || $section === '') return;
    $stmt = $conn->prepare('INSERT INTO imported_adviser_assignments (ADVISER_KEY, ADVISER_NAME, GRADE, STRAND, SECTION) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE ADVISER_NAME=VALUES(ADVISER_NAME), IMPORTED_AT=CURRENT_TIMESTAMP');
    $stmt->bind_param('ssiss', $key, $adviserName, $grade, $strand, $section);
    $stmt->execute();
}

/** Keeps the older primary Grade/Section profile fields aligned with an automatic adviser assignment. */
function syncTeacherPrimaryAssignment(mysqli $conn, int $teacherId): void
{
    $current = $conn->prepare('SELECT GRADE, SECTION FROM teachers WHERE TEACHER_ID=? LIMIT 1');
    $current->bind_param('i', $teacherId);$current->execute();$profile=$current->get_result()->fetch_assoc();
    if ((int)($profile['GRADE'] ?? 0) >= 7 && trim((string)($profile['SECTION'] ?? '')) !== '') return;
    $assignment = $conn->prepare('SELECT GRADE, STRAND, SECTION, SUBJECT FROM teacher_assignments WHERE TEACHER_ID=? ORDER BY GRADE, SECTION LIMIT 1');
    $assignment->bind_param('i', $teacherId);$assignment->execute();$primary=$assignment->get_result()->fetch_assoc();
    if (!$primary) return;
    $update = $conn->prepare('UPDATE teachers SET GRADE=?, STRAND=?, SECTION=?, SUBJECT=? WHERE TEACHER_ID=?');
    $grade=(int)$primary['GRADE'];$strand=(string)$primary['STRAND'];$section=(string)$primary['SECTION'];$subject=(string)$primary['SUBJECT'];
    $update->bind_param('isssi', $grade, $strand, $section, $subject, $teacherId);$update->execute();
}

/** Adds each remembered adviser placement belonging to this teacher. Returns new assignments added. */
function assignRememberedAdviserClasses(mysqli $conn, int $teacherId, string $teacherName): int
{
    $records = $conn->query('SELECT ADVISER_NAME, GRADE, STRAND, SECTION FROM imported_adviser_assignments');
    $insert = $conn->prepare('INSERT IGNORE INTO teacher_assignments (TEACHER_ID, GRADE, STRAND, SECTION, SUBJECT) VALUES (?, ?, ?, ?, ?)');
    $added = 0;$firstAssignment=null;
    while ($records && $record = $records->fetch_assoc()) {
        if (!adviserAssignmentNamesMatch($teacherName, (string) $record['ADVISER_NAME'])) continue;
        $grade = (int) $record['GRADE'];
        $strand = (string) $record['STRAND'];
        $section = (string) $record['SECTION'];
        $firstAssignment ??= ['grade'=>$grade,'strand'=>$strand,'section'=>$section];
        $subject = '';
        $insert->bind_param('iisss', $teacherId, $grade, $strand, $section, $subject);
        $insert->execute();
        $added += $insert->affected_rows;
    }
    if ($firstAssignment !== null) syncTeacherPrimaryAssignment($conn, $teacherId);
    return $added;
}
