<?php
/** Shared capacity-aware sectioning helpers used by promotion, preview, and reporting. */
require_once __DIR__ . '/student-placement-options.php';

const DEFAULT_SECTION_CAPACITY = 40;
const MAX_SECTION_CAPACITY = 50;

/** Administrator-configured fallback capacity (section_settings.ID=1). */
function defaultSectionCapacity(mysqli $conn): int
{
    $result = $conn->query('SELECT DEFAULT_CAPACITY FROM section_settings WHERE ID=1 LIMIT 1');
    $row = $result ? $result->fetch_assoc() : null;
    return max(1, (int) ($row['DEFAULT_CAPACITY'] ?? DEFAULT_SECTION_CAPACITY));
}

/** A section's configured capacity, falling back to the configured default. */
function sectionCapacity(array $section, ?int $default = null): int
{
    $capacity = (int) ($section['CAPACITY'] ?? 0);
    return max(1, $capacity > 0 ? $capacity : ($default ?? DEFAULT_SECTION_CAPACITY));
}

/** Active catalog sections for a target grade/strand, in deterministic fill order. */
function orderedTargetSections(mysqli $conn, int $grade, string $strand): array
{
    $stmt = $conn->prepare(
        'SELECT SECTION_ID, SECTION, GRADE, STRAND, CAPACITY, SORT_ORDER
           FROM sections
          WHERE IS_ACTIVE=1 AND GRADE=? AND STRAND=?
          ORDER BY SORT_ORDER, SECTION_ID',
    );
    $stmt->bind_param('is', $grade, $strand);
    $stmt->execute();
    $result = $stmt->get_result();
    $sections = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $sections[] = $row;
    }
    return $sections;
}

function generatedSectionName(int $grade, string $strand, int $index): string
{
    return 'Grade ' . $grade . ($strand !== '' ? ' - ' . $strand : '') . ' - Section ' . $index;
}

/**
 * Divides an array into a specific number of balanced chunks.
 * When the split is uneven, the earlier chunks receive one extra item.
 *
 * This is the canonical distribution used by regular sectioning. It never
 * fills one section to capacity before touching the next: 70 students across
 * two sections become 35/35, not 40/30.
 *
 * @param array<int,mixed> $items
 * @return array<int,array<int,mixed>>
 */
function chunkEvenly(array $items, int $numChunks): array
{
    if ($numChunks <= 0) return [];
    if (empty($items)) return array_fill(0, $numChunks, []);

    $result = [];
    $total = count($items);
    $baseSize = intdiv($total, $numChunks);
    $remainder = $total % $numChunks;
    $offset = 0;
    for ($i = 0; $i < $numChunks; $i++) {
        $size = $baseSize + ($i < $remainder ? 1 : 0);
        $result[] = array_slice($items, $offset, $size);
        $offset += $size;
    }
    return $result;
}

/**
 * Balanced regular-section distribution.
 *
 * Students are split with chunkEvenly() across every available regular
 * section. A section whose configured capacity is smaller than its even share
 * is filled to its capacity and its surplus is redistributed evenly among the
 * remaining sections. This preserves the equal-load policy while still
 * respecting administrator-configured capacities.
 *
 * Existing occupants only reduce the free seats; they never cause the next
 * incoming student to be dumped into a single section.
 *
 * @param array<int,string|int> $studentIds Already ordered.
 * @param array<int,array>      $sections   Regular sections only.
 * @return array<int,array{section:array,students:array}>
 */
function assignStudentsToSections(array $studentIds, array $sections, ?int $default = null): array
{
    $default ??= DEFAULT_SECTION_CAPACITY;
    $groups = [];
    $available = [];
    foreach ($sections as $section) {
        if (!empty($section['IS_HONOR'])) throw new InvalidArgumentException('Honor students must be qualified before sectioning.');
        $groups[] = ['section' => $section, 'students' => []];
        $existing = (int) ($section['EXISTING_COUNT'] ?? 0);
        $available[] = max(0, sectionCapacity($section, $default) - $existing);
    }

    $studentIds = array_values($studentIds);
    $total = count($studentIds);
    if ($total === 0) return $groups;
    if (!$groups) throw new RuntimeException('No regular section is available for the remaining students.');
    if ($total > array_sum($available)) throw new RuntimeException('Insufficient regular-section capacity.');

    // Start from an even split, cap sections that cannot hold their share, and
    // redistribute the surplus evenly until every student has a seat.
    $sizes = array_fill(0, count($groups), 0);
    $open = array_keys($groups);
    $remaining = $total;
    while ($remaining > 0 && $open) {
        $chunks = chunkEvenly(range(1, $remaining), count($open));
        $surplus = 0;
        $stillOpen = [];
        foreach ($open as $position => $index) {
            $share = count($chunks[$position]);
            $free = $available[$index] - $sizes[$index];
            if ($share > $free) {
                $sizes[$index] += $free;
                $surplus += $share - $free;
            } else {
                $sizes[$index] += $share;
                if ($sizes[$index] < $available[$index]) $stillOpen[] = $index;
            }
        }
        if ($surplus === 0) { $remaining = 0; break; }
        $remaining = $surplus;
        $open = $stillOpen;
    }
    if ($remaining > 0) throw new RuntimeException('Insufficient regular-section capacity.');

    $offset = 0;
    foreach ($groups as $index => &$group) {
        $group['students'] = array_slice($studentIds, $offset, $sizes[$index]);
        $offset += $sizes[$index];
    }
    unset($group);
    return $groups;
}

