<?php
require_once __DIR__ . '/student-placement-options.php';

/** Keeps the administrator-managed teacher-section catalog grade-aware, sized, and ordered. */
function ensureSectionCatalogColumns(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS sections (SECTION_ID INT AUTO_INCREMENT PRIMARY KEY, SECTION VARCHAR(80) NOT NULL UNIQUE)");
    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM sections');
    while ($result && ($column = $result->fetch_assoc())) $columns[strtoupper($column['Field'])] = true;
    if (!isset($columns['GRADE'])) $conn->query('ALTER TABLE sections ADD COLUMN GRADE TINYINT UNSIGNED NULL AFTER SECTION');
    if (!isset($columns['STRAND'])) $conn->query("ALTER TABLE sections ADD COLUMN STRAND VARCHAR(20) NOT NULL DEFAULT '' AFTER GRADE");
    if (!isset($columns['BASE_SECTION'])) $conn->query("ALTER TABLE sections ADD COLUMN BASE_SECTION VARCHAR(80) NOT NULL DEFAULT '' AFTER STRAND");
    if (!isset($columns['IS_ACTIVE'])) $conn->query('ALTER TABLE sections ADD COLUMN IS_ACTIVE TINYINT(1) NOT NULL DEFAULT 1 AFTER BASE_SECTION');
    if (!isset($columns['CAPACITY'])) $conn->query('ALTER TABLE sections ADD COLUMN CAPACITY SMALLINT UNSIGNED NOT NULL DEFAULT 40 AFTER IS_ACTIVE');
    if (!isset($columns['SORT_ORDER'])) {
        $conn->query('ALTER TABLE sections ADD COLUMN SORT_ORDER INT NOT NULL DEFAULT 0 AFTER CAPACITY');
        // Give legacy catalog rows a stable order instead of relying on insertion order.
        $conn->query('UPDATE sections SET SORT_ORDER=SECTION_ID WHERE SORT_ORDER=0');
    }
    // Configurable fallback used by the auto-sectioning chunker.
    $conn->query('CREATE TABLE IF NOT EXISTS section_settings (ID TINYINT UNSIGNED NOT NULL PRIMARY KEY, DEFAULT_CAPACITY SMALLINT UNSIGNED NOT NULL DEFAULT 40)');
    $conn->query('INSERT IGNORE INTO section_settings (ID, DEFAULT_CAPACITY) VALUES (1, 40)');
    ensureSectionColumnWidths($conn);
    reconcileBaseSectionOrder($conn);
}

/**
 * Generated overflow names such as "Grade 11 - Academic - Section 3" exceed the
 * legacy VARCHAR(20) columns used by students and teachers.
 */
function ensureSectionColumnWidths(mysqli $conn): void
{
    $targets = [
        'students' => 'NULL DEFAULT NULL',
        'teachers' => "NOT NULL DEFAULT ''",
    ];
    foreach ($targets as $table => $attributes) {
        $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'SECTION'");
        $column = $result ? $result->fetch_assoc() : null;
        if (!$column) continue;
        if (preg_match('/^varchar\((\d+)\)$/i', (string) $column['Type'], $match) && (int) $match[1] >= 80) {
            continue;
        }
        $conn->query("ALTER TABLE `{$table}` MODIFY COLUMN SECTION VARCHAR(80) {$attributes}");
    }
}

/**
 * Keeps the pre-named catalog sections in their intended fill order.
 * Grade 8 is deliberately Amethyst -> Emerald -> Jade so the first promoted
 * chunk lands in Amethyst, matching the documented rollover behavior.
 * Auto-created sections (BASE_SECTION='') always sort after named ones.
 */
function reconcileBaseSectionOrder(mysqli $conn): void
{
    $update = $conn->prepare(
        "UPDATE sections SET SORT_ORDER=?
          WHERE SECTION=? AND GRADE=? AND STRAND=? AND BASE_SECTION<>'' AND SORT_ORDER<>?",
    );
    if (!$update) return;
    $order = 0;
    $grades = [7 => [''], 8 => [''], 9 => [''], 10 => [''], 11 => ['Academic', 'Techpro'], 12 => ['Academic', 'Techpro']];
    foreach ($grades as $grade => $strands) {
        foreach ($strands as $strand) {
            foreach (studentSectionsForGrade($grade, $strand) as $name) {
                $order++;
                $update->bind_param('isisi', $order, $name, $grade, $strand, $order);
                $update->execute();
            }
        }
    }
    // Generated/overflow sections are never interleaved with the named catalog.
    $conn->query("UPDATE sections SET SORT_ORDER=1000+SECTION_ID WHERE BASE_SECTION=''");
}

