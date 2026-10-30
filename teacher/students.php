<?php
require "page-guard.php";
require_once "../config/db_conn.php";
require_once "../includes/student-profile-schema.php";
require_once "../includes/attendance-schema.php";
require_once "../includes/teacher-profile-schema.php";
require_once "../includes/teacher-assignment-schema.php";
require_once "../includes/school-year-schema.php";
require "../includes/student-promotion-schema.php";
require "../includes/csrf-token.php";
ensureStudentProfileColumns($conn);
ensureAttendanceTimeOutColumn($conn);
ensureTeacherProfileColumns($conn);
ensureTeacherAssignmentSchema($conn);
ensureSchoolYearRolloverSchema($conn);
ensureStudentPromotionSchema($conn);
require "components.php";

$teacherId = (int) ($_SESSION["teacher-id"] ?? 0);
$assignments = teacherAdvisoryAssignments($conn, $teacherId);
if (!$assignments) {
    teacherHead('Students'); teacherShell('Students');
    echo '<div class="mb-6 flex flex-wrap justify-between gap-3"><h2 class="text-2xl font-black">Assigned classes</h2><a href="grading.php" class="btn btn-secondary">Grading</a></div><section class="card p-5"><p class="font-bold">No advisory section assigned for this school year.</p><p class="mt-2 text-sm text-slate-500">Ask the administrator to assign your advisory section for the active school year.</p></section>';
    teacherFoot(); exit;
}

$grades = [];
foreach ($assignments as $assignment) {
    $assignedGrade = (int) $assignment["GRADE"];
    if (!in_array($assignedGrade, $grades, true)) {
        $grades[] = $assignedGrade;
    }
}
sort($grades);

$default = $assignments[0];
$grade = (int) ($_GET["grade"] ?? $default["GRADE"]);
if (!in_array($grade, $grades, true)) {
    $_SESSION['toast'] = [
        'message' => 'That grade is not assigned to your account. Showing your assigned class instead.',
        'type' => 'error',
    ];
    header('Location: students.php?' . http_build_query([
        'grade' => (int) $default['GRADE'],
        'section' => $default['SECTION'],
    ]));
    exit;
}

$sectionResult = $conn->prepare(
    "SELECT ta.GRADE, ta.SECTION, ta.STRAND, COUNT(DISTINCT s.ID) student_count FROM current_teacher_advisory_assignments ta LEFT JOIN students s ON s.GRADE = ta.GRADE AND s.SECTION = ta.SECTION AND s.STRAND=ta.STRAND AND s.ENROLLMENT_STATUS='Enrolled' WHERE ta.TEACHER_ID = ? GROUP BY ta.GRADE, ta.SECTION, ta.STRAND ORDER BY ta.GRADE, ta.SECTION",
);
$sectionResult->bind_param("i", $teacherId);
$sectionResult->execute();
$allSections = [];
$sectionRows = $sectionResult->get_result();
while ($item = $sectionRows->fetch_assoc()) {
    $allSections[] = $item;
}
// The grade pills filter the section cards client-side, so every assigned
// section is rendered and only the active grade's cards are visible initially.
$sections = array_values(array_filter(
    $allSections,
    fn(array $item): bool => (int) $item['GRADE'] === $grade,
));

$section = trim($_GET["section"] ?? "");
$sectionExists = false;
$sectionRow = null;
foreach ($sections as $item) {
    if ($item["SECTION"] === $section) {
        $sectionExists = true;
        $sectionRow = $item;
        break;
    }
}
if (!$sectionExists) {
    $section = $sections[0]["SECTION"] ?? "";
    $sectionRow = $sections[0] ?? null;
}

$assignedStrand = trim((string) ($sectionRow["STRAND"] ?? ""));
$setting = $conn->query('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1')->fetch_assoc();
$schoolYear = (string) ($setting['ACTIVE_SCHOOL_YEAR'] ?? '');

