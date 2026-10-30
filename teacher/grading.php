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
$assignments = teacherAssignments($conn, $teacherId);
if (!$assignments) {
    teacherHead('Grading'); teacherShell('Grading');
    echo '<div class="mb-6 flex flex-wrap justify-between gap-3"><h2 class="text-2xl font-black">Grading</h2><a href="students.php" class="btn btn-secondary">Back to Students</a></div><section class="card p-5"><p class="font-bold">No subject teaching assignments for this school year.</p><p class="mt-2 text-sm text-slate-500">Ask the administrator to assign the subject and class you teach. Advisory ownership does not grant subject grading permission.</p></section>';
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
    header('Location: grading.php?' . http_build_query([
        'grade' => (int) $default['GRADE'],
        'section' => $default['SECTION'],
    ]));
    exit;
}

$sectionResult = $conn->prepare(
    "SELECT ta.GRADE, ta.SECTION, COUNT(DISTINCT s.ID) student_count, MAX(ta.STRAND) STRAND, MAX(ta.SUBJECT) SUBJECT FROM current_teacher_subject_assignments ta LEFT JOIN students s ON s.GRADE = ta.GRADE AND s.SECTION = ta.SECTION AND s.STRAND=ta.STRAND AND s.ENROLLMENT_STATUS='Enrolled' WHERE ta.TEACHER_ID = ? GROUP BY ta.GRADE, ta.SECTION ORDER BY ta.GRADE, ta.SECTION",
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

$assignedSubject = trim((string) ($sectionRow["SUBJECT"] ?? ""));
$assignedStrand = trim((string) ($sectionRow["STRAND"] ?? ""));
$setting = $conn->query('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1')->fetch_assoc();
$schoolYear = (string) ($setting['ACTIVE_SCHOOL_YEAR'] ?? '');

$month = (int) date("n");
$defaultQuarter = $month >= 6 && $month <= 8 ? 1 : ($month >= 9 && $month <= 11 ? 2 : (($month === 12 || $month <= 2) ? 3 : 4));
$activeQuarter = (int) ($_GET["quarter"] ?? $defaultQuarter);
if ($activeQuarter < 1 || $activeQuarter > 4) {
    $activeQuarter = $defaultQuarter;
}

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

$gradeResults = [];
if ($students && $assignedSubject !== '') {
    $resultStmt = $conn->prepare(
        "SELECT ssr.STUDENT_ID,ssr.Q1_GRADE,ssr.Q2_GRADE,ssr.Q3_GRADE,ssr.Q4_GRADE,ssr.FINAL_GRADE,ssr.RESULT
           FROM student_subject_results ssr
           JOIN students s ON TRIM(s.STUDENT_ID)=TRIM(ssr.STUDENT_ID)
          WHERE s.GRADE=? AND s.SECTION=? AND s.ENROLLMENT_STATUS='Enrolled' AND ssr.SCHOOL_YEAR=? AND ssr.SUBJECT=?",
    );
    $resultStmt->bind_param("isss", $grade, $section, $schoolYear, $assignedSubject);
    $resultStmt->execute();
    $resultRows = $resultStmt->get_result();
    while ($resultRow = $resultRows->fetch_assoc()) {
        $gradeResults[trim((string) $resultRow['STUDENT_ID'])] = $resultRow;
    }
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
$displayGradeValue = function ($value): string {
    if ($value === null || $value === '') {
        return '';
    }
    return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
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
$quarterItems = [];
foreach ([1 => 'Q1', 2 => 'Q2', 3 => 'Q3', 4 => 'Q4'] as $quarterValue => $quarterLabel) {
    $quarterItems[] = ['value' => (string) $quarterValue, 'label' => $quarterLabel];
}

teacherHead("Grading");
teacherShell("Grading");
?>
<style>
    #quarterly-grades [data-quarter].is-active-quarter {
        background-color: rgb(219 234 254 / .65);
        box-shadow: inset 0 -2px 0 #2563eb;
    }

    html[data-theme="dark"] #quarterly-grades [data-quarter].is-active-quarter {
        background-color: rgb(30 64 175 / .28);
        box-shadow: inset 0 -2px 0 #60a5fa;
    }

    #quarterly-grades input[type="number"]::-webkit-outer-spin-button,
    #quarterly-grades input[type="number"]::-webkit-inner-spin-button {
        margin: 0;
        -webkit-appearance: none;
    }

    #quarterly-grades input[type="number"] {
        -moz-appearance: textfield;
    }

    #quarterly-grades [data-quarter-input][readonly] {
        background: transparent;
        border-color: transparent;
        color: inherit;
        font-weight: 500;
        cursor: default;
    }

    #quarterly-grades [data-quarter-input]:focus-visible {
        outline: 2px solid #3b82f6;
        outline-offset: 2px;
    }

    #quarterly-grades [data-grade-row][data-dirty] td:first-child {
        box-shadow: inset 3px 0 0 #f59e0b;
    }
