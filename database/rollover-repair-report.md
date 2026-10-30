# Rollover missing-grade audit — 29 October 2026

## Confirmed cause

The active database is `biometric`, SY `2026-2027`, MariaDB 10.4.32 on port 3306. The 59 reviews are accurate. No query repair or live grade backfill is justified.

| Finding | Count |
|---|---:|
| Enrolled, nonarchived students | 59 |
| Students requiring review | 59 |
| Students ready for promotion/graduation | 0 |
| Junior-high students requiring eight subjects each | 57 |
| Required junior-high subject records absent | 455 |
| Existing subject records with incomplete quarters | 1 |
| Enrolled senior-high students without configured curricula | 2 |
| Required occupied class/subject combinations without an active subject teacher | 47 |
| Active teachers / active advisory assignments | 19 / 19 |
| Duplicate active advisory sections or advisers | 0 |

The audit found an incomplete subject result with outstanding quarters. A running subject average cannot establish an annual average or honor qualification. Other junior-high required records are absent, and occupied senior-high strands need configured subject lists. Individual student records and grade values remain only in the private audit.

The eight configured junior-high subjects are Araling Panlipunan, English, Filipino, MAPEH, Math, Science, TLE, and Values Education. No student/year/subject/grade/strand mismatch was found in the one existing grade record.

`storage/teacher-upgrade/before.sql` (before the 18 accounts) and `storage/promotion-upgrade/before.sql` (after the accounts) contain exactly the same `student_subject_results` INSERT as the new audit backup, including its saved timestamp. The earlier file contains only the original two teacher accounts; the later file contains the additional 18. This establishes that the missing grades predate account creation.

## Teacher account audit

All 18 scripted mock accounts have the intended active advisory class and no subject assignments. Hazel Charise Leoncio remains adviser to Grade 8 Amethyst and the active Filipino subject teacher there. The other original teacher is archived; that teacher's historical assignments do not grant current access. All 19 active sections have unique active advisers, and database unique keys protect both teacher/year and section/year ownership.

`database/migrate_teacher_portal.php` deliberately seeds advisory-only accounts with empty subjects and a required first-login password change. It calls the same `setTeacherAdvisory()` helper as Admin account creation. `admin/save-teacher.php` additionally accepts explicit subject assignments and calls `replaceTeacherAssignments()`; it can also create advisory-only accounts. Neither path creates grading records. No arbitrary subjects were assigned to advisers during this repair.

## Grading trace and repairs

`teacher/grading.php` reads active-year assignments from `current_teacher_subject_assignments`. It displays rows from `student_subject_results`, selected by student LRN (`students.STUDENT_ID`), school year, and assigned subject. The numeric `students.ID` is used only in form submissions; `teacher/save-subject-result.php` resolves it to the LRN before storage. Grade/strand/section are copied from the enrolled student and checked against the teacher's assignment.

The storage primary key is `(STUDENT_ID,SCHOOL_YEAR,SUBJECT)`. `studentFinalGrade()` calculates a two-decimal running subject average from entered quarters; blank quarters remain NULL. Rollover's `promotionContext()` reads that same table for the active year, and `promotionAcademicStanding()` requires the configured subjects, matching grade/strand, all four valid quarters, a consistent calculated final, and a consistent Passed/Failed result. Annual general average/honor qualification uses unrounded quarter totals across required subjects; 89.9975 never becomes an honor-qualifying 90. Decisions preserve the existing 0–1 failed subject promotion, 2 summer-class, and 3+ retention policy.

Two adjacent inconsistencies were repaired: the student profile previously displayed promotion based only on recorded failures, even when grades were incomplete; it now uses the same completeness function as rollover. The profile's legacy result-only form could overwrite a calculated result or create a row with no numerical grades; it now links to quarterly grading, and stale result-only POST requests are rejected without writing grades. Existing records remain intact.

The review interface now provides a compact alert, distinct affected-student and ready counts, separate missing/partial-record counts, names/LRNs, grade/strand/section, missing subjects, the exact incomplete quarters, and links to teacher grading and Admin teaching assignments. Pagination is server-rendered at 15 students per page. Grade and section filters preserve preview targets and do not alter the full validation plan. Teacher grading requires the subject teacher's own sign-in; administrator access does not impersonate a teacher.

## Files changed for this task