$today = date("Y-m-d");
$stmt = $conn->prepare(
    "SELECT s.*, (SELECT COUNT(*) FROM attendance a WHERE TRIM(a.STUDENT_ID) = TRIM(s.STUDENT_ID) AND DATE(a.TIME_IN) = ?) attended_today, (SELECT a.ATTENDANCE_REMARK FROM attendance a WHERE TRIM(a.STUDENT_ID) = TRIM(s.STUDENT_ID) AND DATE(a.TIME_IN) = ? ORDER BY a.ID DESC LIMIT 1) attendance_remark FROM students s WHERE s.SECTION = ? AND s.GRADE = ? AND s.STRAND=? AND s.ENROLLMENT_STATUS='Enrolled' ORDER BY s.LAST_NAME, s.FIRST_NAME, s.STUDENT_NAME",
);
$stmt->bind_param("sssis", $today, $today, $section, $grade, $assignedStrand);
$stmt->execute();
$studentRows = $stmt->get_result();
$students = [];
while ($student = $studentRows->fetch_assoc()) {
    $students[] = $student;
}

$displayStudentName = function (array $student): string {
    return trim(
        ($student["LAST_NAME"] ?? "") .
            ", " .
            ($student["FIRST_NAME"] ?? "") .
            (!empty($student["MIDDLE_NAME"]) ? ", " . $student["MIDDLE_NAME"] : ""),
        ", ",
    ) ?: ($student["STUDENT_NAME"] ?? "");
};
$gradeItems = [];
foreach ($grades as $assignedGrade) {
    $gradeItems[] = [
        'value' => (string) $assignedGrade,
        // Grades 11 and 12 are labelled as SHS so the selector matches the
        // language used by the school even when both levels are assigned.
        'label' => $assignedGrade >= 11 ? 'SHS ' . $assignedGrade : 'Grade ' . $assignedGrade,
    ];
}
teacherHead("Students");
teacherShell("Students");
?>


<div class="mb-6 flex w-full flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <div>
        <h2 class="text-2xl font-black text-slate-900 dark:text-white">Assigned classes</h2>
        <p class="mt-1 text-sm font-semibold text-slate-500 dark:text-slate-400">Choose an assigned grade and section
            to review the roster and attendance.</p>
    </div>
    <div class="flex flex-wrap gap-2"><a href="grading.php" class="btn btn-primary bg-blue-600">Grading</a><a href="home.php" class="btn btn-secondary shrink-0">Attendance logs</a></div>
</div>

