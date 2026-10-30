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

/** Balance regular students by total occupancy, respecting every section's capacity.
 * Honor qualification is handled explicitly by the promotion planner.
 */
function assignStudentsToSections(array $studentIds, array $sections, ?int $default = null): array
{
    $groups = [];
    foreach ($sections as $section) {
        if (!empty($section['IS_HONOR'])) throw new InvalidArgumentException('Honor students must be qualified before sectioning.');
        $groups[] = ['section'=>$section, 'students'=>[]];
    }
    foreach ($studentIds as $studentId) {
        $selected = null; $smallest = PHP_INT_MAX;
        foreach ($groups as $index=>$group) {
            $population = (int)($group['section']['EXISTING_COUNT'] ?? 0) + count($group['students']);
            if ($population < sectionCapacity($group['section'], $default) && $population < $smallest) {
                $selected = $index; $smallest = $population;
            }
        }
        if ($selected === null) throw new RuntimeException('Insufficient regular-section capacity.');
        $groups[$selected]['students'][] = $studentId;
    }
    return $groups;
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

/**
 * Split an ordered list of student IDs across weighted targets.
 * Uses the largest-remainder method so the chunks always add up to the total.
 *
 * @param array<int,string|int> $studentIds Already ordered.
 * @param array<int,int>        $percents   One weight per target (0 skips a target).
 * @return array<int,array<int,string|int>> One chunk per weight, in the same order.
 */
function splitStudentsByWeights(array $studentIds, array $percents): array
{
    $percents = array_values($percents);
    $count = count($percents);
    $total = count($studentIds);
    $sum = array_sum($percents);
    if ($count === 0 || $total === 0 || $sum <= 0) {
        return array_fill(0, $count, []);
    }

    $floors = [];
    $remainders = [];
    foreach ($percents as $index => $percent) {
        $exact = $total * $percent / $sum;
        $floors[$index] = (int) floor($exact);
        $remainders[$index] = $exact - $floors[$index];
    }

    $assigned = array_sum($floors);
    arsort($remainders);
    foreach (array_keys($remainders) as $index) {
        if ($assigned >= $total) break;
        $floors[$index]++;
        $assigned++;
    }

    $groups = [];
    $offset = 0;
    foreach ($floors as $size) {
        $groups[] = array_slice($studentIds, $offset, $size);
        $offset += $size;
    }
    return $groups;
}

/**
 * Validate posted rollover plans into flat rows.
 * A source grade/strand may target several strands (Grade 10 -> Grade 11 split),
 * so each row is a (source, target, percent) tuple.
 *
 * @param callable(string):never $fail
 * @return array<int,array{FROM_GRADE:int,FROM_STRAND:string,TO_GRADE:int,TO_STRAND:string,SPLIT_PERCENT:int}>
 */
function normalisePromotionPlans(array $postedPlans, callable $fail): array
{
    $rows = [];
    $seenSources = [];
    foreach ($postedPlans as $posted) {
        if (!is_array($posted)) $fail('Choose valid promotion targets.');
        $fromGrade = (int) ($posted['from_grade'] ?? 0);
        $fromStrand = trim((string) ($posted['from_strand'] ?? ''));
        if ($fromGrade < 7 || $fromGrade > 11) {
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
        if (!is_array($targets) || !$targets) {
            $fail('Choose a target for Grade ' . $toGrade . '.');
        }

        $percentTotal = 0;
        $usedStrands = [];
        foreach ($targets as $target) {
            if (!is_array($target)) $fail('Choose valid strand targets.');
            $toStrand = trim((string) ($target['strand'] ?? ''));
            $percent = (int) ($target['percent'] ?? 100);
            if ($fromGrade === 11 && $toStrand !== $fromStrand) $fail('Grade 11 students must keep their strand in Grade 12.');
            if ($targetStrands) {
                if (!in_array($toStrand, $targetStrands, true)) {
                    $fail('Choose a valid target strand for Grade ' . $toGrade . '.');
                }
            } else {
                $toStrand = '';
            }
            if (isset($usedStrands[$toStrand])) {
                $fail('Each target strand may only be chosen once.');
            }
            $usedStrands[$toStrand] = true;
            if ($percent < 0 || $percent > 100) {
                $fail('Target percentages must be between 0 and 100.');
            }
            $percentTotal += $percent;
            if ($percent === 0) continue;
            $rows[] = [
                'FROM_GRADE' => $fromGrade,
                'FROM_STRAND' => $fromStrand,
                'TO_GRADE' => $toGrade,
                'TO_STRAND' => $toStrand,
                'SPLIT_PERCENT' => $percent,
            ];
        }

        if ($percentTotal !== 100) {
            $fail('Target percentages for Grade ' . $fromGrade . ' must add up to 100%.');
        }
    }
    return $rows;
}