- `admin/school-year-rollover.php`: integrate the compact review; preserve reviewed targets through filter/page requests.
- `admin/rollover-review.php`: new review table, metrics, filters, pagination and actions.
- `includes/promotion-planner.php`: structured missing/partial subject lists and exact quarter diagnostics; eligibility rules preserved.
- `includes/promotion-review.php`: new pure review aggregation/filter/pagination helper.
- `teacher/student-profile.php`: shared completeness decision and quarterly grading link.
- `teacher/save-subject-result.php`: reject legacy result-only writes.
- `css/tailwind.css`: rebuild generated styles.
- `database/audit_rollover.php`: CLI-only SELECT audit with private student-level JSON report.
- `database/rollover-audit.sql`: reproducible read-only diagnostic queries.
- `tests/promotion-sectioning.test.php`, `tests/promotion.integration.cjs`, `tests/teacher-portal-fixture.php`, `tests/teacher-portal.integration.cjs`: completeness, review pagination/filter, legacy-write and save-to-rollover regressions.
- This report.

The workspace already contained extensive unrelated changes. They were preserved.

## Database backup and SQL changes

Before repair verification, a full consistent dump including routines/events was saved at `storage/rollover-audit/biometric-before-repair-20261029.sql` (83,946 bytes), SHA-256 `C1B9E09C2A6E6593040FF89BA8E231E7B3D81CB15C5141470F0101F6A49079ED`.

No live migrations, SQL UPDATE/INSERT/DELETE operations, teacher creation, assignment creation, grade generation, or rollover execution were applied. New diagnostic SQL is SELECT-only. Integration tests create separate randomly named `biometric_teacher_test_*` and `biometric_promotion_test_*` schemas and serve code copies on loopback; synthetic grades exist only in those isolated schemas. Each test server stops its own process. Test schemas and copied artifacts remain under `tmp/` for inspection.

Run `C:\xampp\php\php.exe database/audit_rollover.php` to refresh the audit. Complete per-student missing records and teacher assignment gaps are in the ignored private `storage/rollover-audit/review.json`. Apache denies access to `storage`, `database` and `tmp` through the existing root `.htaccess` rules.

## Required completion before rollover

1. Have the administrator assign the actual subject teachers to the 47 uncovered junior-high class/subject combinations using Teachers → Edit. Advisory assignments already exist and should not be duplicated.
2. Configure the school-approved Grade 12 Academic and Techpro required subjects in rollover promotion settings, then assign their actual subject teachers. Review Grade 11 requirements before its next enrolled cohort is promoted.
3. Subject teachers must enter the missing subject grades and remaining quarters from authoritative records. For the one existing Filipino record, Q2, Q3 and Q4 are specifically outstanding. Refresh the audit and rollover preview afterward.

No passing or zero grades should be inferred from accounts, attendance, a partial running final, or an empty curriculum. Rollover remains blocked until these records/configuration are complete and any capacity reviews are resolved.

## XAMPP diagnostics

`config/db_conn.php`, `C:\xampp\mysql\bin\my.ini` (client and server), the running server's `@@port`, and TCP listening sockets agree on 3306. PID 1228 is `mysqld`. Apache is `httpd` PID 3464, listening on 80/443; `httpd.conf` specifies `Listen 80` and `ServerName localhost:80`. No conflict was verified. No ports, services or unrelated processes were changed; no restart is required.

Local app URLs use `/biometric-system/` in this checkout. The scanner's initial example URL uses `/biometric/`; its configured server URL must match the actual host/path. `api/mobile-discovery.php` derives the host and base path from the request, so the example is not a grading database connection setting.

## Verification

- 27 PHP promotion/sectioning checks: complete 90+ honor threshold, 89.9975 boundary, missing subject/quarter blocks, failures/retention, capacities, 70 regular students → Emerald 35/Jade 35, retained occupants, strand separation, overflow and deterministic assignment; new distinct-student counts and pagination/filter checks.
- 25 isolated promotion HTTP checks: 15/15/1 review pages, filters preserve validation, read-only preview, direct-submit bypass rejection, stale-preview rejection, transaction rollback, section balancing, history preservation, undo/re-run and unchanged live data.
- 52 isolated teacher HTTP checks cover all 19 logins, 18 forced password changes, archived login rejection, advisory attendance access, bulk/individual saves, authorization, calculated finals, incomplete profile decisions, legacy result overwrite rejection, assignment uniqueness/carry-forward, rollover/undo and unchanged live grades. A check directly loads rollover after a teacher save and confirms Filipino is found while the other seven missing subjects still block promotion.
- 13 Node checks cover quarterly navigation/saves and roster pagination/filter behavior.
- PHP/JavaScript syntax checks, Tailwind build, and whitespace validation passed on the affected files. Tailwind reports an existing outdated Browserslist dataset; the build succeeds.
- Full before/after live dumps have identical stored rows and schema definitions except `authorized_mobile_scanners.AUTO_INCREMENT`, which advanced from 4649 to 4651 during the audit. No scanner rows changed; the counter was preserved. Dump completion timestamps naturally differ. Historical grade INSERT statements were also compared directly and are identical across the two prior backups and the new backup.

HTTP markup and scripts are tested; no manual browser visual assessment is claimed. The application still needs authoritative grades, curricula and subject assignments; the software must not invent them.