<section class="mb-6 w-full overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
    <div class="flex w-full flex-col gap-4 border-b border-slate-100 pb-5 dark:border-slate-800 xl:flex-row xl:items-center xl:justify-between">
        <div class="min-w-0">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Grade level</p>
            <p class="mt-1 text-sm font-semibold text-slate-500 dark:text-slate-400">Choose a grade to filter the
                section cards, then open a section to review its roster.</p>
        </div>
        <div class="-mx-1 max-w-full overflow-x-auto px-1 pb-1">
            <?= ui_capsule_group('grade_filter', $gradeItems, (string) $grade, [
                'aria_label' => 'Assigned grades',
                'hidden_input' => true,
                'container_class' => 'inline-flex items-center gap-1 rounded-full border border-slate-200 bg-white p-1.5 shadow-sm dark:border-slate-800 dark:bg-slate-900/90',
            ]) ?>
        </div>
    </div>

    <div class="mt-5 flex w-full flex-wrap items-center justify-between gap-2">
        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Current sections</p>
        <p id="section-count-label" aria-live="polite" class="text-xs font-semibold text-slate-400"><?= count($sections) ?>
            section<?= count($sections) === 1 ? '' : 's' ?> assigned</p>
    </div>
    <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 w-full">
        <?php foreach ($allSections as $item):
            $cardGrade = (int) $item['GRADE'];
            $isActive = $cardGrade === $grade && $item['SECTION'] === $section;
            $detail = implode(' · ', array_filter([
                trim((string) ($item['STRAND'] ?? '')),
            ], fn($value) => $value !== ''));
            $studentCount = (int) $item['student_count'];
            $cardHref = 'students.php?' . http_build_query([
                'grade' => $cardGrade,
                'section' => $item['SECTION'],
            ]);
        ?>
        <a href="<?= htmlspecialchars($cardHref) ?>" data-section-card data-section-grade="<?= $cardGrade ?>"
            <?= $cardGrade !== $grade ? 'hidden' : '' ?> <?= $isActive ? 'aria-current="page"' : '' ?>
            class="<?= $isActive
                ? 'rounded-2xl border-2 border-blue-500 bg-blue-600/10 p-5 shadow-sm transition-all cursor-pointer'
                : 'rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 hover:border-slate-400 dark:hover:border-slate-700 transition-all cursor-pointer' ?>">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <span class="block truncate text-base font-black text-slate-900 dark:text-white"><?= htmlspecialchars($item['SECTION']) ?></span>
                    <?php if ($detail !== ''): ?>
                    <span class="mt-1 block truncate text-xs font-semibold text-slate-500 dark:text-slate-400"><?= htmlspecialchars($detail) ?></span>
                    <?php endif; ?>
                </div>
                <span class="shrink-0 whitespace-nowrap px-2.5 py-1 text-xs font-bold rounded-full bg-slate-800 text-slate-300"><?= $studentCount ?>
                    student<?= $studentCount === 1 ? '' : 's' ?></span>
            </div>
        </a>
        <?php endforeach; ?>
        <?php if (!$sections): ?>
        <div class="col-span-full flex w-full flex-col items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center dark:border-slate-700 dark:bg-slate-900/60">
            <span class="grid h-12 w-12 place-items-center rounded-2xl bg-white text-slate-400 shadow-sm dark:bg-slate-800">
                <?= icon('briefcase', 'h-6 w-6') ?>
            </span>
            <p class="mt-4 text-base font-bold text-slate-800 dark:text-slate-200">No assigned sections yet</p>
            <p class="mt-1 text-xs text-slate-500">Ask the administrator to assign a class to your account.</p>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($students): ?>
<div class="flex flex-wrap items-center gap-3 w-full bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 mb-6">
    <label for="student-search" class="sr-only">Search students</label>
    <input id="student-search" type="text"
        class="flex-1 min-w-[240px] px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-sm font-medium focus:ring-2 focus:ring-blue-500 text-slate-900 placeholder:text-slate-400 dark:text-white dark:placeholder:text-slate-500 focus:outline-none"
        placeholder="Search by name or LRN">
    <button id="clear-student-filters" type="button"
        class="btn btn-secondary text-sm font-semibold h-11 px-4">Clear filters</button>
</div>
<?php endif; ?>