</style>
<div class="mb-6 flex flex-wrap items-center justify-between gap-3"><h2 class="text-2xl font-black text-slate-900 dark:text-white">Grading</h2><a href="students.php" class="btn btn-secondary">Back to Students</a></div>
<section class="mb-6 w-full overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
    <div class="flex w-full flex-col gap-4 border-b border-slate-100 pb-5 dark:border-slate-800 xl:flex-row xl:items-center xl:justify-between">
        <div class="min-w-0">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Grade level</p>
            <p class="mt-1 text-sm font-semibold text-slate-500 dark:text-slate-400">Choose a grade to filter the
                section cards, then open a section to review its quarterly grades.</p>
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
                trim((string) ($item['SUBJECT'] ?? '')),
            ], fn($value) => $value !== ''));
            $studentCount = (int) $item['student_count'];
            $cardHref = 'grading.php?' . http_build_query([
                'grade' => $cardGrade,
                'section' => $item['SECTION'],
                'quarter' => $activeQuarter,
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

<div class="mb-6"><label for="student-search" class="sr-only">Search students</label><input id="student-search" placeholder="Search by name or LRN" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900"></div>
<?php if ($students): ?>
<section id="quarterly-grades"
    class="mt-6 w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
    <form action="save-subject-result.php" method="post" class="w-full">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="grade" value="<?= $grade ?>">
        <input type="hidden" name="section" value="<?= htmlspecialchars($section) ?>">
        <input type="hidden" name="subject" value="<?= htmlspecialchars($assignedSubject) ?>">
        <?php foreach ($students as $student): ?>
        <input type="hidden" name="student_ids[]" value="<?= (int) $student['ID'] ?>">
        <?php endforeach; ?>

        <div class="flex w-full flex-col gap-4 border-b border-slate-200 p-5 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <h3 class="text-xl font-black text-slate-900 dark:text-white">Quarterly grades</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    <?= htmlspecialchars($assignedSubject !== '' ? $assignedSubject : 'No subject assigned') ?>
                    &middot; SY <?= htmlspecialchars($schoolYear !== '' ? $schoolYear : '—') ?>
                    &middot; Grade <?= $grade ?> | <?= htmlspecialchars($section) ?>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Quarter</span>
                <div class="-mx-1 max-w-full overflow-x-auto px-1">
                    <?= ui_capsule_group('quarter_period', $quarterItems, (string) $activeQuarter, [
                        'aria_label' => 'Quarter period',
                        'hidden_input' => true,
                        'container_class' => 'inline-flex items-center gap-1 rounded-full border border-slate-200 bg-white p-1.5 shadow-sm dark:border-slate-800 dark:bg-slate-900/90',
                    ]) ?>
                </div>
            </div>
        </div>

        <?php if ($assignedSubject === ''): ?>
        <div class="flex w-full flex-col items-center justify-center px-6 py-14 text-center">
            <p class="text-base font-bold text-slate-800 dark:text-slate-200">No subject assigned to this section</p>
            <p class="mt-1 max-w-md text-xs text-slate-500">Ask the administrator to assign the subject you teach so
                quarterly grades can be recorded.</p>
        </div>
        <?php else: ?>
        <p id="grade-encoding-help" class="px-5 py-3 text-xs text-slate-500 dark:text-slate-400">Edit the selected quarter. Tab moves down; Shift + Tab moves up within this page. An amber row marker means unsaved changes. Save All Grades includes every page.</p>
        <p data-grade-status role="status" aria-live="polite" class="px-5 py-2 text-sm font-semibold text-slate-700 dark:text-slate-300" hidden></p>
        <div class="w-full overflow-x-auto">
            <table class="w-full min-w-[980px] text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-900/60 dark:text-slate-400">
                    <tr>
                        <th class="px-5 py-4">Student</th>
                        <th class="px-5 py-4">LRN</th>
                        <?php foreach ([1 => 'Q1', 2 => 'Q2', 3 => 'Q3', 4 => 'Q4'] as $quarterValue => $quarterLabel): ?>
                        <th class="px-3 py-4 text-center" data-quarter-head="<?= $quarterValue ?>"><?= $quarterLabel ?></th>
                        <?php endforeach; ?>
                        <th class="px-3 py-4 text-center">Final</th>
                        <th class="px-3 py-4 text-center">Remark</th>
                        <th class="px-5 py-4 text-right">Action</th>
                    </tr>
                </thead>
                <tbody id="student-rows" data-page-size="15" class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($students as $student):
                        $displayName = $displayStudentName($student);
                        $studentKey = trim((string) $student['STUDENT_ID']);
                        $result = $gradeResults[$studentKey] ?? [];
                        $quarters = [
                            1 => $result['Q1_GRADE'] ?? null,
                            2 => $result['Q2_GRADE'] ?? null,
                            3 => $result['Q3_GRADE'] ?? null,
                            4 => $result['Q4_GRADE'] ?? null,
                        ];
                        $finalGrade = studentFinalGrade($quarters);
                        $remark = studentResultRemark($finalGrade);
                    ?>
                    <tr data-grade-row data-roster-student-id="<?= (int) $student['ID'] ?>" data-search="<?= htmlspecialchars($displayName . ' ' . $student['STUDENT_ID'], ENT_QUOTES) ?>" data-student-id="<?= (int) $student['ID'] ?>"
                        class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3"><img src="../<?= htmlspecialchars(
              $student["IMAGE_PATH"],
          ) ?>" alt="" class="h-9 w-9 rounded-full object-cover ring-1 ring-slate-200 dark:ring-slate-700"><span class="font-bold text-slate-900 dark:text-white"><?= htmlspecialchars(
    $displayName,
) ?></span></div>
                        </td>
                        <td class="px-5 py-4 text-slate-600 dark:text-slate-400"><?= htmlspecialchars($student['STUDENT_ID']) ?></td>
                        <?php foreach ([1, 2, 3, 4] as $quarterValue): ?>
                        <td class="px-2 py-3 text-center <?= $quarterValue === $activeQuarter ? 'is-active-quarter' : '' ?>"
                            data-quarter="<?= $quarterValue ?>">
                            <input type="number" name="q<?= $quarterValue ?>[<?= (int) $student['ID'] ?>]"
                                value="<?= htmlspecialchars($displayGradeValue($quarters[$quarterValue])) ?>"
                                min="0" max="100" step="0.01" inputmode="decimal" placeholder="—"
                                aria-label="<?= $quarterValue === $activeQuarter ? 'Active quarter: ' : '' ?>Q<?= $quarterValue ?> grade for <?= htmlspecialchars($displayName) ?>"
                                aria-describedby="grade-encoding-help"
                                <?= $quarterValue !== $activeQuarter ? 'readonly tabindex="-1"' : '' ?>
                                data-quarter-input
                                class="w-16 px-2.5 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-center text-sm font-bold focus:ring-2 focus:ring-blue-500">
                        </td>
                        <?php endforeach; ?>
                        <td class="px-3 py-4 text-center text-base font-black text-slate-900 dark:text-white" data-final>
                            <?= $finalGrade === null ? '—' : htmlspecialchars($displayGradeValue($finalGrade)) ?></td>
                        <td class="px-3 py-4 text-center" data-remark>
                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold <?= $remark === 'Passed'
    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
    : ($remark === 'Failed'
        ? 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'
        : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400') ?>"><?= $remark !== '' ? htmlspecialchars($remark) : 'Not graded' ?></span>
                        </td>
                        <td class="px-5 py-4 text-right">
                            <button type="submit" name="save_student" value="<?= (int) $student['ID'] ?>"
                                class="btn btn-primary btn-sm bg-blue-600 hover:bg-blue-700"
                                title="Save grades for <?= htmlspecialchars($displayName) ?>">Save</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="flex w-full flex-col gap-3 border-t border-slate-200 p-5 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-slate-500 dark:text-slate-400">Final is <span
                    class="font-semibold text-slate-700 dark:text-slate-300">(Q1 + Q2 + Q3 + Q4) ÷ 4</span>. While some
                quarters are blank, the average of the quarters entered so far is shown. A final of 75 or higher is
                marked <span class="font-bold text-emerald-700 dark:text-emerald-400">Passed</span>.</p>
            <button type="submit" class="btn btn-primary bg-blue-600 hover:bg-blue-700">Save All Grades</button>
        </div>
        <?php endif; ?>
    </form>
</section>
<?php endif; ?>

<?php if (!$students): ?><p class="p-5 text-slate-500">No students in this assigned class.</p><?php endif; ?>
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

<script>
    (() => {
        const grades = document.getElementById('quarterly-grades');
        if (!grades) return;
        const form = grades.querySelector('form');
        const rows = Array.from(grades.querySelectorAll('[data-grade-row]'));
        const inputs = Array.from(grades.querySelectorAll('[data-quarter-input]'));
        const savedValues = new Map(inputs.map(input => [input, input.value]));
        const status = grades.querySelector('[data-grade-status]');
        let saving = false;

        function markDirty(row) {
            row.toggleAttribute('data-dirty', Array.from(row.querySelectorAll('[data-quarter-input]'))
                .some(input => input.value !== savedValues.get(input)));
        }

        const visibleInputs = () => inputs.filter(input => !input.readOnly && !input.disabled
            && !input.closest('[data-grade-row]').hidden);

        function renderRemark(cell, remark) {
            const tone = remark === 'Passed'
                ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                : (remark === 'Failed'
                    ? 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'
                    : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400');
            cell.innerHTML = `<span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold ${tone}">${remark || 'Not graded'}</span>`;
        }

        function recalculate(row) {
            const inputs = Array.from(row.querySelectorAll('[data-quarter-input]'));
            const values = inputs
                .map(input => input.value.trim() === '' ? null : Number(input.value))
                .filter(value => value !== null && Number.isFinite(value));
            const finalCell = row.querySelector('[data-final]');
            const remarkCell = row.querySelector('[data-remark]');
            if (!finalCell || !remarkCell) return;
            if (!values.length) {
                finalCell.textContent = '—';
                renderRemark(remarkCell, '');
                return;
            }
            const finalGrade = Math.round((values.reduce((total, value) => total + value, 0) / values.length) * 100) / 100;
            finalCell.textContent = finalGrade.toFixed(2);
            renderRemark(remarkCell, finalGrade >= 75 ? 'Passed' : 'Failed');
        }

        function setActiveQuarter(quarter) {
            grades.querySelectorAll('[data-quarter]').forEach(cell => {
                const active = cell.dataset.quarter === String(quarter);
                cell.classList.toggle('is-active-quarter', active);
                const input = cell.querySelector('[data-quarter-input]');
                input.readOnly = !active;
                input.tabIndex = active ? 0 : -1;
                // Keep the student's name while updating the active-quarter label.
                input.setAttribute('aria-label', (active ? 'Active quarter: ' : '')
                    + input.getAttribute('aria-label').replace(/^Active quarter: /, ''));
            });
            grades.querySelectorAll('[data-quarter-head]').forEach(head => {
                const active = head.dataset.quarterHead === String(quarter);
                head.classList.toggle('text-blue-700', active);
                head.classList.toggle('dark:text-blue-400', active);
            });
        }

        grades.addEventListener('input', event => {
            const input = event.target.closest('[data-quarter-input]');
            if (input) {
                const row = input.closest('[data-grade-row]');
                recalculate(row);
                markDirty(row);
            }
        });

        grades.addEventListener('change', event => {
            const input = event.target.closest('[data-quarter-input]');
            if (!input || input.value.trim() === '') return;
            let value = Number(input.value);
            if (!Number.isFinite(value)) {
                input.value = '';
            } else {
                value = Math.min(100, Math.max(0, value));
                input.value = String(Math.round(value * 100) / 100);
            }
            recalculate(input.closest('[data-grade-row]'));
            markDirty(input.closest('[data-grade-row]'));
        });

        grades.addEventListener('keydown', event => {
            const input = event.target.closest('[data-quarter-input]');
            if (!input || input.readOnly || event.isComposing) return;
            if (['ArrowUp', 'ArrowDown', 'Enter'].includes(event.key)) {
                event.preventDefault();
            } else if (event.key === 'Tab' && !event.ctrlKey && !event.altKey && !event.metaKey) {
                const fields = visibleInputs();
                const next = fields[fields.indexOf(input) + (event.shiftKey ? -1 : 1)];
                // At either edge let the browser reach normal controls (including Save).
                if (next) {
                    event.preventDefault();
                    next.focus();
                }
            }
        });

        grades.addEventListener('wheel', event => {
            if (event.target.matches('[data-quarter-input]') && event.target === document.activeElement) {
                event.preventDefault();
            }
        }, { passive: false });

        grades.addEventListener('capsule:change', event => {
            if (event.detail.name !== 'quarter_period') return;
            setActiveQuarter(event.detail.value);
            const url = new URL(location.href);
            url.searchParams.set('quarter', event.detail.value);
            history.replaceState(null, '', url);
            document.querySelectorAll('[data-section-card]').forEach(link => { const target = new URL(link.href); target.searchParams.set('quarter', event.detail.value); link.href = target.href; });
            visibleInputs()[0]?.focus({ preventScroll: true });
        });

        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (saving) return;
            const payload = new FormData(form);
            if (event.submitter?.name) payload.append(event.submitter.name, event.submitter.value);
            // Snapshot values so edits made during a request remain marked unsaved.
            const submittedValues = new Map(inputs.map(input => [input, input.value]));
            saving = true;
            const buttons = Array.from(form.querySelectorAll('button[type="submit"]'));
            buttons.forEach(button => { button.disabled = true; });
            if (status) {
                status.hidden = false;
                status.textContent = 'Saving grades…';
            }
            try {
                const response = await fetch(form.action, {
                    method: 'POST', body: payload, headers: { Accept: 'application/json' },
                });
                if (!response.ok) throw new Error('Save request failed');
                const result = await response.json();
                if (!Array.isArray(result.saved_ids) || typeof result.message !== 'string') {
                    throw new Error('Unexpected save response');
                }
                const savedIds = new Set(result.saved_ids.map(String));
                rows.forEach(row => {
                    if (!savedIds.has(row.dataset.studentId)) return;
                    row.querySelectorAll('[data-quarter-input]').forEach(input => {
                        savedValues.set(input, submittedValues.get(input));
                    });
                    markDirty(row);
                });
                if (status) status.textContent = result.message;
            } catch (error) {
                if (status) status.textContent = 'Could not confirm the save. Your entries are still here. Check your connection or session and try again.';
            } finally {
                saving = false;
                buttons.forEach(button => { button.disabled = false; });
            }
        });

        window.addEventListener('beforeunload', event => {
            if (inputs.some(input => input.value !== savedValues.get(input))) { event.preventDefault(); event.returnValue = ''; }
        });
        grades.querySelectorAll('[data-grade-row]').forEach(recalculate);
        setActiveQuarter(grades.dataset.activeQuarter || document.querySelector('[data-capsule-group="quarter_period"]')?.dataset.capsuleValue || '1');
    })();
</script>
<?php teacherFoot(); ?>