function seedDefaultTeacherSections(mysqli $conn): void
{
    $defaults = [7 => ['' => studentSectionsForGrade(7)], 8 => ['' => studentSectionsForGrade(8)], 9 => ['' => studentSectionsForGrade(9)], 10 => ['' => studentSectionsForGrade(10)], 11 => ['Academic' => studentSectionsForGrade(11, 'Academic'), 'Techpro' => studentSectionsForGrade(11, 'Techpro')], 12 => ['Academic' => studentSectionsForGrade(12, 'Academic'), 'Techpro' => studentSectionsForGrade(12, 'Techpro')]];
    $hasBase = $conn->prepare('SELECT 1 FROM sections WHERE BASE_SECTION=? LIMIT 1');
    $upsert = $conn->prepare("INSERT INTO sections (SECTION,GRADE,STRAND,BASE_SECTION,IS_ACTIVE,CAPACITY,SORT_ORDER) VALUES (?,?,?,?,1,?,?) ON DUPLICATE KEY UPDATE GRADE=COALESCE(GRADE,VALUES(GRADE)), STRAND=IF(STRAND='',VALUES(STRAND),STRAND), BASE_SECTION=IF(BASE_SECTION='',VALUES(BASE_SECTION),BASE_SECTION)");
    $capacitySetting = $conn->query('SELECT DEFAULT_CAPACITY FROM section_settings WHERE ID=1 LIMIT 1');
    $capacity = max(1, (int) (($capacitySetting ? $capacitySetting->fetch_assoc() : [])['DEFAULT_CAPACITY'] ?? 40));
    $order = 0;
    foreach ($defaults as $grade => $strands) foreach ($strands as $strand => $sections) foreach ($sections as $section) {
        $order++;
        $hasBase->bind_param('s', $section); $hasBase->execute();
        if ($hasBase->get_result()->num_rows) continue;
        $upsert->bind_param('sissii', $section, $grade, $strand, $section, $capacity, $order);
        $upsert->execute();
    }
}

function teacherSectionPlacementOptions(mysqli $conn): array
{
    $options = [7 => [], 8 => [], 9 => [], 10 => [], 11 => ['Academic' => [], 'Techpro' => []], 12 => ['Academic' => [], 'Techpro' => []]];
    $result = $conn->query('SELECT SECTION, GRADE, STRAND FROM sections WHERE IS_ACTIVE=1 AND GRADE BETWEEN 7 AND 12 ORDER BY SORT_ORDER, SECTION_ID');
    while ($result && ($row = $result->fetch_assoc())) {
        $grade = (int)$row['GRADE']; $strand = $row['STRAND']; $section = $row['SECTION'];
        if ($grade >= 11 && in_array($strand, studentStrandsForGrade($grade), true)) {
            $options[$grade][$strand][] = $section;
        } elseif ($grade <= 10 && $strand === '') {
            $options[$grade][] = $section;
        }
    }
    return $options;
}

/** Approved sections for student placement, including sections created by auto-sectioning. */
function studentSectionPlacementOptions(mysqli $conn): array
{
    $options = [7 => [], 8 => [], 9 => [], 10 => [], 11 => ['Academic' => [], 'Techpro' => []], 12 => ['Academic' => [], 'Techpro' => []]];
    $result = $conn->query('SELECT SECTION, GRADE, STRAND FROM sections WHERE IS_ACTIVE=1 AND GRADE BETWEEN 7 AND 12 ORDER BY SORT_ORDER, SECTION_ID');
    while ($result && ($row = $result->fetch_assoc())) {
        $grade = (int)$row['GRADE']; $strand = $row['STRAND']; $section = $row['SECTION'];
        if ($grade >= 11 && in_array($strand, studentStrandsForGrade($grade), true)) {
            $options[$grade][$strand][] = $section;
        } elseif ($grade <= 10 && $strand === '') {
            $options[$grade][] = $section;
        }
    }
    return $options;
}

function isApprovedTeacherSection(mysqli $conn, int $grade, string $strand, string $section): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM sections WHERE SECTION=? AND GRADE=? AND STRAND=? AND IS_ACTIVE=1 LIMIT 1');
    $stmt->bind_param('sis', $section, $grade, $strand); $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function isApprovedStudentSection(mysqli $conn, int $grade, string $strand, string $section): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM sections WHERE SECTION=? AND GRADE=? AND STRAND=? AND IS_ACTIVE=1 LIMIT 1');
    $stmt->bind_param('sis', $section, $grade, $strand); $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}