<section class="w-full overflow-hidden rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
    <div class="flex w-full flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-5 dark:border-slate-800">
        <div class="min-w-0">
            <h3 class="text-lg font-black text-slate-900 dark:text-white">Grade <?= $grade ?> | <?= htmlspecialchars($section) ?>
                students</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400"><?= count($students) ?>
                student<?= count($students) === 1 ? '' : 's' ?> in this section</p>
            <p id="grade-filter-note" hidden class="mt-2 text-xs font-semibold text-amber-600 dark:text-amber-400"></p>
        </div>
        <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700 dark:bg-blue-500/10 dark:text-blue-300"><?= count($students) ?>
            enrolled</span>
    </div>
    <?php if (!$students): ?>
    <div class="flex w-full flex-col items-center justify-center px-6 py-16 text-center">
        <span class="w-12 h-12 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400">
            <?= icon('users', 'h-5 w-5') ?>
        </span>
        <p class="mt-4 text-base font-bold text-slate-800 dark:text-slate-200">No students in this section yet</p>
        <p class="mt-1 max-w-md text-xs text-slate-500">Select another assigned grade or section above to display
            student records.</p>
    </div>
    <?php else: ?>
    <div class="w-full overflow-x-auto">
        <table class="w-full min-w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-900/60 dark:text-slate-400">
                <tr>
                    <th class="px-5 py-4">Student</th>
                    <th class="px-5 py-4">LRN</th>
                    <th class="px-5 py-4">Grade</th>
                    <th class="px-5 py-4">Section</th>
                    <th class="px-5 py-4">Today</th>
                    <th class="px-5 py-4 text-right">Action</th>
                </tr>
            </thead>
            <tbody id="student-rows" data-page-size="15" class="divide-y divide-slate-100 dark:divide-slate-800"
                data-total="<?= count($students) ?>">
                <?php foreach ($students as $student):
                    $displayName = $displayStudentName($student); ?>
                <tr data-roster-student-id="<?= (int) $student['ID'] ?>" class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                    <td class="px-5 py-4">
                        <div class="flex items-center gap-3"><img src="../<?= htmlspecialchars(
              $student["IMAGE_PATH"],
          ) ?>" alt="" class="h-10 w-10 rounded-full object-cover ring-1 ring-slate-200 dark:ring-slate-700"><span class="font-bold text-slate-900 dark:text-white"><?= htmlspecialchars(
    $displayName,
) ?></span></div>
                    </td>
                    <td class="px-5 py-4 text-slate-600 dark:text-slate-400"><?= htmlspecialchars(
              $student["STUDENT_ID"],
          ) ?></td>
                    <td class="px-5 py-4"><span
                            class="rounded-full bg-violet-50 px-3 py-1 text-xs font-bold text-violet-700 dark:bg-violet-500/10 dark:text-violet-300">Grade <?= htmlspecialchars(
              $student["GRADE"] ?? "—",
          ) ?></span></td>
                    <td class="px-5 py-4"><span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700 dark:bg-blue-500/10 dark:text-blue-300"><?= htmlspecialchars(
              $student["SECTION"],
          ) ?></span></td>
                    <?php
          $todayRemark = $student["attendance_remark"] ?? "";
          $todayLabel = $student["attended_today"]
              ? ($todayRemark === "Absent"
                  ? "Absent"
                  : "Present")
              : "Not recorded";
          $todayClass =
              $todayLabel === "Absent"
                  ? "bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300"
                  : ($todayLabel === "Present"
                      ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300"
                      : "bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300");
          ?>
                    <td class="px-5 py-4"><span
                            class="rounded-full px-3 py-1 text-xs font-bold <?= $todayClass ?>"><?= $todayLabel ?></span></td>
                    <td class="px-5 py-4 text-right"><a
                            class="inline-flex h-8 items-center justify-center rounded-lg border border-blue-500/20 bg-blue-500/10 px-3 text-xs font-semibold text-blue-600 transition hover:bg-blue-500/20 align-middle dark:text-blue-300"
                            href="student-profile.php?id=<?= $student[
    "ID"
] ?>&grade=<?= $grade ?>&section=<?= urlencode(
    $section,
) ?>">Open profile</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<script src="../js/capsule-control.js?v=3"></script>
<script src="../js/student-pagination.js?v=13"></script>
<script>
    const clearFilters = document.getElementById('clear-student-filters');
    const studentSearch = document.getElementById('student-search');
    if (clearFilters && studentSearch) {
        clearFilters.onclick = () => {
            studentSearch.value = '';
            studentSearch.dispatchEvent(new Event('input'));
        };
    }

    // Filter the section cards in place so switching grades never reloads the
    // page or loses the roster the teacher is currently reviewing.
    document.addEventListener('capsule:change', event => {
        if (event.detail.name !== 'grade_filter') return;
        const grade = event.detail.value;
        const rosterGrade = <?= json_encode((string) $grade) ?>;
        const gradeLabel = Number(grade) >= 11 ? `SHS ${grade}` : `Grade ${grade}`;
        let visible = 0;
        document.querySelectorAll('[data-section-card]').forEach(card => {
            const show = card.dataset.sectionGrade === grade;
            card.hidden = !show;
            if (show) visible++;
        });
        const label = document.getElementById('section-count-label');
        if (label) label.textContent = `${visible} section${visible === 1 ? '' : 's'} assigned`;
        const note = document.getElementById('grade-filter-note');
        if (note) {
            const differs = grade !== rosterGrade;
            note.hidden = !differs;
            note.textContent = differs
                ? `Section cards are filtered to ${gradeLabel}. Open a section above to switch the roster.`
                : '';
        }
    });
</script>

<?php teacherFoot(); ?>
