<?php
require_once __DIR__ . '/school-year-schema.php';

/** Year-specific advisory ownership is independent of subject teaching. */
function ensureTeacherAccessSchema(mysqli $conn): void
{
    static $ready = false;
    if ($ready) return;
    // Access setup must not execute rollover cleanup or touch attendance records.
    $conn->query('CREATE TABLE IF NOT EXISTS school_year_settings (ID TINYINT UNSIGNED NOT NULL PRIMARY KEY, ACTIVE_SCHOOL_YEAR VARCHAR(20) NOT NULL)');
    $start = (int)date('Y') - ((int)date('n') < 6 ? 1 : 0);
    $initialYear = $start . '-' . ($start + 1);
    $seed = $conn->prepare('INSERT IGNORE INTO school_year_settings (ID,ACTIVE_SCHOOL_YEAR) VALUES (1,?)');
    $seed->bind_param('s', $initialYear); $seed->execute();
    $year = $conn->real_escape_string(activeTeacherSchoolYear($conn));
    if (!$conn->query("SHOW COLUMNS FROM teacher_assignments LIKE 'SCHOOL_YEAR'")->num_rows) {
        $conn->query("ALTER TABLE teacher_assignments ADD SCHOOL_YEAR VARCHAR(20) NOT NULL DEFAULT '$year', DROP PRIMARY KEY, ADD PRIMARY KEY (TEACHER_ID,SCHOOL_YEAR,GRADE,STRAND,SECTION)");
    }
    $yearColumn = $conn->query("SHOW COLUMNS FROM teacher_assignments LIKE 'SCHOOL_YEAR'")->fetch_assoc();
    if ($yearColumn['Default'] !== null) $conn->query('ALTER TABLE teacher_assignments ALTER SCHOOL_YEAR DROP DEFAULT');
    if (!$conn->query("SHOW COLUMNS FROM teachers LIKE 'MUST_CHANGE_PASSWORD'")->num_rows) {
        $conn->query('ALTER TABLE teachers ADD MUST_CHANGE_PASSWORD TINYINT(1) NOT NULL DEFAULT 0');
    }
    $conn->query("CREATE TABLE IF NOT EXISTS teacher_advisory_assignments (
        ID INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        TEACHER_ID INT NOT NULL, SECTION_ID INT NOT NULL, SCHOOL_YEAR VARCHAR(20) NOT NULL,
        RELEASED_AT DATETIME NULL, CREATED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ACTIVE_TEACHER_ID INT GENERATED ALWAYS AS (IF(RELEASED_AT IS NULL,TEACHER_ID,NULL)) STORED,
        ACTIVE_SECTION_ID INT GENERATED ALWAYS AS (IF(RELEASED_AT IS NULL,SECTION_ID,NULL)) STORED,
        UNIQUE KEY uq_adviser_year (SCHOOL_YEAR,ACTIVE_TEACHER_ID),
        UNIQUE KEY uq_section_year (SCHOOL_YEAR,ACTIVE_SECTION_ID),
        FOREIGN KEY (TEACHER_ID) REFERENCES teachers(TEACHER_ID),
        FOREIGN KEY (SECTION_ID) REFERENCES sections(SECTION_ID)
    ) ENGINE=InnoDB");
    if (!$conn->query("SHOW FULL TABLES LIKE 'current_teacher_subject_assignments'")->num_rows) {
        $conn->query("CREATE VIEW current_teacher_subject_assignments AS SELECT ta.* FROM teacher_assignments ta JOIN school_year_settings sy ON sy.ID=1 AND sy.ACTIVE_SCHOOL_YEAR=ta.SCHOOL_YEAR JOIN teachers t ON t.TEACHER_ID=ta.TEACHER_ID AND t.TEACHER_STATUS='Active'");
    }
    if (!$conn->query("SHOW FULL TABLES LIKE 'current_teacher_advisory_assignments'")->num_rows) {
        installTeacherAdvisoryView($conn);
    }
    $ready = true;
}

