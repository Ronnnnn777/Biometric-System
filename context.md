# Context — Biometric Attendance System (Maruhat National High School)

> **Purpose of this document.** A single, self-contained knowledge base describing
> the architecture, data foundation, core logic, and UI conventions of the
> `biometric-system` codebase. It is written to be uploaded to Google NotebookLM as
> grounding context for planning the remaining **backend logic fixes** ahead of the
> **thesis re-defense**.
>
> **Project root:** `C:\xampp\htdocs\biometric-system`
> **Document generated:** 2026-10-21
> **Primary language:** PHP 8 (server-rendered) with MariaDB, Tailwind CSS, vanilla JS, and a Python face-recognition service.

---

## Table of Contents

1. [Project Overview & Tech Stack](#1-project-overview--tech-stack)
2. [Directory & Architecture Map](#2-directory--architecture-map)
3. [Database Schema (The Data Foundation)](#3-database-schema-the-data-foundation)
4. [Core Workflows & Logic](#4-core-workflows--logic)
5. [UI & State Refactoring](#5-ui--state-refactoring)
6. [Appendix A — File Inventory](#appendix-a--file-inventory)
7. [Appendix B — Glossary](#appendix-b--glossary)
8. [Appendix C — Open Items / Re-Defense Focus](#appendix-c--open-items--re-defense-focus)

---

## 1. Project Overview & Tech Stack

### 1.1 What the system is

A web-based **biometric (facial-recognition) attendance system** for a Philippine
public secondary school (Maruhat National High School). Students are enrolled with a
reference face photo; a camera (USB webcam or an Android phone acting as a mobile
scanner) detects and recognizes faces and records attendance in real time. Records are
reviewed, filtered, corrected, and exported by administrators and by class advisers
(teachers).

The system serves **Grades 7–12**, including Senior High School (SHS) strands
(`Academic`, `Techpro`) for Grades 11–12.

### 1.2 Portals and roles

| Portal | Path | Role (`$_SESSION['user-role']`) | Purpose |
|---|---|---|---|
| Admin | `/admin` | `administrator` | Students, teachers, sections/settings, attendance log, scanner, school-year rollover, archives, imports/exports |
| Teacher | `/teacher` | `teacher` | Assigned section rosters, attendance logs, subject results, student profiles |
| Camera | `/camera` | public kiosk page | Choose attendance camera and open the scanner |
| Mobile API | `/api` | `mobile_scanner` | Android scanner app login, session, preview, scan |
| Common | `/common` | — | Shared logout |

Authentication is **session-based**. The admin login lives at `admin/login.php` →
`admin/auth.php`, and the same form authenticates teachers (a teacher account that is
`Active` logs into the teacher portal). The first default admin is forced to change its
password on first login. Each protected page begins with a `page-guard.php` that
validates the session role.

### 1.3 Tech stack

| Layer | Technology | Notes |
|---|---|---|
| Backend | **PHP 8** | `mysqli` with prepared statements and `bind_param`; typed functions; null-coalescing / arrow functions |
| Database | **MariaDB / MySQL** (XAMPP) | Database name `biometric`, user `root`, no password, port `3306`; timezone pinned to `Asia/Manila` / `+08:00` |
| CSS | **Tailwind CSS 3.4.17** | Source `css/tailwind-input.css` → precompiled `css/tailwind.css`; component layer for `.btn`, `.card`, `.field` |
| Theming | `css/theme.css` + `js/theme.js` | Light/dark/system via `html[data-theme=...]` and CSS variables |
| Frontend JS | **Vanilla JavaScript** | `/js/*.js` + `admin/manual-attendance.js`; no framework |
| Face recognition | **Python** | Local service at `http://127.0.0.1:8772/recognize`; CLI fallback `admin/recognize_face.py`; long-running service `admin/recognize_face_service.py` |
| Mobile scanner | **Android (Kotlin/Java)** under `/android-scanner` | Talks to `/api` endpoints; build artifacts are checked in |
| Build | **npm** | `npm run build:css` runs the Tailwind CLI; `npm run watch:css` for dev |

### 1.4 Database connection (`config/db_conn.php`)

```php
date_default_timezone_set('Asia/Manila');
$dbhost="localhost"; $dbuser="root"; $dbpass=""; $dbname="biometric";
$conn = new mysqli($dbhost, $dbuser, $dbpass, $dbname, 3306);
$conn->query("SET time_zone = '+08:00'");
```

Every page `require`s this file and then calls one or more **lazy, idempotent schema
helpers** (see §3.6) so the database self-upgrades on first visit.

### 1.5 Security model (current)

- **CSRF:** a 32-byte hex token in `$_SESSION['csrf_token']` (`includes/csrf-token.php`); every state-changing form posts `csrf_token` and validates with `hash_equals()`.
- **Password hashing:** `password_hash()` / `password_verify()` (bcrypt).
- **Session hardening:** `session_regenerate_id(true)` on login; `page-guard.php` sends `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`.
- **Destructive admin actions** (rollover, undo) re-verify the administrator password.

---

## 2. Directory & Architecture Map

### 2.1 High-level tree

```text
biometric-system/
├── index.php                     # Entry router / landing
├── config/
│   └── db_conn.php               # mysqli connection (Asia/Manila, db "biometric", :3306)
├── includes/                     # Shared, reusable PHP libraries (no UI)
│   ├── ui.php                    # ui_topbar(), ui_button(), ui_card_header()
│   ├── icons.php                 # Inline SVG icon() helper
│   ├── csrf-token.php            # Session CSRF token bootstrap
│   ├── validation.php            # sanitize() and input validators
│   ├── sectioning.php            # *** Capacity-aware auto-sectioning chunker ***
│   ├── section-catalog-schema.php# sections table (+CAPACITY, +SORT_ORDER), placement helpers
│   ├── student-placement-options.php # Hard-coded canonical section names per grade/strand
│   ├── student-profile-schema.php# students profile columns + enrollment statuses
│   ├── attendance-schema.php     # attendance columns, snapshots, status/late logic
│   ├── school-year-schema.php    # year settings, promotion plan, history, rollover sections
│   ├── student-promotion-schema.php # subject results + promotion decision
│   ├── teacher-profile-schema.php# teacher profile columns + subject list
│   ├── teacher-assignment-schema.php # teacher_assignments table + helpers
│   ├── adviser-assignment-schema.php
│   ├── school-year-schema.php
│   ├── student-import.php        # Spreadsheet import parsing
│   ├── xlsx-export.php           # Single-sheet XLSX writer (downloadAttendanceXlsx)
│   ├── biometric-image.php       # Reference face image storage
│   ├── staff-biometric.php       # Staff biometric login support
│   ├── mobile-scanner-settings.php / mobile-scanner-activity.php
│   ├── camera-portal-schema.php
│   ├── site-nav.php              # Navigation model for admin/teacher shells
│   └── default-admin.php         # Seeds the default administrator
├── admin/                        # Administrator portal + scanner backend
│   ├── components.php            # adminHead(), adminSidebar(), adminFoot(), admin <style>
│   ├── page-guard.php            # Role guard (administrator) + security headers
│   ├── auth.php / login.php / reset-password.php / forgot-password.php
│   ├── home.php                  # Dashboard (stats, scanners)
│   ├── add-student.php / edit-student.php / save-student.php / update-student.php
│   ├── student-list.php / teacher-list.php / attendance-log.php
│   ├── add-teacher.php / edit-teacher.php / save-teacher.php / update-teacher.php / delete-teacher.php
│   ├── import-students.php / import-students-save.php
│   ├── archive-student.php / restore-archived-student.php / delete-student.php
│   ├── archived-students.php / archived-student-record.php
│   ├── archived-teachers.php / archived-teacher-record.php
│   ├── scan-attendance.php       # USB webcam scanner UI
│   ├── scan-match.php            # *** Core recognition + attendance writer ***
│   ├── scanner-guard.php / scanner-exit.php
│   ├── log-attendance.php        # Attendance log endpoint (snapshot write)
│   ├── manual-attendance.js / manual-attendance-save.php / manual-student.php / manual-students-search.php
│   ├── get-latest-attendance.php # JSON rows for the attendance table
│   ├── update-attendance-remark.php
│   ├── export-attendance.php     # Roster-driven XLSX export
│   ├── get-stats.php / check-status.php
│   ├── run-automatic-absences.php
│   ├── school-year-rollover.php          # Rollover UI (grade-level plan)
│   ├── school-year-rollover-preview.php  # Dry-run preview + final confirm
│   ├── save-school-year-rollover-map.php # Persist plan
│   ├── run-school-year-rollover.php      # *** Rollover runner (auto-sectioning) ***
│   ├── undo-school-year-rollover.php     # Restore from history + cleanup
│   ├── settings.php / settings-save.php  # Sections, capacity, scanners, security
│   ├── activate-mobile-scanner.php / disable-mobile-scanner.php / remove-mobile-scanner.php
│   ├── detect-mobile-scanner-server.php / mobile-scanner-feed.php / mobile-scanner-preview-status.php
│   ├── biometric-login-match.php
│   └── recognize_face.py / recognize_face_service.py   # Python face engine (referenced)
├── teacher/                      # Teacher portal
│   ├── components.php            # teacherHead(), teacherShell(), teacherFoot()
│   ├── page-guard.php / auth.php / login.php
│   ├── home.php                  # Attendance logs view
│   ├── students.php / student-profile.php
│   ├── get-attendance.php        # JSON rows for teacher table
│   ├── mark-present.php / mark-absent.php
│   ├── update-attendance-remark.php
│   ├── save-subject-result.php
│   ├── settings.php / settings-save.php
│   └── export-attendance.php
├── api/                          # JSON endpoints for the Android mobile scanner
│   ├── mobile-login.php / mobile-logout.php / mobile-session.php / mobile-session-config.php
│   ├── mobile-scan.php           # Delegates to admin/scan-match.php
│   ├── mobile-preview.php
│   └── mobile-app-update.php
├── camera/                       # Camera portal (index.php, logout.php)
├── common/logout.php
├── js/                           # theme.js, table-pagination.js, student-pagination.js,
│                                 # quiet-attendance-refresh.js, password-toggle.js, script.js
├── css/                          # tailwind-input.css (source), tailwind.css (build), theme.css, style.css
├── database/
│   └── migration_teacher_accounts.sql   # teachers table bootstrap (legacy)
├── docs/                         # changes.md, implementation-changes.md, sectioning-grade-promotion-plan.md
├── android-scanner/              # Android scanner project (+ checked-in build/ artifacts)
├── image/                        # Logo and static images
├── package.json / tailwind.config.js
└── context.md                    # (this file)
```

### 2.2 Key files at a glance

| File | Responsibility |
|---|---|
| `config/db_conn.php` | Single `mysqli` connection; timezone enforcement. |
| `includes/ui.php` | `ui_topbar()`, `ui_button()`, `ui_card_header()` — shared chrome. |
| `includes/sectioning.php` | **New** capacity-aware chunker: `orderedTargetSections()`, `assignStudentsToSections()`, `sectionPlanForBatch()`, `ensureSectionCapacity()`, `splitStudentsByWeights()`, `normalisePromotionPlans()`. |
| `includes/section-catalog-schema.php` | `sections` schema, `CAPACITY`/`SORT_ORDER`, seeding, `reconcileBaseSectionOrder()`, placement validation. |
| `includes/school-year-schema.php` | `school_year_settings`, `school_year_promotion_plan`, `student_school_history`, `school_year_rollover_sections`, snapshot backfill. |
| `includes/attendance-schema.php` | `attendance` columns, snapshot columns + helpers, late/status logic, whole-day mode. |
| `admin/run-school-year-rollover.php` | **The promotion runner** — auto-sections promoted students at 40/section. |
| `admin/attendance-log.php` | Attendance log page: filters, table shell, remark modal, styles. |
| `admin/get-latest-attendance.php` | Renders the attendance `<tbody>` rows (AJAX), incl. the REASON/ACTION cell. |
| `admin/export-attendance.php` | Roster-driven XLSX export, snapshot-first. |
| `admin/scan-match.php` | Face recognition → attendance decision → DB write (used by webcam + mobile API). |
| `admin/school-year-rollover.php` / `-preview.php` / `save-school-year-rollover-map.php` | Rollover UI, dry-run preview, plan persistence. |
| `admin/undo-school-year-rollover.php` | Restores placements from `student_school_history`, removes empty auto-created sections. |

---

## 3. Database Schema (The Data Foundation)

> **Important:** there is no single `schema.sql` for the live database. Tables are
> created/extended at runtime by **idempotent schema helpers** in `includes/*schema*.php`.
> Column names are UPPERCASE; the code toggles case for inspection via `SHOW COLUMNS`.

### 3.1 `students` — the roster (core table)

| Column | Type (approx.) | Notes |
|---|---|---|
| `ID` | INT AUTO_INCREMENT PK | Internal surrogate key |
| `STUDENT_ID` | VARCHAR | **LRN**; the business key joined throughout the app via `TRIM()` |
| `STUDENT_NAME` | VARCHAR | Display name (legacy) |
| `FIRST_NAME` / `MIDDLE_NAME` / `LAST_NAME` | VARCHAR(80) | Structured name (added by `student-profile-schema.php`) |
| `GRADE` | TINYINT UNSIGNED | 7–12 |
| `AGE` | TINYINT UNSIGNED | Validated 3–120 |
| `STRAND` | VARCHAR(20) | `''` for JHS; `Academic`/`Techpro` for Grades 11–12 |
| `ADDRESS` | VARCHAR(255) | |
| `SECTION` | **VARCHAR(80)** | Widened from 20 so overflow names fit (e.g. `Grade 11 - Academic - Section 3`) |
| `ENROLLMENT_STATUS` | VARCHAR(20) | `Enrolled`, `Transferred`, `Dropped`, `Graduated`, `Archived` |
| `ARCHIVE_REASON` | VARCHAR(40) | e.g. `Manual archive`, `Graduated` |
| `ARCHIVED_AT` | DATETIME NULL | |
| `IMAGE_PATH` | VARCHAR | Reference face photo path |

**Relationship:** `students.STUDENT_ID` ↔ `attendance.STUDENT_ID` (trimmed string join).
Current placement (`GRADE`/`STRAND`/`SECTION`) is the live roster; historical placement
is frozen into attendance snapshots and `student_school_history`.

### 3.2 `attendance` — daily records (core table)

| Column | Type | Notes |
|---|---|---|
| `ID` | INT AUTO_INCREMENT PK | |
| `STUDENT_ID` | VARCHAR | FK-by-value to `students.STUDENT_ID` |
| `STUDENT_NAME` | VARCHAR | Denormalized at write time |
| `GRADE_SNAPSHOT` | TINYINT UNSIGNED NULL | **Placement frozen at record time** |
| `STRAND_SNAPSHOT` | VARCHAR(20) DEFAULT '' | **Placement frozen at record time** |
| `SECTION_SNAPSHOT` | **VARCHAR(80) DEFAULT ''** | **Placement frozen at record time (new for the fix)** |
| `TIME_IN` | DATETIME | Also encodes absence date (`00:00:00` for teacher-marked absences) |
| `LUNCH_OUT` | DATETIME NULL | Whole-day mode |
| `AFTERNOON_TIME_IN` | DATETIME NULL | Whole-day mode |
| `TIME_OUT` | DATETIME NULL | |
| `ATTENDANCE_REMARK` | VARCHAR(40) NULL | `Absent`, `Excused late`, `Excused absence`, `Early dismissal`, `Emergency dismissal` |
| `ATTENDANCE_NOTE` | VARCHAR(255) NULL | Reason/comment |
| `IS_AUTO_ABSENT` | TINYINT(1) DEFAULT 0 | Retired auto-absences (system rows deleted on schema ensure) |

**Why snapshots matter:** a student who is later promoted/moved must not have their
*past* attendance re-attributed to their *new* section. Reports prefer
`COALESCE(NULLIF(a.SECTION_SNAPSHOT,''), s.SECTION)` with current placement as fallback.

### 3.3 `sections` — the section catalog (core table)

| Column | Type | Notes |
|---|---|---|
| `SECTION_ID` | INT AUTO_INCREMENT PK | |
| `SECTION` | VARCHAR(80) UNIQUE | Section name (globally unique) |
| `GRADE` | TINYINT UNSIGNED NULL | 7–12 |
| `STRAND` | VARCHAR(20) DEFAULT '' | SHS strand, else `''` |
| `BASE_SECTION` | VARCHAR(80) DEFAULT '' | Canonical named sections; **empty string means auto/overflow-generated** |
| `IS_ACTIVE` | TINYINT(1) DEFAULT 1 | |
| **`CAPACITY`** | **SMALLINT UNSIGNED NOT NULL DEFAULT 40** | **Max students per section (new for the fix)** |
| **`SORT_ORDER`** | **INT NOT NULL DEFAULT 0** | **Deterministic fill order (new for the fix)** |

Companion table **`section_settings`**: `ID=1`, `DEFAULT_CAPACITY SMALLINT DEFAULT 40` —
the configurable fallback used for new/overflow sections.

**Ordering rules (`reconcileBaseSectionOrder()`):**
- Named catalog sections get canonical `SORT_ORDER` values derived from
  `studentSectionsForGrade()`.
- Auto-created/overflow sections (`BASE_SECTION=''`) always sort **after** named ones
  (`SORT_ORDER = 1000 + SECTION_ID`).

**Canonical fill order (from `includes/student-placement-options.php`):**

| Grade | Sections (in fill order) |
|---|---|
| 7 | Camia → Orchids → Rose |
| 8 | **Amethyst → Emerald → Jade** |
| 9 | Peace → Faith → Hope |
| 10 | Aristotle → Newton → Einsten |
| 11 Academic | Kepler → Smith |
| 11 Techpro | Carver → Prescott |
| 12 Academic | Pythagoras |
| 12 Techpro | Hilton → Burbank |

### 3.4 `student_school_history` — year-over-year archive (core table)

| Column | Type | Notes |
|---|---|---|
| `STUDENT_ID` | VARCHAR(50) | Composite PK part |
| `SCHOOL_YEAR` | VARCHAR(20) | Composite PK part (e.g. `2025-2026`) |
| `GRADE` | TINYINT UNSIGNED | Pre-rollover placement |
| `STRAND` | VARCHAR(20) DEFAULT '' | |
| `SECTION` | VARCHAR(80) | |
| `ENROLLMENT_STATUS` | VARCHAR(20) | |
| `ARCHIVED_AT` | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |

**Primary key:** `(STUDENT_ID, SCHOOL_YEAR)`. Written with `INSERT IGNORE` so retries
don't duplicate. This is the **undo source of truth**.

### 3.5 Supporting tables

| Table | Primary key | Purpose / key columns |
|---|---|---|
| `school_year_settings` | `ID=1` | `ACTIVE_SCHOOL_YEAR VARCHAR(20)` |
| `school_year_promotion_plan` | `(FROM_GRADE, FROM_STRAND, TO_STRAND)` | `TO_GRADE`, `SPLIT_PERCENT` (default 100), `UPDATED_AT` |
| `school_year_rollover_sections` | `(SCHOOL_YEAR, SECTION)` | `GRADE`, `STRAND` — auto-created sections, so undo can clean them up |
| `student_subject_results` | `(STUDENT_ID, SCHOOL_YEAR, SUBJECT)` | `RESULT` (`Passed`/`Failed`), `TEACHER_ID`, `GRADE`, `STRAND`, `SECTION` |
| `student_promotion_decisions` | `(STUDENT_ID, SCHOOL_YEAR)` | `FAILED_SUBJECTS`, `DECISION` |
| `teacher_assignments` | `(TEACHER_ID, GRADE, STRAND, SECTION)` | `SUBJECT` |
| `attendance_day_settings` | `ID=1` | `WHOLE_DAY_ENABLED TINYINT(1)` |
| `teachers` | `TEACHER_ID` | `TEACHER_USER` (unique), `TEACHER_PASS`, `TEACHER_NAME`, `FIRST_NAME`, `LAST_NAME`, `GRADE`, `STRAND`, `SECTION VARCHAR(80)`, `SUBJECT`, `TEACHER_STATUS` (`Active`/`Archived`), `ARCHIVED_AT` |
| `admins` | `ADMIN_ID` | `ADMIN_USER`, `ADMIN_PASS` |
| `section_settings` | `ID=1` | `DEFAULT_CAPACITY` |
| Mobile-scanner tables | — | Created by `includes/mobile-scanner-settings.php` and `camera-portal-schema.php` (scanner pairing/activity) |

> **Deprecated:** `school_year_rollover_maps` (old class→class mapping) is **dropped**
> by `ensureSchoolYearRolloverSchema()`.

### 3.6 Schema evolution mechanism (lazy migrations)

Each helper checks `SHOW COLUMNS` / uses `CREATE TABLE IF NOT EXISTS` and only alters
when needed, so migrations are safe to run on every request and idempotent:

| Helper | Ensures |
|---|---|
| `ensureStudentProfileColumns()` | `students` structured-name + archive columns |
| `ensureSectionCatalogColumns()` | `sections` + `CAPACITY` + `SORT_ORDER`, `section_settings`, column widths, ordering |
| `ensureAttendanceTimeOutColumn()` / `ensureAttendanceSnapshotColumns()` | `attendance` columns incl. `*_SNAPSHOT` |
| `ensureSchoolYearRolloverSchema()` | year settings, promotion plan, history, rollover sections, snapshot backfill |
| `ensureStudentPromotionSchema()` | `student_subject_results`, `student_promotion_decisions` |
| `ensureTeacherProfileColumns()` / `ensureTeacherAssignmentSchema()` | teacher profile + assignments |

### 3.7 Entity relationship sketch

```text
students (STUDENT_ID) ───1:N──▶ attendance (STUDENT_ID, *_SNAPSHOT)
students (STUDENT_ID) ───1:N──▶ student_school_history (STUDENT_ID, SCHOOL_YEAR)
students (STUDENT_ID) ───1:N──▶ student_subject_results (STUDENT_ID, SCHOOL_YEAR, SUBJECT)
students (STUDENT_ID) ───1:N──▶ student_promotion_decisions (STUDENT_ID, SCHOOL_YEAR)

sections (GRADE, STRAND, SECTION, CAPACITY, SORT_ORDER)
   ▲ referenced by name:
   ├── students.SECTION
   ├── teachers.SECTION
   ├── teacher_assignments.SECTION
   └── school_year_rollover_sections.SECTION

school_year_settings (ACTIVE_SCHOOL_YEAR)
   └── drives school_year_promotion_plan, rollover, and snapshot backfill window
```

---

## 4. Core Workflows & Logic

### 4.1 Attendance flow — from a face scan to a record

**Entry points**
- USB webcam: `admin/scan-attendance.php` (UI) → posts frames to `admin/scan-match.php`.
- Android scanner: `api/mobile-scan.php` → sets `MOBILE_API_AUTHENTICATED` → `require admin/scan-match.php`.

**Step-by-step in `admin/scan-match.php`**

1. **Guard + schema.** Webcam path requires `scanner-guard.php`; the mobile path is already authenticated. Ensures student-profile and attendance (incl. snapshot) columns.
2. **Frame intake.** Accepts a JPEG/PNG upload (`$_FILES['frame']`, ≤2 MB, validated with `getimagesize`) or a base64 `data:image/...` POST body (written to a temp file).
3. **Recognition.** `recognizeWithService()` POSTs `{image_path}` to the local Python service `http://127.0.0.1:8772/recognize` (3-second timeout). If unavailable, it auto-starts the service (`recognize_face_service.py`, ~1.5 s warm-up) and retries; a final fallback shells out to `recognize_face.py`.
4. **Enrollment filter.** Recognized IDs are kept **only if** the student exists with `ENROLLMENT_STATUS='Enrolled'` — archived/transferred/dropped/graduated students can never produce a success.
5. **Confirmation de-bounce.** Match distance `> 0.54` is rejected; `≤ 0.43` records immediately; borderline matches require the **same identity in 2 consecutive frames** within a 5–6 second window (`$_SESSION['face_match_confirmations']`).
6. **Action decision.** For each confirmed student, `attendanceNextAutomaticAction()` returns the next event for today's record:
   - Half-day: `time_in` → (cooldown) → `time_out`.
   - Whole-day: `time_in` → `lunch_out` (from 11:40) → `afternoon_time_in` (from 12:20) → `time_out`.
   - A 5-minute **time-out cooldown** protects a fresh time-in.
   - A teacher-marked `Absent` row is deleted when the student subsequently scans in.
7. **Write.** New rows insert with the **placement snapshot**:

   ```sql
   INSERT INTO attendance
     (STUDENT_ID, STUDENT_NAME, TIME_IN, GRADE_SNAPSHOT, STRAND_SNAPSHOT, SECTION_SNAPSHOT)
   VALUES (?, ?, NOW(), ?, ?, ?)
   ```

   Later events `UPDATE` `LUNCH_OUT` / `AFTERNOON_TIME_IN` / `TIME_OUT` on the day's row.
8. **Response.** JSON with `match`, `faces`/boxes, `matches[]` (student, action, message, confidence).

**Timing constants (`includes/attendance-schema.php`)**

| Constant | Value |
|---|---|
| `ATTENDANCE_LATE_FROM` | `07:21:00` |
| `ATTENDANCE_LUNCH_OUT_FROM` | `11:40:00` |
| `ATTENDANCE_AFTERNOON_TIME_IN_FROM` | `12:20:00` |
| `ATTENDANCE_TIME_OUT_COOLDOWN_SECONDS` | `300` |
| `ATTENDANCE_ABSENCE_FINALIZE_AT` | `23:59:00` |

**Other writers of attendance (all write snapshots):**
`admin/log-attendance.php`, `admin/update-attendance-remark.php`,
`teacher/mark-present.php`, `teacher/mark-absent.php`,
`teacher/update-attendance-remark.php`.

**Reads / reporting:**
- `admin/get-latest-attendance.php` — roster by **current** grade/section, but
  **displays** `DISPLAY_GRADE`/`DISPLAY_SECTION` from the snapshot first.
- `admin/export-attendance.php` — roster-driven (`LEFT JOIN` from `students`) so
  students with no record appear as `Not recorded`; filters/displays snapshot-first.

### 4.2 The Rollover Problem (Pre-Fix) — the flaw the panel caught

**Old design:** `school_year_rollover_maps` mapped a **source class (grade + strand +
section)** to exactly **one target section**. The runner (`run-school-year-rollover.php`)
executed a single-target update:

```php
$promote = $conn->prepare(
  "UPDATE students SET GRADE=?, STRAND=?, SECTION=?
   WHERE STUDENT_ID=? AND GRADE=? AND STRAND=? AND SECTION=? AND ENROLLMENT_STATUS='Enrolled'"
);
```

**Consequences the panel flagged:**

1. **"120 students dumped into a single Amethyst section."** Because each source class
   mapped to one section with **no capacity and no spill-over**, an entire cohort could
   land in one room. Grade 8's catalog (`Amethyst`, `Emerald`, `Jade`) went unused
   beyond the first section.
2. **Manual, error-prone mapping ("43-step" process).** The admin had to individually
   choose a destination section for **every source class** in a dropdown table — one
   manual mapping per source class (and per split target). With many classes and no
   automation this was slow and easy to get wrong, and it re-appeared every school year.

**Secondary gaps identified:**
- `sections` had **no `CAPACITY`** and **no explicit ordering**.
- `attendance.*_SNAPSHOT` columns existed but were **written only during rollover** and
  **never read** by the log or export.
- Export was driven by the `attendance` table, so students without a record were
  silently omitted (not a true roster).
- Historical reports shifted when a student changed sections (current placement used
  instead of recorded snapshot).

### 4.3 The Rollover Solution (In Progress) — capacity-aware auto-sectioning

The redesign promotes **by source grade + strand** and auto-chunks students into
sections of **≤ 40**, spilling sequentially and **auto-creating overflow sections**.
This turns the ~40-step manual mapping into a **1-click batch upgrade**
("Promote & auto-section").

#### 4.3.1 Chunker core (`includes/sectioning.php`)

| Function | Behavior |
|---|---|
| `defaultSectionCapacity($conn)` | Reads `section_settings.DEFAULT_CAPACITY` (fallback 40) |
| `sectionCapacity($section, $default)` | Section `CAPACITY` if > 0, else default |
| `orderedTargetSections($conn, $grade, $strand)` | Active sections `ORDER BY SORT_ORDER, SECTION_ID` |
| `generatedSectionName($grade, $strand, $index)` | `Grade 8 - Section 4`, `Grade 11 - Academic - Section 2` |
| `assignStudentsToSections($ids, $sections, $default)` | Walk sections in order, take ≤ capacity each |
| `sectionPlanForBatch(...)` | **Dry-run**: appends *virtual* sections for the preview screen |
| `ensureSectionCapacity($conn, $grade, $strand, $needed, &$sections, $default)` | **Creates real overflow sections** until the batch fits; returns created rows |
| `splitStudentsByWeights($ids, $percents)` | Largest-remainder split (Grade 10 → 11 strand split) |
| `normalisePromotionPlans($posted, $fail)` | Validates/among-strands the plan rows |

Chunking algorithm:

```php
$index = 0; $total = count($studentIds);
foreach ($sections as $section) {                 // ordered by SORT_ORDER
    if ($index >= $total) break;
    $chunk = array_slice($studentIds, $index, sectionCapacity($section, $default));
    if (!$chunk) continue;
    $groups[] = ['section' => $section, 'students' => $chunk];
    $index += count($chunk);
}
```

Overflow creation (names encoded with grade/strand/index for global uniqueness):

```sql
INSERT INTO sections (SECTION, GRADE, STRAND, BASE_SECTION, IS_ACTIVE, CAPACITY, SORT_ORDER)
VALUES (?, ?, ?, '', 1, ?, ?)
ON DUPLICATE KEY UPDATE GRADE=VALUES(GRADE), STRAND=VALUES(STRAND), IS_ACTIVE=1
```

#### 4.3.2 Runner (`admin/run-school-year-rollover.php`) — one transaction

1. Validate **CSRF**, **end-of-year confirmation** (`end_school_year_confirmation=1`), and **administrator password**.
2. Read `ACTIVE_SCHOOL_YEAR`; compute `nextYear` (`2025-2026 → 2026-2027`).
3. Validate the plan; ensure every enrolled source grade/strand has a target.
4. Save the plan to `school_year_promotion_plan`.
5. **Freeze attendance snapshots** before any student moves.
6. Archive pre-rollover placement into `student_school_history` (`INSERT IGNORE`).
7. For each source grade/strand:
   - Order students by `SECTION, LAST_NAME, FIRST_NAME, STUDENT_ID`.
   - Decide from `student_subject_results`: `≥3` failed → **Retained**; `=2` → **Summer class required**; else **Promoted**.
   - Keep **only Promoted** in the batch.
   - For Grade 10 → 11, `splitStudentsByWeights()` splits across `Academic`/`Techpro` per `SPLIT_PERCENT`.
   - `ensureSectionCapacity()` then `assignStudentsToSections()`.
   - `UPDATE students SET GRADE, STRAND, SECTION = ...` **per student** (this is the fix that prevents the one-section dump).
   - Track created sections in `school_year_rollover_sections`.
8. Grade 12: archive + mark `Graduated` for Promoted students.
9. Advance `ACTIVE_SCHOOL_YEAR`.
10. **Commit**; any exception → `rollback()` (no partial changes).
11. Toast includes the **per-section distribution**, e.g. `Amethyst (40), Emerald (40), Jade (40), Grade 8 - Section 4 (10)`.

#### 4.3.3 Expected behavior (documented test matrix)

| Promoted students | Result |
|---|---|
| 40 | 1 section |
| 41 | 40 + 1 |
| 80 | 40 + 40 |
| 81 | 40 + 40 + 1 |
| 120 | 40 + 40 + 40 |
| 130 | 40 + 40 + 40 + 10 (4th section auto-created) |

Worked example (the panel's Amethyst scenario, fixed):

| Order | Section | Students |
|---|---|---|
| 1 | Amethyst | 40 |
| 2 | Emerald | 40 |
| 3 | Jade | 40 |
| 4 | `Grade 8 - Section 4` (auto-created) | 10 |

Grade 10 → 11 split, e.g. 200 students 50/50 → 100 Academic (40/40/20) + 100 Techpro (40/40/20).

#### 4.3.4 Preview, undo, and attendance integration

- **Preview (`school-year-rollover-preview.php`)** is a dry run using the *same* planner
  (`sectionPlanForBatch`), so preview and runner cannot diverge; it shows promoted/summer/
  retained counts and which sections **will be created**.
- **Plan persistence (`save-school-year-rollover-map.php`)** upserts
  `school_year_promotion_plan`, then redirects to the preview.
- **Undo (`undo-school-year-rollover.php`)** restores `GRADE/STRAND/SECTION/ENROLLMENT_STATUS`
  from `student_school_history` for the target year, then **deletes empty auto-created
  sections** (or deactivates if still referenced), clears tracking rows, and rewinds the
  active school year.
- **Attendance integration:** export is roster-driven and snapshot-first; the log is
  snapshot-aware; snapshots are backfilled **only for the active school year**
  (`TIME_IN >= YYYY-06-01`) so older records are never rewritten.
- **Placement validation** accepts catalog sections (`isApprovedStudentSection`), so
  students can be added/edited/imported into auto-created overflow sections.

#### 4.3.5 Remaining gaps to plan for (re-defense)

These are called out in `docs/implementation-changes.md` §9 and are the likely focus of
the remaining backend work:

1. **Grade 10 → 11 split is by percentage, not student preference.** One batch is split
   by an admin-chosen percentage; it is not driven by each student's chosen strand.
2. **Historical roster completeness.** For a past date, a student with *no* record uses
   their *current* placement; if since promoted, they may not appear in an old section
   export (snapshot-covered records remain accurate).
3. **No dedicated admin UI for per-section capacity.** `CAPACITY` and
   `section_settings.DEFAULT_CAPACITY` exist and are edited via Settings, but there is no
   polished per-section capacity editor.
4. **Single-sheet export.** Export is one grouped worksheet, not one worksheet per section.
5. **`students.ENROLLED_SCHOOL_YEAR` intentionally omitted.** The active year + history
   table represent the cohort instead.
6. **Undo does not remove non-empty auto-created sections** (they remain, empty after undo).

---

## 5. UI & State Refactoring

### 5.1 Design system

All chrome is defined in the Tailwind **component layer** (`css/tailwind-input.css`,
`@layer components`) so the admin and teacher portals share one visual language. The
compiled output lives in `css/tailwind.css` (regenerate with `npm run build:css`).

**Component classes**

| Class | Defined as | Used for |
|---|---|---|
| `.btn` | `inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold` | Base button/link |
| `.btn-primary` | `bg-brand-600 text-white` (brand `#1f4f91`) | Primary actions |
| `.btn-secondary` | surface bg + `var(--ui-border)` border + `var(--ui-text)` | Neutral/secondary actions |
| `.btn-danger` | `bg-red-600 hover:bg-red-700` | Destructive actions |
| `.btn-warning` | `bg-amber-500` | Caution |
| `.btn-success` | `bg-emerald-600` | Positive/record/export |
| `.btn-info` | `bg-violet-700` | Informational |
| `.btn-ghost` | text-only | Subtle actions |
| `.btn-sm` | `px-3 py-1.5 text-xs` | Compact/table actions |
| `.btn-lg`, `.btn-icon` | size / icon-only | — |
| `.card`, `.card-header`, `.card-body`, `.card-title`, `.card-muted` | card shell | Panels |
| `.field`, `.field-label` | form controls | Inputs |
| `.table-filter-bar`, `.table-filter-control`, `.table-filter-reset` | filter row | List filters |

**Theming:** `css/theme.css` defines CSS variables for light and dark
(`--ui-surface`, `--ui-border`, `--ui-text`, `--ui-muted`, `--ui-field`) under
`html[data-theme="light|dark"]`, and remaps many Tailwind color utilities for dark mode.
`js/theme.js` toggles and persists the theme.

### 5.2 Button colour semantics (the standard)

| Role | Class / pattern | Examples |
|---|---|---|
| **Primary** | `.btn-primary` (brand blue), `.btn-success` (green) | Add student, Save profile, Export spreadsheet |
| **Secondary / neutral** | `.btn-secondary` (+ subtle tinted variant for tables) | Open profile, Update, Export, Restore, View |
| **Danger / destructive** | `.btn-danger` (`bg-red-600 hover:bg-red-700`) and ghost red `text-red-500 hover:bg-red-500/10 hover:text-red-600` | Sign out, Archive, Delete, Remove, Disable |
| **Caution** | `.btn-warning` (amber) | Record time out, rollover warnings |

Recent hardening (see `docs/changes.md` §11): `.btn-danger` moved from `rose-*` to
`red-*`; sidebar **Sign out** became ghost-red; archive/delete affordances were recoloured
red; scanner "Disable"/"Remove", assignment "Remove", section "Delete", and teacher
"Mark absent" all use red. The **Back to Teachers** link on `admin/edit-teacher.php`
uses `text-slate-400 hover:text-blue-500`.

**Subtle secondary table style** (theme-aware; used for Open profile / Open record /
Restore / Update / Export in tables):

```text
btn btn-secondary inline-flex h-7 items-center justify-center rounded
border border-blue-500/20 bg-blue-500/10 px-2.5 py-0 text-xs font-medium
text-blue-600 transition hover:bg-blue-500/20
```

> `text-blue-600` (not `text-blue-400`) is deliberate: `theme.css` remaps
> `text-blue-600/700/800/900` for dark mode but **not** `text-blue-400`, so blue-600
> stays legible on the light card and lightens automatically in dark mode.

### 5.3 Shell layout & state

- Sidebar is `w-64`, full-bleed `px-0 py-4`; links are `w-full`, `flex items-center gap-4`,
  `px-6 py-4`, with `h-5 w-5 shrink-0` icon wrappers and truncating labels. The active
  link carries `aria-current="page"` and a blue highlight.
- The **Sign out** link is pinned to the bottom (`mt-auto`) with ghost-red styling.
- Top bar is a shared `h-16` `ui_topbar()` (breadcrumbs left, clock + actions right), so
  list pages do **not** repeat their title.
- **Pagination** renders **bottom-only** (`js/table-pagination.js`, `js/student-pagination.js`).
- Inline **"← Back to …"** links sit above the content grid on profile/edit pages
  (`edit-student.php`, `edit-teacher.php`, `archived-*-record.php`, `teacher/student-profile.php`).

### 5.4 Flexbox alignment constraints (attendance REASON/ACTION column)

The attendance log's action cell is deliberately structured so button baselines never
shift between rows:

- `<td>` is `whitespace-nowrap px-4 py-4`.
- Whole cell is wrapped in `flex flex-col items-start justify-center gap-1.5`
  (`items-start` keeps text and buttons flush left).
- Reason text ("No reason added") sits on top in its own span:
  `block max-w-[15rem] truncate text-[11px] leading-none text-slate-400`.
- Buttons live in a dedicated row `flex items-center justify-start gap-2`, with
  **Update first (left anchor)** and **Export appended to the right**.
- Both buttons share **identical geometry** (`h-7`, uniform padding/rounding) so neither
  alters the baseline. Column CSS also enforces `white-space: nowrap` on the cell and
  `flex-wrap: nowrap` on the button row.

### 5.5 Build/state notes

- `css/tailwind.css` is **precompiled**; any *new* raw utility class requires
  `npm run build:css` (Tailwind 3.4.17 scans the `content` globs in `tailwind.config.js`,
  which include `./admin/**/*.php`, `./teacher/**/*.php`, `./js/**/*.js`, etc.).
- Client-side state is minimal: pagination, filter-driven AJAX refresh
  (`js/quiet-attendance-refresh.js`), theme preference, and the scanner session.
- No SPA framework; pages are server-rendered and progressively enhanced with vanilla JS.

---

## Appendix A — File Inventory

**Admin (selected):** `home.php`, `student-list.php`, `add-student.php`, `edit-student.php`,
`save-student.php`, `update-student.php`, `import-students.php`, `import-students-save.php`,
`teacher-list.php`, `add-teacher.php`, `edit-teacher.php`, `save-teacher.php`,
`update-teacher.php`, `delete-teacher.php`, `archived-students.php`,
`archived-student-record.php`, `archived-teachers.php`, `archived-teacher-record.php`,
`archive-student.php`, `restore-archived-student.php`, `delete-student.php`,
`attendance-log.php`, `get-latest-attendance.php`, `update-attendance-remark.php`,
`export-attendance.php`, `log-attendance.php`, `scan-attendance.php`, `scan-match.php`,
`scanner-guard.php`, `manual-attendance-save.php`, `manual-student.php`,
`manual-students-search.php`, `get-stats.php`, `check-status.php`,
`run-automatic-absences.php`, `school-year-rollover.php`,
`school-year-rollover-preview.php`, `save-school-year-rollover-map.php`,
`run-school-year-rollover.php`, `undo-school-year-rollover.php`, `settings.php`,
`settings-save.php`, `activate-mobile-scanner.php`, `disable-mobile-scanner.php`,
`remove-mobile-scanner.php`, `detect-mobile-scanner-server.php`, `mobile-scanner-feed.php`,
`mobile-scanner-preview-status.php`, `biometric-login-match.php`, `auth.php`, `login.php`,
`reset-password.php`, `forgot-password.php`, `page-guard.php`, `components.php`.

**API:** `mobile-login.php`, `mobile-logout.php`, `mobile-session.php`,
`mobile-session-config.php`, `mobile-scan.php`, `mobile-preview.php`, `mobile-app-update.php`.

**Teacher:** `components.php`, `page-guard.php`, `auth.php`, `login.php`, `home.php`,
`students.php`, `student-profile.php`, `get-attendance.php`, `mark-present.php`,
`mark-absent.php`, `update-attendance-remark.php`, `save-subject-result.php`,
`settings.php`, `settings-save.php`, `export-attendance.php`.

**Includes:** `ui.php`, `icons.php`, `csrf-token.php`, `validation.php`,
`sectioning.php`, `section-catalog-schema.php`, `student-placement-options.php`,
`student-profile-schema.php`, `attendance-schema.php`, `school-year-schema.php`,
`student-promotion-schema.php`, `teacher-profile-schema.php`,
`teacher-assignment-schema.php`, `adviser-assignment-schema.php`, `student-import.php`,
`xlsx-export.php`, `biometric-image.php`, `staff-biometric.php`, `camera-portal-schema.php`,
`mobile-scanner-settings.php`, `mobile-scanner-activity.php`, `site-nav.php`,
`default-admin.php`.

**JS:** `js/theme.js`, `js/table-pagination.js`, `js/student-pagination.js`,
`js/quiet-attendance-refresh.js`, `js/password-toggle.js`, `js/script.js`,
`admin/manual-attendance.js`.

**Docs:** `docs/changes.md` (UI refactor), `docs/implementation-changes.md`
(sectioning + attendance accuracy), `docs/sectioning-grade-promotion-plan.md`
(original implementation plan).

---

## Appendix B — Glossary

| Term | Meaning |
|---|---|
| **LRN** | Learner Reference Number (`students.STUDENT_ID`), the student business key |
| **Snapshot** | `GRADE_SNAPSHOT` / `STRAND_SNAPSHOT` / `SECTION_SNAPSHOT` frozen onto an attendance row at record time |
| **Base section** | A named catalog section (`BASE_SECTION <> ''`); auto/overflow sections have `BASE_SECTION = ''` |
| **Overflow section** | Auto-created section (e.g. `Grade 8 - Section 4`) created by `ensureSectionCapacity()` |
| **Chunker** | `assignStudentsToSections()` — slices an ordered ID list into capacity-sized groups |
| **Promotion decision** | `Promoted` / `Summer class required` (`=2` failed) / `Retained` (`≥3` failed) |
| **Rollover** | End-of-year promotion that advances `ACTIVE_SCHOOL_YEAR` and re-sections students |
| **Split (Grade 10 → 11)** | Percentage split of a Grade 10 cohort into `Academic` / `Techpro` |
| **Undo** | Restore placements from `student_school_history` and clean up created sections |

---

## Appendix C — Open Items / Re-Defense Focus

Likely discussion points and remaining backend logic to finalize:

1. **Preference-driven SHS strand split** — move Grade 10 → 11 allocation from a global
   percentage to per-student strand choice (requires a student preference field/table).
2. **Historical roster correctness** — decide whether to materialize a per-year roster
   (current fallback can omit a since-promoted student with no record on a past date).
3. **Per-section capacity management UI** — first-class editor for `sections.CAPACITY`
   and `section_settings.DEFAULT_CAPACITY` with validation.
4. **Multi-sheet export** — generalize `includes/xlsx-export.php` (one worksheet per
   section) if the panel expects per-section rosters.
5. **Undo completeness** — consider cleaning up non-empty auto-created sections or
   flagging them for admin review after undo.
6. **Concurrency / idempotency** — ensure the rollover cannot double-run (currently
   guarded by single-transaction + year advance + plan validation `TO_GRADE = FROM_GRADE + 1`).
7. **Recognition robustness** — the Python service dependency (port 8772) and a
   hard-coded Windows Python path in `scan-match.php` are environment-sensitive and worth
   hardening/configuring during the defense.

---

*End of context.md*