/**
 * Finalized academic sectioning.
 *
 * Section 1 is the honor section. It is deliberately UNCAPPED: every student
 * whose exact GWA is at least 90.00 is placed in the first ordered section,
 * regardless of that section's configured capacity. Section 1 is ordered by
 * highest exact GWA, then LRN, so the roster is deterministic.
 *
 * Every remaining student (GWA below 90.00) is distributed evenly across
 * Sections 2+ with chunkEvenly(), which assignStudentsToSections() applies
 * while still respecting each section's configured capacity and generating
 * overflow sections when the batch is larger than the catalog.
 *
 * A missing GWA is deliberately a regular placement. That is the safe
 * administrative default for a student with no recorded grades; it must not
 * turn an unrecorded GWA into a rollover blocker or an honor award.
 *
 * @param array<int,string|int>        $studentIds     Students to place.
 * @param array<int,array>             $orderedSections Section 1 first, then Sections 2+.
 * @param array<string|int,float|null> $gwaMap         GWA per student id.
 * @return array{honor:array<int,string|int>,regular:array<int,string|int>,groups:array<int,array{section:array,students:array}>}
 */
function assignStudentsByAcademicSections(
    array $studentIds,
    array $orderedSections,
    array $gwaMap,
    int $defaultCapacity = DEFAULT_SECTION_CAPACITY,
): array {
    $orderedSections = array_values($orderedSections);
    if (!$orderedSections) {
        throw new RuntimeException('No section is available for sectioning.');
    }

    // Section 1 is the first ordered section; the rest receive the regulars.
    $honorSection = array_shift($orderedSections);
    $regularSections = $orderedSections;

    $honor = [];
    $regular = [];
    foreach (array_values($studentIds) as $studentId) {
        $gwa = $gwaMap[$studentId] ?? null;
        if ($gwa !== null && (float) $gwa >= 90.00) {
            $honor[] = $studentId;
        } else {
            $regular[] = $studentId;
        }
    }

    // Keep Section 1 deterministic: highest exact GWA first, then LRN.
    usort($honor, static function ($left, $right) use ($gwaMap): int {
        $comparison = ((float) $gwaMap[$right] <=> (float) $gwaMap[$left]);
        return $comparison ?: strcmp((string) $left, (string) $right);
    });

    $honorSection['IS_HONOR'] = true;
    $groups = [['section' => $honorSection, 'students' => $honor]];
    if ($regularSections) {
        $groups = array_merge(
            $groups,
            assignStudentsToSections($regular, $regularSections, $defaultCapacity),
        );
    } elseif ($regular) {
        throw new RuntimeException('No regular section is available for the remaining students.');
    }

    return ['honor' => $honor, 'regular' => $regular, 'groups' => $groups];
}

/**
 * Backwards-compatible wrapper. Older callers pass Section 1 separately from
 * the regular sections; the canonical entry point is
 * assignStudentsByAcademicSections().
 *
 * @param array<int,string|int> $studentIds
 * @param array<string|int,float|null> $gwaByStudent
 * @param array $honorSection Section 1
 * @param array<int,array> $regularSections Sections 2 and above
 * @return array{honor:array<int,string|int>,regular:array<int,string|int>,groups:array<int,array{section:array,students:array}>}
 */
function sectionOneHonorAndBalancedRegular(
    array $studentIds,
    array $gwaByStudent,
    array $honorSection,
    array $regularSections,
    ?int $default = null,
): array {
    return assignStudentsByAcademicSections(
        $studentIds,
        array_merge([$honorSection], array_values($regularSections)),
        $gwaByStudent,
        $default ?? DEFAULT_SECTION_CAPACITY,
    );
}

/**
 * Plans a batch without touching the database: appends virtual sections when the
 * existing catalog cannot hold the whole batch. Used by the inline rollover projection.
 */
function sectionPlanForBatch(array $studentIds, array $sections, int $grade, string $strand, ?int $default = null): array
{
    $default ??= DEFAULT_SECTION_CAPACITY;
    $capacity = 0;
    foreach ($sections as $section) $capacity += sectionCapacity($section, $default);
    $index = count($sections);
    $total = count($studentIds);
    while ($capacity < $total) {
        $index++;
        $sections[] = [
            'SECTION_ID' => 0,
            'SECTION' => generatedSectionName($grade, $strand, $index),
            'GRADE' => $grade,
            'STRAND' => $strand,
            'CAPACITY' => $default,
            'SORT_ORDER' => 0,
            'VIRTUAL' => true,
        ];
        $capacity += $default;
    }
    return assignStudentsToSections($studentIds, $sections, $default);
}

