<?php
/** Approved sections and senior-high strands for student profiles. */
function studentStrandsForGrade(int $grade): array
{
    return in_array($grade, [11, 12], true) ? ['Techpro', 'Academic'] : [];
}

function studentSectionsForGrade(int $grade, string $strand = ''): array
{
    $juniorHigh = [
        7 => ['Camia', 'Orchids', 'Rose'],
        8 => ['Jade', 'Amethyst', 'Emerald'],
        9 => ['Peace', 'Faith', 'Hope'],
        10 => ['Aristotle', 'Newton', 'Einsten'],
    ];
    $seniorHigh = [
        11 => ['Academic' => ['Kepler', 'Smith'], 'Techpro' => ['Carver', 'Prescott']],
        12 => ['Academic' => ['Pythagoras'], 'Techpro' => ['Hilton', 'Burbank']],
    ];
    if (isset($juniorHigh[$grade])) return $juniorHigh[$grade];
    return $seniorHigh[$grade][$strand] ?? [];
}

function isValidStudentPlacement(int $grade, string $strand, string $section): bool
{
    $strands = studentStrandsForGrade($grade);
    if ($strands) {
        if (!in_array($strand, $strands, true)) return false;
    } elseif ($strand !== '') return false;
    return in_array($section, studentSectionsForGrade($grade, $strand), true);
}

function allStudentSections(): array
{
    return array_merge(
        studentSectionsForGrade(7), studentSectionsForGrade(8),
        studentSectionsForGrade(9), studentSectionsForGrade(10),
        studentSectionsForGrade(11, 'Academic'), studentSectionsForGrade(11, 'Techpro'),
        studentSectionsForGrade(12, 'Academic'), studentSectionsForGrade(12, 'Techpro'),
    );
}