/** Explicit migration also replaces the legacy view that joined teaching subjects. */
function installTeacherAdvisoryView(mysqli $conn): void
{
    $conn->query("CREATE OR REPLACE VIEW current_teacher_advisory_assignments AS
        SELECT a.TEACHER_ID,a.SCHOOL_YEAR,s.SECTION_ID,s.GRADE,s.STRAND,s.SECTION
        FROM teacher_advisory_assignments a JOIN sections s ON s.SECTION_ID=a.SECTION_ID AND s.IS_ACTIVE=1
        JOIN school_year_settings sy ON sy.ID=1 AND sy.ACTIVE_SCHOOL_YEAR=a.SCHOOL_YEAR
        JOIN teachers t ON t.TEACHER_ID=a.TEACHER_ID AND t.TEACHER_STATUS='Active'
        WHERE a.RELEASED_AT IS NULL");
}

function activeTeacherSchoolYear(mysqli $conn): string
{
    return (string) $conn->query('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1')->fetch_assoc()['ACTIVE_SCHOOL_YEAR'];
}

function teacherAdvisoryAssignments(mysqli $conn, int $teacherId): array
{
    $stmt = $conn->prepare('SELECT * FROM current_teacher_advisory_assignments WHERE TEACHER_ID=?');
    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Caller owns the transaction. Unique keys also protect concurrent writes. */
function setTeacherAdvisory(mysqli $conn, int $teacherId, int $sectionId): void
{
    if ($sectionId < 1) throw new RuntimeException('Choose one active advisory section. Subjects are optional and do not establish advisory ownership.');
    $year = (string)$conn->query('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1 FOR UPDATE')->fetch_assoc()['ACTIVE_SCHOOL_YEAR'];
    $teacher = $conn->prepare("SELECT TEACHER_ID FROM teachers WHERE TEACHER_ID=? AND TEACHER_STATUS='Active' FOR UPDATE");
    $teacher->bind_param('i', $teacherId); $teacher->execute();
    if (!$teacher->get_result()->fetch_assoc()) throw new RuntimeException('Only active teachers can receive an advisory class.');
    $current = teacherAdvisoryAssignments($conn, $teacherId);
    if ((int)($current[0]['SECTION_ID'] ?? 0) === $sectionId) {
        syncTeacherAdvisoryProfile($conn, $teacherId);
        return;
    }
    if ($sectionId) {
        $section = $conn->prepare('SELECT SECTION_ID FROM sections WHERE SECTION_ID=? AND IS_ACTIVE=1 FOR UPDATE');
        $section->bind_param('i', $sectionId); $section->execute();
        if (!$section->get_result()->fetch_assoc()) throw new RuntimeException('Choose an existing active section.');
        $owner = $conn->prepare("SELECT t.TEACHER_NAME FROM teacher_advisory_assignments a JOIN teachers t ON t.TEACHER_ID=a.TEACHER_ID AND t.TEACHER_STATUS='Active'
            WHERE a.SECTION_ID=? AND a.SCHOOL_YEAR=? AND a.RELEASED_AT IS NULL AND a.TEACHER_ID<>? LIMIT 1");
        $owner->bind_param('isi',$sectionId,$year,$teacherId); $owner->execute();
        if ($owner->get_result()->fetch_assoc()) throw new RuntimeException('That advisory section already has an active adviser for this school year. Choose another section.');
    }
    $release = $conn->prepare('UPDATE teacher_advisory_assignments SET RELEASED_AT=NOW() WHERE TEACHER_ID=? AND SCHOOL_YEAR=? AND RELEASED_AT IS NULL');
    $release->bind_param('is', $teacherId, $year); $release->execute();
    if ($sectionId) {
        // A record archived through another maintenance path must not reserve a class.
        $cleanup = $conn->prepare("UPDATE teacher_advisory_assignments a JOIN teachers t ON t.TEACHER_ID=a.TEACHER_ID SET a.RELEASED_AT=NOW() WHERE a.SECTION_ID=? AND a.SCHOOL_YEAR=? AND a.RELEASED_AT IS NULL AND t.TEACHER_STATUS<>'Active'");
        $cleanup->bind_param('is', $sectionId, $year); $cleanup->execute();
        $insert = $conn->prepare('INSERT INTO teacher_advisory_assignments (TEACHER_ID,SECTION_ID,SCHOOL_YEAR) VALUES (?,?,?)');
        $insert->bind_param('iis', $teacherId, $sectionId, $year); $insert->execute();
        syncTeacherAdvisoryProfile($conn, $teacherId);
    }
}

/** Retains compatibility with older administrative profile displays. */
function syncTeacherAdvisoryProfile(mysqli $conn, int $teacherId): void
{
    $assignment = teacherAdvisoryAssignments($conn, $teacherId)[0] ?? null;
    if (!$assignment) return;
    $update = $conn->prepare('UPDATE teachers SET GRADE=?,STRAND=?,SECTION=? WHERE TEACHER_ID=?');
    $update->bind_param('issi', $assignment['GRADE'], $assignment['STRAND'], $assignment['SECTION'], $teacherId);
    $update->execute();
}

/** At-most-one is enforced by SQL unique keys; every active account write requires one. */
function requireTeacherAdvisory(mysqli $conn, int $teacherId): void
{
    if (count(teacherAdvisoryAssignments($conn,$teacherId)) !== 1) {
        throw new RuntimeException('An active teacher must have exactly one active advisory section for this school year.');
    }
}

/** Called inside the rollover transaction; teachers stay with their section. */
function carryForwardTeacherAssignments(mysqli $conn, string $fromYear, string $toYear): void
{
    // Keep deliberate target-year assignments, including any manual changes after undo.
    $subjects = $conn->prepare("INSERT INTO teacher_assignments (TEACHER_ID,GRADE,STRAND,SECTION,SUBJECT,SCHOOL_YEAR)
        SELECT ta.TEACHER_ID,ta.GRADE,ta.STRAND,ta.SECTION,ta.SUBJECT,?
        FROM teacher_assignments ta JOIN teachers t ON t.TEACHER_ID=ta.TEACHER_ID AND t.TEACHER_STATUS='Active'
        JOIN sections s ON s.GRADE=ta.GRADE AND s.STRAND=ta.STRAND AND s.SECTION=ta.SECTION AND s.IS_ACTIVE=1
        WHERE ta.SCHOOL_YEAR=? AND NOT EXISTS (
            SELECT 1 FROM teacher_assignments target WHERE target.TEACHER_ID=ta.TEACHER_ID AND target.SCHOOL_YEAR=?
                AND target.GRADE=ta.GRADE AND target.STRAND=ta.STRAND AND target.SECTION=ta.SECTION)");
    $subjects->bind_param('sss', $toYear, $fromYear, $toYear); $subjects->execute();
    $release = $conn->prepare("UPDATE teacher_advisory_assignments a JOIN teachers t ON t.TEACHER_ID=a.TEACHER_ID
        SET a.RELEASED_AT=NOW() WHERE a.SCHOOL_YEAR=? AND a.RELEASED_AT IS NULL AND t.TEACHER_STATUS<>'Active'");
    $release->bind_param('s', $toYear); $release->execute();
    $advisers = $conn->prepare("INSERT INTO teacher_advisory_assignments (TEACHER_ID,SECTION_ID,SCHOOL_YEAR)
        SELECT a.TEACHER_ID,a.SECTION_ID,? FROM teacher_advisory_assignments a
        JOIN teachers t ON t.TEACHER_ID=a.TEACHER_ID AND t.TEACHER_STATUS='Active'
        JOIN sections s ON s.SECTION_ID=a.SECTION_ID AND s.IS_ACTIVE=1
        WHERE a.SCHOOL_YEAR=? AND a.RELEASED_AT IS NULL
          AND NOT EXISTS (SELECT 1 FROM teacher_advisory_assignments target WHERE target.SCHOOL_YEAR=? AND target.TEACHER_ID=a.TEACHER_ID AND target.RELEASED_AT IS NULL)
          AND NOT EXISTS (SELECT 1 FROM teacher_advisory_assignments target WHERE target.SCHOOL_YEAR=? AND target.SECTION_ID=a.SECTION_ID AND target.RELEASED_AT IS NULL)");
    $advisers->bind_param('ssss', $toYear, $fromYear, $toYear, $toYear); $advisers->execute();
}

function teacherAdvisorySelect(mysqli $conn, int $teacherId = 0): void
{
    $selected = teacherAdvisoryAssignments($conn, $teacherId)[0]['SECTION_ID'] ?? 0;
    echo '<label class="block text-sm font-bold text-slate-700">Advisory section (active school year)<select name="advisory_section_id" required class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3"><option value="">Choose one advisory section</option>';
    foreach ($conn->query("SELECT s.* FROM sections s WHERE s.IS_ACTIVE=1 AND NOT EXISTS (
        SELECT 1 FROM current_teacher_advisory_assignments a WHERE a.SECTION_ID=s.SECTION_ID AND a.TEACHER_ID<>".(int)$teacherId.") ORDER BY s.GRADE,s.SORT_ORDER,s.SECTION") as $section) {
        echo '<option value="'.(int)$section['SECTION_ID'].'" '.((int)$selected===(int)$section['SECTION_ID']?'selected':'').'>' . htmlspecialchars('Grade '.$section['GRADE'].' | '.$section['SECTION'].' '.$section['STRAND']) . '</option>';
    }
    echo '</select></label><p class="text-sm text-slate-500">Exactly one adviser per section and school year. Only available sections and your current advisory are listed. Subject teaching assignments grant grading access separately.</p>';
}