/**
 * Creates additional catalog sections when the batch is larger than the available
 * capacity. Mutates $sections so the caller can immediately chunk the students.
 *
 * @return array<int,array> Rows for sections created by this call (for undo cleanup).
 */
function ensureSectionCapacity(mysqli $conn, int $grade, string $strand, int $neededStudents, array &$sections, ?int $default = null): array
{
    $default ??= DEFAULT_SECTION_CAPACITY;
    $available = 0;
    foreach ($sections as $section) $available += sectionCapacity($section, $default);
    if ($available >= $neededStudents) return [];

    $index = count($sections);
    $sortOrder = 0;
    foreach ($sections as $section) $sortOrder = max($sortOrder, (int) ($section['SORT_ORDER'] ?? 0));

    $insert = $conn->prepare(
        "INSERT INTO sections (SECTION,GRADE,STRAND,BASE_SECTION,IS_ACTIVE,CAPACITY,SORT_ORDER)
         VALUES (?,?,?,'',1,?,?)
         ON DUPLICATE KEY UPDATE GRADE=VALUES(GRADE), STRAND=VALUES(STRAND), IS_ACTIVE=1",
    );
    $lookup = $conn->prepare(
        'SELECT SECTION_ID, SECTION, GRADE, STRAND, CAPACITY, SORT_ORDER FROM sections WHERE SECTION=? LIMIT 1',
    );

    $createdSections = [];
    while ($available < $neededStudents) {
        $index++;
        $name = generatedSectionName($grade, $strand, $index);
        $capacity = $default;
        $sortOrder++;

        // Check first so undo can tell a brand-new section from a reactivated one.
        $lookup->bind_param('s', $name);
        $lookup->execute();
        $existing = $lookup->get_result()->fetch_assoc();

        $insert->bind_param('sisii', $name, $grade, $strand, $capacity, $sortOrder);
        $insert->execute();

        if ($existing) {
            $sections[] = $existing;
            $available += sectionCapacity($existing, $default);
            continue;
        }

        $lookup->bind_param('s', $name);
        $lookup->execute();
        $created = $lookup->get_result()->fetch_assoc();
        if (!$created) {
            $available += $capacity;
            continue;
        }
        $createdSections[] = $created;
        $sections[] = $created;
        $available += sectionCapacity($created, $default);
    }
    return $createdSections;
}

/** Validate one deterministic target for every non-Grade-10 promotion source.
 * Grade 10 is routed separately by an administrator-reviewed student choice.
 *
 * @param callable(string):never $fail
 * @return array<int,array{FROM_GRADE:int,FROM_STRAND:string,TO_GRADE:int,TO_STRAND:string}>
 */
function normalisePromotionPlans(array $postedPlans, callable $fail): array
{
    $rows = [];
    $seenSources = [];
    foreach ($postedPlans as $posted) {
        if (!is_array($posted)) $fail('Choose valid promotion targets.');
        $fromGrade = (int) ($posted['from_grade'] ?? 0);
        $fromStrand = trim((string) ($posted['from_strand'] ?? ''));
        if (!in_array($fromGrade, [7, 8, 9, 11], true)) {
            $fail('Choose a valid source grade for every promotion.');
        }

        $sourceStrands = studentStrandsForGrade($fromGrade);
        if ($sourceStrands) {
            if (!in_array($fromStrand, $sourceStrands, true)) {
                $fail('Choose a valid strand for Grade ' . $fromGrade . '.');
            }
        } elseif ($fromStrand !== '') {
            $fail('Grade ' . $fromGrade . ' does not use a strand.');
        }

        $sourceKey = $fromGrade . '|' . $fromStrand;
        if (isset($seenSources[$sourceKey])) {
            $fail('Each source grade may only be promoted once.');
        }
        $seenSources[$sourceKey] = true;

        $toGrade = $fromGrade + 1;
        $targetStrands = studentStrandsForGrade($toGrade);
        $targets = $posted['targets'] ?? [];
        if (!is_array($targets) || count($targets) !== 1 || !is_array($targets[0] ?? null)) {
            $fail('Choose one target for Grade ' . $toGrade . '.');
        }
        $toStrand = trim((string) ($targets[0]['strand'] ?? ''));
        if ($fromGrade === 11 && $toStrand !== $fromStrand) $fail('Grade 11 students must keep their strand in Grade 12.');
        if ($targetStrands) {
            if (!in_array($toStrand, $targetStrands, true)) {
                $fail('Choose a valid target strand for Grade ' . $toGrade . '.');
            }
        } else {
            $toStrand = '';
        }
        $rows[] = [
            'FROM_GRADE' => $fromGrade,
            'FROM_STRAND' => $fromStrand,
            'TO_GRADE' => $toGrade,
            'TO_STRAND' => $toStrand,
        ];
    }
    return $rows;
}
