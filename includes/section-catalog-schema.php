<?php
/** Keeps the administrator-managed teacher-section catalog grade-aware. */
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
}

function seedDefaultTeacherSections(mysqli $conn): void
{
    $defaults = [7 => ['' => studentSectionsForGrade(7)], 8 => ['' => studentSectionsForGrade(8)], 9 => ['' => studentSectionsForGrade(9)], 10 => ['' => studentSectionsForGrade(10)], 11 => ['Academic' => studentSectionsForGrade(11, 'Academic'), 'Techpro' => studentSectionsForGrade(11, 'Techpro')], 12 => ['Academic' => studentSectionsForGrade(12, 'Academic'), 'Techpro' => studentSectionsForGrade(12, 'Techpro')]];
    $hasBase = $conn->prepare('SELECT 1 FROM sections WHERE BASE_SECTION=? LIMIT 1');
    $upsert = $conn->prepare("INSERT INTO sections (SECTION,GRADE,STRAND,BASE_SECTION,IS_ACTIVE) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE GRADE=COALESCE(GRADE,VALUES(GRADE)), STRAND=IF(STRAND='',VALUES(STRAND),STRAND), BASE_SECTION=IF(BASE_SECTION='',VALUES(BASE_SECTION),BASE_SECTION)");
    foreach ($defaults as $grade => $strands) foreach ($strands as $strand => $sections) foreach ($sections as $section) {
        $hasBase->bind_param('s', $section); $hasBase->execute();
        if ($hasBase->get_result()->num_rows) continue;
        $upsert->bind_param('siss', $section, $grade, $strand, $section); $upsert->execute();
    }
}

function teacherSectionPlacementOptions(mysqli $conn): array
{
    $options = [7 => [], 8 => [], 9 => [], 10 => [], 11 => ['Academic' => [], 'Techpro' => []], 12 => ['Academic' => [], 'Techpro' => []]];
    $result = $conn->query('SELECT SECTION, GRADE, STRAND FROM sections WHERE IS_ACTIVE=1 AND GRADE BETWEEN 7 AND 12');
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
