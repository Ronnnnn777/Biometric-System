<?php
require_once __DIR__ . '/teacher-assignment-schema.php';

/** Remembers School Form advisers so an account created later can still receive its class. */
function ensureAdviserAssignmentSchema(mysqli $conn): void
{
    ensureTeacherAssignmentSchema($conn);
    $conn->query("CREATE TABLE IF NOT EXISTS imported_adviser_assignments (ADVISER_KEY VARCHAR(255) NOT NULL, ADVISER_NAME VARCHAR(160) NOT NULL, GRADE TINYINT UNSIGNED NOT NULL, STRAND VARCHAR(20) NOT NULL DEFAULT '', SECTION VARCHAR(80) NOT NULL, IMPORTED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, SCHOOL_YEAR VARCHAR(20) NOT NULL DEFAULT '', PRIMARY KEY (ADVISER_KEY, SCHOOL_YEAR, GRADE, STRAND, SECTION))");
    if (!$conn->query("SHOW COLUMNS FROM imported_adviser_assignments LIKE 'SCHOOL_YEAR'")->num_rows) {
        $year = $conn->real_escape_string(activeTeacherSchoolYear($conn));
        $conn->query("ALTER TABLE imported_adviser_assignments ADD SCHOOL_YEAR VARCHAR(20) NOT NULL DEFAULT '$year', DROP PRIMARY KEY, ADD PRIMARY KEY (ADVISER_KEY,SCHOOL_YEAR,GRADE,STRAND,SECTION)");
    }
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
    $stmt = $conn->prepare('INSERT INTO imported_adviser_assignments (ADVISER_KEY, ADVISER_NAME, GRADE, STRAND, SECTION,SCHOOL_YEAR) VALUES (?, ?, ?, ?, ?,(SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1)) ON DUPLICATE KEY UPDATE ADVISER_NAME=VALUES(ADVISER_NAME), IMPORTED_AT=CURRENT_TIMESTAMP');
    $stmt->bind_param('ssiss', $key, $adviserName, $grade, $strand, $section);
    $stmt->execute();
}

/** Keeps the older primary Grade/Section profile fields aligned with an automatic adviser assignment. */
function syncTeacherPrimaryAssignment(mysqli $conn, int $teacherId): void
{
    syncTeacherAdvisoryProfile($conn,$teacherId);
}

/** Adds each remembered adviser placement belonging to this teacher. Returns new assignments added. */
function assignRememberedAdviserClasses(mysqli $conn, int $teacherId, string $teacherName): int
{
    if (teacherAdvisoryAssignments($conn, $teacherId)) return 0;
    $matches = [];
    $records = $conn->query('SELECT i.*,s.SECTION_ID FROM imported_adviser_assignments i JOIN sections s ON s.GRADE=i.GRADE AND s.STRAND=i.STRAND AND s.SECTION=i.SECTION AND s.IS_ACTIVE=1 WHERE i.SCHOOL_YEAR=(SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1)');
    foreach ($records as $record) {
        if (adviserAssignmentNamesMatch($teacherName, $record['ADVISER_NAME'])) {
            $matches[(int)$record['SECTION_ID']] = true;
        }
    }
    if (count($matches)>1) throw new RuntimeException('Imported adviser name matches several sections. Choose one advisory section explicitly.');
    if (!$matches) return 0;
    setTeacherAdvisory($conn, $teacherId, (int)array_key_first($matches));
    return 1;
}
