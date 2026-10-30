# Teacher portal migration

Run from the project root with PHP CLI:

    C:\xampp\php\php.exe database/migrate_teacher_portal.php

Back up the database first. The migration upgrades existing assignment rows to the active school year, creates year-specific advisory ownership with foreign keys and unique constraints, and seeds only SY 2026-2027. Account and advisory creation uses one transaction; schema DDL runs before it because MariaDB implicitly commits DDL. Existing grades, Hazel's password and archived teacher records are verified unchanged.

Teachers.md is a private, ignored local credential report. Keep it for idempotent reruns while temporary passwords remain in use. Reruns never reset existing passwords. The .example addresses are unique local login identifiers, not mailboxes. New teachers have advisory access only; no teaching subject was supplied for them. Assign subjects explicitly through the administrator teacher editor.

The adviser selector and subject teaching assignments are independent. Active account creation and restoration require exactly one available advisory section; profile changes cannot clear it. Archiving releases advisory ownership while retaining history. Restoration requires an explicit available section and never reclaims an occupied advisory. Imported School Form advisers use the same ownership rules and never overwrite an existing adviser; ambiguous matches require an explicit selection.

After backing up, run `C:\xampp\php\php.exe database/migrate_advisory_ownership.php` to replace the older advisory view with one that has no subject-assignment join. The migration preserves the existing year-specific unique constraints and all data rows. See `database/advisory-ownership-report.md` for the follow-up audit and verification. Portal rosters and profile labels use advisory ownership only; legacy teacher grade/section fields are synchronized only from advisories.

Assignments are scoped to school year. Rollover copies active advisers and subject assignments to the new year for the same sections, within the existing student rollover transaction. Teachers are not promoted with their students. Explicit target-year assignments take precedence, inactive sections and archived teachers are excluded, and repeated copying creates no duplicates. Undoing rollover restores the previous year's view and keeps the other year's assignment history. Administrators can change new-year assignments through Teachers. Legacy teachers.GRADE/STRAND/SECTION fields are retained for compatibility; portal authorization uses current_teacher_advisory_assignments and current_teacher_subject_assignments.
