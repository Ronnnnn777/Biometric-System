# Promotion and honor-section audit

## Findings

The original `assignStudentsToSections()` filled the first section with the first students in a section/name-sorted list. It never read grades. Spare honor seats could therefore go to nonqualifiers, and senior-high first sections were implicitly labeled honor sections too.

Promotion counted stored Failed results and treated absent results as zero failures. It did not establish a complete subject list or require four completed quarters. The preview used placeholder student IDs and enrollment counts, including students who would actually be retained or need summer classes.

The original regular distribution used balanced chunks but did not check each section's capacity or retained occupants. Overflow planning counted honor seats as available regular seats. Code defaults were 50 although the live school setting and section capacities are 40.

The runner selected each source grade again after changing students' grades. This allowed a newly promoted student to enter the next source batch, and newly promoted Grade 12 students could enter graduation. Old attendance snapshots were backfilled without an upper year bound. Repeated requests had no reviewed-year fingerprint. `INSERT IGNORE` yearly snapshots could not accurately undo a later rerun after a source placement had changed.

## Corrections and affected files

- `includes/sectioning.php`: regular balancing selects the least-populated section with a free seat; configured individual capacities and staying students count. The fallback is 40. Grade 11 cannot switch strands while entering Grade 12.
- `includes/promotion-planner.php`: one read-only planner for preview and execution, complete annual subject validation, exact honor threshold/ranking, deterministic ties, reserved seats, regular overflow projection, and stale-preview fingerprints.
- `includes/promotion-runner.php`: locks and freezes source students before any move, creates only reviewed regular sections, saves decisions and immutable before/after states, and advances the year atomically. Existing teacher adviser/subject carry-forward is retained.
- `admin/school-year-rollover.php`: actual students and grades drive the preview. It lists eligible, honor, regular, incomplete, retained, summer-class and graduating counts; section populations, capacities, overflow names, and individual review reasons. Refreshing a preview changes no database records. Changed targets require another preview.
- `admin/run-school-year-rollover.php` and `admin/undo-school-year-rollover.php`: authenticate and validate the reviewed year, targets and fingerprint; execute or restore transactionally. Undo restores the exact run's placements/archival flags and retains grades, sections and audit records. Undo refuses to overwrite placement changes made after rollover. Legacy yearly snapshots remain supported for the immediately previous year.
- `includes/promotion-policy-schema.php`, `admin/save-promotion-policy.php`, `database/migrate_promotion_policy.php`: explicit honor and subject configuration plus additive audit schema.
- `includes/section-catalog-schema.php`, `includes/school-year-schema.php`, `admin/student-list.php`: correct new-install capacity defaults, preserve retired historical tables, and label only explicitly configured honor sections.

`student-promotion-schema.php`, its subject-final calculation, existing 0–1 failure promotion / 2 failures summer-class / 3+ failures retention rules, stored grades and placement-name lists are preserved. Missing/inconsistent annual grades now block rollover rather than counting as passed.

## Grade and honor rules

Every configured subject must have four numeric quarters, a final grade consistent with the existing equal-quarter subject calculation, and a consistent Passed/Failed result. Missing subjects, quarters, final grades, unexpected subjects, or grade/strand mismatches require review. Grade-result records are never rewritten by this process.

For honor qualification, the general average is the equal-weight mean of the completed subject quarter averages **without intermediate rounding**. The numerator is an integer sum of quarter hundredths and the denominator is four times the subject count. Qualification compares this exact total against 90; ranking compares exact rational values. Thus 89.99, 89.50, and 89.9975 remain below 90 even if a stored subject final displays as 90. The running/rounded grade display elsewhere in the application remains unchanged. Equal averages are resolved by ascending LRN using a stable string comparison; names and original sections never determine honor eligibility.

Confirmed junior-high designations are Grade 8 Amethyst, Grade 9 Peace, and Grade 10 Aristotle. Regular Grade 8 sections are Emerald and Jade. Senior high has no implied honor section: Academic and Techpro are independent placements, and any SHS honor designation requires an explicit administrator choice.

Honor seats are not filled with nonqualifiers. Excess qualifiers are ranked and named with their averages and an explicit capacity-review reason. They are not substituted into regular sections; the entire rollover remains blocked pending administrator review. No additional honor section is automatically created.

Regular overflow uses the existing `Grade N - [Strand - ]Section X` naming convention, skips existing active/inactive names, and balances regular populations across all available regular sections. For example, 100 regular students with 40-seat sections use Emerald 34, Jade 33, and Grade 8 - Section 4 with 33.

## Additive database changes

| Structure | Purpose |
|---|---|
| `promotion_honor_sections` | One explicitly configured honor section per grade/strand, referencing existing section IDs. |
| `promotion_required_subjects` | Exact required subject list per school year, grade and strand. |
| `school_year_rollover_runs` | Versioned run audit, reviewed input fingerprint, and uniqueness for a completed source year. |
| `school_year_rollover_students` | Immutable per-run original/resulting placement and academic standing snapshots. |
| `school_year_rollover_sections.RUN_ID` | Associates newly created regular sections with their originating run for safe undo. |

Legacy installations also receive missing `students.IS_ARCHIVED` / `ARCHIVED_BY` columns additively. Existing student, grading, attendance, section, teacher and history rows are preserved. Section names are never duplicated. Undo deactivates unused sections created by its run instead of deleting section/history records.

## Operation

Back up the database and run:

    C:\xampp\php\php.exe database/migrate_promotion_policy.php

The migration is CLI-only and idempotent. DDL runs before transactional configuration seeding; runtime preview/execution performs no schema migration. It initializes junior-high required subjects from the system's existing eight-subject catalog without overwriting configured curricula. Review these requirements in School-Year Rollover → Promotion settings. Configure exact Grade 11/12 subject lists per strand before graduating or promoting those students; an unconfigured list is deliberately incomplete. Requirements and active teacher assignments carry forward with rollover.

The live database had only one partial subject result at audit time. The new preview consequently blocks finalization until annual grading and SHS subject configuration are complete. No live rollover was performed. The pre-change backup is local and ignored at `storage/promotion-upgrade/before.sql`.

## Verification

    C:\xampp\php\php.exe tests/promotion-sectioning.test.php
    node tests/promotion.integration.cjs
    node tests/teacher-portal.integration.cjs
    node --test tests/quarterly-grades.test.cjs tests/student-pagination.test.cjs

Calculation tests cover threshold boundaries, qualifying-round-up prevention, 15 honor students, 70/71/90/100 regular-student distributions, unequal capacities, retained occupancy, honor overflow/ranking/ties, incomplete grades/curricula, existing failed-subject decisions, archived/transferred exclusion, Grades 8–10 reuse, SHS separation, deterministic results and overflow name collisions.

HTTP/database tests use uniquely named isolated database copies. They verify read-only preview, incomplete/honor-capacity blocks, stale configuration rejection, injected write-failure rollback, exact section populations, one promotion per student, original Grade 12 graduation only, repeat-request protection, undo/rerun after changed source placement, unchanged grade records, and unchanged live student/teacher/attendance/history data. The teacher portal regression suite also exercises the corrected complete-grade rollover and undo. Browser visual testing is not included.
