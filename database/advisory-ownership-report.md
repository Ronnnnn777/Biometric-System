# Advisory ownership repair — 29 October 2026

## Audit result

The live database already contained the correct 19 active teacher accounts and 19 unique active advisories for SY 2026-2027. Hazel Charise Leoncio was and remains adviser of Grade 8 Amethyst. No reassignment, new teacher, new section, or password change was needed.

There was no global subject uniqueness constraint or shared-subject conflict check in the advisory table. Its generated active-teacher/active-section columns and year-specific unique keys already enforce at most one section per teacher and one adviser per section. The actual gaps were:

- The advisory view unnecessarily joined `teacher_assignments` to attach a subject to an adviser.
- Admin account creation allowed an active teacher without any advisory; restoration also activated accounts without one.
- Admin profile updates initially copied the first subject class into `teachers.GRADE/STRAND/SECTION`. When an advisory selector was omitted, that teaching class could remain in the legacy advisory profile fields.
- `syncTeacherPrimaryAssignment()` still derived legacy placement from teaching assignments.
- Imported adviser names matching several remembered sections selected the first matching row rather than requiring explicit resolution.
- Subject coverage and advisory ownership needed clearly separated diagnostics on the rollover screen.

## Authoritative relationships

`teacher_advisory_assignments` establishes advisory ownership through teacher ID, section ID, school year, and release status. `sections` supplies the grade/strand/name. `teachers.TEACHER_STATUS` determines active account access. `teacher_assignments` remains independent and grants subject grading permission only. `imported_adviser_assignments` remembers import metadata and passes through the same advisory setter; it grants no grading permission.

Advisory conflict validation never compares subjects. Two teachers teaching Filipino are valid provided their advisory sections differ. Teaching several classes does not create additional advisory sections. A teacher's first subject class no longer supplies their advisory placement.

Database unique keys `uq_adviser_year (SCHOOL_YEAR,ACTIVE_TEACHER_ID)` and `uq_section_year (SCHOOL_YEAR,ACTIVE_SECTION_ID)` enforce at-most-one even during concurrent writes. Backend transactions enforce the complementary requirement that an active account created/restored/updated has exactly one valid advisory before commit. No frontend-only or arbitrary subject-based enforcement is used. Direct SQL account maintenance must respect this invariant; the rollover audit reports any missing ownership introduced outside the application.

## Preserved mapping

| Advisory | Active adviser |
|---|---|
| Grade 7 Camia | Maria Cristina Santos |
| Grade 7 Orchids | Jose Miguel Reyes |
| Grade 7 Rose | Angela Mae Cruz |
| Grade 8 Amethyst | Hazel Charise Leoncio |
| Grade 8 Emerald | Patricia Anne Mendoza |
| Grade 8 Jade | Rafael Luis Garcia |
| Grade 9 Peace | Camille Rose Bautista |
| Grade 9 Faith | Daniel Joseph Ramos |
| Grade 9 Hope | Kristine Joy Aquino |
| Grade 10 Aristotle | Mark Anthony Villanueva |
| Grade 10 Newton | Andrea Nicole Navarro |
| Grade 10 Einsten | Paolo Gabriel Castillo |
| Grade 11 Academic Kepler | Isabella Marie Torres |
| Grade 11 Academic Smith | Vincent Carlo Dela Cruz |
| Grade 11 Techpro Carver | Alyssa Grace Fernandez |
| Grade 11 Techpro Prescott | Nathaniel James Soriano |
| Grade 12 Academic Pythagoras | Beatrice Anne Mercado |
| Grade 12 Techpro Hilton | Adrian Rafael Domingo |
| Grade 12 Techpro Burbank | Sofia Camille Velasco |

## Implemented behavior

The advisory view now has no subject columns or subject joins and excludes inactive sections, archived teachers and released/other-year ownership. Portal class cards, rosters and advisory profile labels use it exclusively; subject grading retains its separate authorization. Admin advisory selection is separate from optional subject rows and lists only available sections plus the teacher's existing advisory.

`setTeacherAdvisory()` requires a positive active section, locks the school-year/teacher/section, checks active advisory ownership, and retains the database uniqueness constraints. Assignment changes release the previous row and insert the new row inside the caller's transaction. A stale unreleased reservation belonging to an archived teacher is released without allowing an active ownership conflict. Legacy grade/strand/section fields synchronize from advisories only; assigning an advisory does not overwrite the teacher's legacy subject field.

Account creation rolls back if neither an explicit advisory nor one unambiguous remembered imported advisory is available. An active teacher cannot clear their advisory through an update; an update that omits the selector retains the actual current advisory. Restoration now requires an explicit available advisory in the same transaction as activation. Occupied/missing advisories leave the original archived account unchanged, including its password. Imported adviser matching does not infer advisories from subject rows and rejects ambiguous section matches.

## Rollover separation

The planner independently reports:

1. Advisory ownership reviews: an active teacher without exactly one active advisory, or an active section without exactly one active adviser. These require administrative resolution and can block the rollover transaction.
2. Missing subject teaching coverage: class/subject combinations without an authorized subject teacher. These are informational and do not invalidate advisers or change student academic eligibility.
3. Actual saved grade completeness: required subjects and all quarterly/final grades from `student_subject_results`.
4. Final general average/promotion eligibility, including subject failures.
5. Configured honor qualification and balanced regular-section placement within capacities.

