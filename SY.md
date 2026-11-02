# School System Working Context

This file is the handoff/context note for the current uncommitted working tree. It records the intended behavior and the major pending work areas so future requests can refer to this list.

## Current school-year rollover behavior

- Rollover is preview-first and commits through one authenticated, CSRF-protected database transaction. The preview fingerprint prevents confirmation if students, grades, sections, targets, or selected outcomes changed after review.
- Grade 12 students graduate at rollover: `ENROLLMENT_STATUS='Graduated'`, `STUDENT_STATUS='Archived'`, `ARCHIVE_REASON='Graduated'`, and `IS_ARCHIVED=1`.
- Students already marked `Transferred` or `Dropped` are archived in that same transaction, retain their enrollment status, and receive the corresponding archive reason.
- Rollback/undo uses immutable before/after snapshots and restores grade, strand, section, enrollment, archive state, and status fields. Run history remains available after undo.

### Academic outcomes and sectioning

- The exact average of four recorded quarters is used when present, avoiding display rounding across the 90.00 honor boundary; otherwise a valid final grade or available-quarter average is used.
- A student with one or more final subject grades below `75.00` receives the decision `Summer Class / Remedial`. This is an auditable promotion outcome, not an automatic retention rule: the student advances and is sectioned normally.
- An administrator can explicitly mark a student `Retained` during Step 2 of the rollover preview. Retained students remain active in their existing grade, strand, and section and are excluded from destination auto-sectioning.
- Each Grade 10 student has an individual required Grade 11 choice (`Academic` or `Techpro`) in the rollover review. This selection, rather than the older percentage split, determines the target strand before sectioning.
- Section 1 is the configured honor section (or the first ordered destination section if no explicit designation exists). It is **uncapped**: every promoted student with GWA `>= 90.00` is placed there, bypassing the section's configured capacity. Honor placement is ordered by highest exact GWA, then LRN.
- Students below 90.00 are split with `chunkEvenly()` across Sections 2 and above. Regular-section capacities are still respected and generated overflow sections are added as needed.

### Rollover UI and data flow

- `admin/school-year-rollover.php` provides four review steps: advisory-class roster, promotion/outcome review, section preview, and password-protected confirmation.
- Outcome and Grade 10 strand selections are posted back to refresh the preview, included in the fingerprint, and forwarded as hidden confirmation inputs to `admin/run-school-year-rollover.php`.
- `student_promotion_decisions` stores the final failed-subject count and decision for each processed student.

## Student roster and archival behavior

- `admin/student-list.php` owns both active and archived views.
- Active rosters exclude `Graduated`, `Transferred`, `Dropped`, and all records whose student status is archived.
- Archived view has an archive-category capsule control: `All`, `Graduated`, `Transferred`, and `Dropped`.
- Archive records retain their historical grade/strand/section and attendance history; graduates cannot be restored through the routine archived-student restore control.

## Teacher advisory behavior

- Advisory ownership is stored in the normalized `teacher_advisory_assignments` table, not a legacy `ADVISORY_SECTION_ID` column.
- Subject teaching assignments can carry into the target school year, but rollover releases all target-year advisory mappings. The new year starts with no active adviser assignments.
- `admin/teacher-list.php` has a bulk post-rollover roster: every active section is shown with an optional active-teacher selector.
- Saving the roster is transactional, validates active teachers/sections, releases current-year adviser rows, and rejects assigning one teacher to multiple sections. Blank sections are allowed.

## Other working-tree feature areas

- Attendance/scanning work is present across the admin, API, and teacher surfaces: scanner-event tracking, live scan/history endpoints, attendance-log improvements, attendance export changes, and mobile scan/login adjustments.
- A school-calendar feature is present: calendar administration, holiday/suspension/makeup-day entries, and a shared calendar schema used to identify instructional days.
- Student/teacher archive handling and active-roster restrictions have related updates in scanner, attendance, export, and profile routes.
- Navigation and styling updates are pending, including the school-calendar entry point and Tailwind output changes.

## Validation completed

- `tests/promotion-sectioning.test.php`: 31 tests pass, including **uncapped** honor placement (all students with GWA `>= 90.00` fill Section 1 regardless of its configured capacity), equal regular sectioning via `chunkEvenly()`/`assignStudentsToSections()`, remedial promotion, manual retention, Grade 10 strand selection, and transferred/dropped archival planning.
- The database-backed `tests/promotion.integration.cjs` was run against XAMPP/MySQL. It exercised preview immutability, stale-preview rejection, transactional rollback, rollover, advisory reset, archive outcomes, undo, and re-run/undo behavior using disposable schemas.
- PHP syntax checks passed for the modified rollover, planner, runner, student-list, and teacher-list files.
- The integration harness now excludes a generated `.insightface-venv` directory from disposable copies to avoid disk exhaustion. Disposable databases and folders created during testing were removed afterward.

## Suggested next tasks

1. Review the complete uncommitted attendance/scanner/calendar work as a separate feature set and run its dedicated browser/API regression checks.
2. Manually test the rollover UI in a browser with a Grade 10 student, a retained override, a remedial student, and transferred/dropped records before production use.
3. Verify the bulk advisory roster with the real active-section catalog (expected 19 sections) and real teacher accounts.
4. Decide whether teacher-facing grade entry should also expose a formal retention recommendation/override workflow; currently retention is set by an administrator during rollover review.
5. Review all pending files, stage intended changes by feature, and commit the rollover, attendance/scanner, and calendar work separately.