Removing all subject teaching assignments in an isolated test left fully graded students' decisions, averages and placements unchanged. Removing an advisory blocked rollover separately without turning complete student grades into incomplete records.

The live incomplete-grade reviews remain valid. Missing subject records, incomplete quarterly records, and unconfigured senior-high curricula require administrative review. A concurrent browser grading edit was preserved; individual student grades and timestamps remain in the private local audit. These conditions come from saved records and curriculum configuration, independently of teacher subject coverage. No grades were fabricated and no promotion validation was bypassed.

Honor logic is preserved: a complete unrounded general average of at least 90 qualifies for the configured honor section. Grade 7→8 qualifiers enter Amethyst; 70 regular promoted students balance 35 Emerald / 35 Jade, subject to capacity. Incomplete students do not qualify for honor.

The existing rollover copies active advisories to the same section ID and grade in the next school year, preserves prior-year rows, and respects explicit target-year choices. It does not promote teachers with students. Administrators can change advisers through Teachers after activating the new year. Copying is idempotent and releases stale archived target-year reservations. Subject assignments carry independently. Undo restores the prior year's view while retaining assignment history.

## Backup and migration

Before changes, a full consistent dump was saved as `storage/advisory-repair/before.sql` (83,946 bytes), SHA-256 `9D2AE8F2C6363D46284C5BD6B15C6517769FA1B6B1F1EDFA3D04F3755DACAA98`.

The CLI-only `database/migrate_advisory_ownership.php` was applied twice to verify idempotence. It first checks advisory ownership and the existing unique keys, then executes `CREATE OR REPLACE VIEW current_teacher_advisory_assignments` with joins only to adviser ownership, active teachers, active sections and the active school year. It does not drop tables or alter teacher/section/grade/attendance data. DDL is intentionally outside a data transaction because MariaDB implicitly commits it.

No live assignment inserts/updates, teacher creation, password reset, section creation, or student rollover was required. Integration mutations run only in randomly named `biometric_teacher_test_*` / `biometric_promotion_test_*` database copies and loopback code copies under `tmp/`.

## Modified files

- `includes/teacher-access-schema.php`: subject-free advisory view, required advisory validation, independent legacy synchronization and available-section selector.
- `includes/adviser-assignment-schema.php`: advisory-only legacy synchronization and unambiguous import matching.
- `includes/teacher-assignment-audit.php`: independent advisory/teaching coverage diagnostics.
- `admin/add-teacher.php`, `admin/edit-teacher.php`, `admin/teacher-list.php`: separate adviser selection, explicit teaching labels and restoration selection.
- `admin/save-teacher.php`, `admin/update-teacher.php`, `admin/restore-archived-teacher.php`: transactional exactly-one advisory enforcement without deriving placement from subjects.
- `teacher/components.php`, `teacher/students.php`: advisory-only class/profile display.
- `includes/promotion-planner.php`, `includes/promotion-runner.php`, `admin/school-year-rollover.php`, `admin/rollover-review.php`: independent ownership, teaching coverage and academic-record checks.
- `database/migrate_advisory_ownership.php`, `database/teacher-portal.md`, this report, and generated `css/tailwind.css`.
- `tests/teacher-portal-fixture.php`, `tests/teacher-portal.integration.cjs`, `tests/promotion-sectioning.test.php`: ownership, shared-subject, portal, restoration and academic independence regressions.

Existing unrelated workspace changes were preserved. The existing grade saving, attendance, login, promotion history and honor distribution implementations remain subject to regression checks.

## Verification results

- 62 isolated teacher HTTP checks passed: all 19 logins, temporary password changes, complete advisory rosters, attendance authorization, grading saves, shared Filipino assignments, teaching outside an advisory, unchanged advisory profiles after subject changes, required ownership on creation/update/restoration, occupied-section rejection, archived reservation cleanup, year isolation, idempotent carry-forward, rollover/undo, and unchanged live grade fingerprints during the tests.
- 25 isolated promotion HTTP checks passed: actual saved grade validation, readable review pagination/filters, advisory/grade/capacity reviews, stale-preview rejection, transactional rollback, honor/regular distribution, immutable history, undo/re-run and live-data preservation during tests.
- 29 PHP promotion/sectioning checks passed, including 70 regular students → 35 Emerald / 35 Jade, the exact 90 threshold, capacity handling, independent advisory/subject diagnostics, and missing ownership blocking rollover without changing academic eligibility.
- 13 Node quarterly grading and roster pagination checks passed.
- PHP syntax checks passed for all 17 modified PHP implementation/fixture files. JavaScript parsing, whitespace validation and the Tailwind build passed. The build emits its existing outdated Browserslist dataset notice.
- Live SELECT checks found 19 active teachers, zero teachers with a count other than one advisory, zero duplicated sections, zero active sections without advisers, and Hazel in Grade 8 Amethyst.
- Before/after SQL dump comparisons found identical stored rows for all tables except the concurrent live Filipino grade edit described above. In particular, all teacher rows/password hashes, archived teacher records, teacher subject/advisory/import assignments, sections, students, attendance and history are unchanged. Table definitions and unique constraints are unchanged; scanner auto-increment metadata may advance independently. The intended schema difference is the replacement advisory view. No concurrent user grade was restored from backup.

The test fixtures use database clones and synthetic data; they do not populate live student grades. HTTP markup/authorization was checked; no manual browser visual review is claimed. The private per-student grade audit can be refreshed with `C:\xampp\php\php.exe database/audit_rollover.php`.
