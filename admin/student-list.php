<?php
require '../config/db_conn.php';
require '../includes/student-profile-schema.php';
require '../includes/section-catalog-schema.php';
ensureStudentProfileColumns($conn);
ensureSectionCatalogColumns($conn);
require 'page-guard.php';
require '../includes/csrf-token.php';
require 'components.php';

$limit = 15;
$today = date('Y-m-d');
$view = strtolower(trim((string) ($_GET['view'] ?? 'active')));
if (!in_array($view, ['active', 'archived'], true)) {
    $view = 'active';
}
$isArchivedView = $view === 'archived';

// One page now owns both rosters. The two predicates are deliberately exact so
// the list, the SQL count, and the pager always report the same records.
$archivedStatus = 'Archived';
$archivedStatusSql = $conn->real_escape_string($archivedStatus);
$studentWhere = $isArchivedView
    ? "s.ENROLLMENT_STATUS='" . $archivedStatusSql . "'"
    : "COALESCE(NULLIF(s.ENROLLMENT_STATUS,''),'Enrolled')<>'" . $archivedStatusSql . "'";

$countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM students s WHERE $studentWhere");
$countStmt->execute();
$totalStudents = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);

if ($isArchivedView) {
    $stmt = $conn->prepare(
        "SELECT s.*, (SELECT COUNT(*) FROM attendance a WHERE TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID)) attendance_records
         FROM students s
         WHERE $studentWhere
         ORDER BY s.LAST_NAME, s.FIRST_NAME, s.GRADE, s.SECTION",
    );
} else {
    $stmt = $conn->prepare(
        "SELECT s.*,
            (SELECT COUNT(*) FROM attendance a WHERE TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID) AND DATE(a.TIME_IN)=?) attended_today,
            (SELECT a.ATTENDANCE_REMARK FROM attendance a WHERE TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID) AND DATE(a.TIME_IN)=? ORDER BY a.ID DESC LIMIT 1) attendance_remark
         FROM students s
         WHERE $studentWhere
         ORDER BY s.LAST_NAME, s.FIRST_NAME, s.GRADE, s.SECTION",
    );
    $stmt->bind_param('ss', $today, $today);
}
$stmt->execute();
$result = $stmt->get_result();

$sections = [];
$sectionMap = [];
$sectionResult = $conn->query(
    "SELECT GRADE, SECTION, MIN(SORT_ORDER) AS SORT_ORDER
     FROM (
         SELECT GRADE, SECTION, SORT_ORDER
         FROM sections
         WHERE IS_ACTIVE=1 AND GRADE BETWEEN 7 AND 12
         UNION ALL
         SELECT DISTINCT GRADE, SECTION, 1000 AS SORT_ORDER
         FROM students
         WHERE GRADE BETWEEN 7 AND 12 AND SECTION IS NOT NULL AND SECTION<>''
     ) AS section_catalog
     GROUP BY GRADE, SECTION
     ORDER BY GRADE, SORT_ORDER, SECTION",
);
while ($item = $sectionResult->fetch_assoc()) {
    $sections[] = $item['SECTION'];
    $sectionMap[(string) (int) $item['GRADE']][] = $item['SECTION'];
}

// Hidden <select> options: every status the data can hold. "Archived" is not
// offerable on the active view; on the archived view the select keeps it so
// #status-filter's option values stay exactly as they were.
$statusItems = [['value' => '', 'label' => 'All statuses']];
foreach (studentEnrollmentStatuses() as $statusOption) {
    if ($statusOption === 'Archived' && !$isArchivedView) {
        continue;
    }
    $statusItems[] = ['value' => $statusOption, 'label' => $statusOption];
}

// Visible pills: the archived view is already scoped to archived students, so
// an "Archived" pill is redundant there. Enrolled/Transferred/Dropped/Graduated
// remain meaningful reason sub-filters on both views.
$statusPillItems = array_values(array_filter(
    $statusItems,
    static fn (array $item): bool => $item['value'] !== 'Archived',
));

$gradeItems = [
    ['value' => '', 'label' => 'All'],
    ['type' => 'group', 'items' => [
        ['type' => 'label', 'label' => 'JHS'],
        ['value' => '7', 'label' => 'Grade 7'],
        ['value' => '8', 'label' => 'Grade 8'],
        ['value' => '9', 'label' => 'Grade 9'],
        ['value' => '10', 'label' => 'Grade 10'],
    ]],
    ['type' => 'group', 'items' => [
        ['type' => 'divider'],
        ['type' => 'label', 'label' => 'SHS'],
        ['value' => '11', 'label' => 'Grade 11'],
        ['value' => '12', 'label' => 'Grade 12'],
    ]],
];

$statusClassMap = [
    'Enrolled' => 'bg-emerald-50 text-emerald-700',
    'Transferred' => 'bg-blue-50 text-blue-700',
    'Dropped' => 'bg-rose-50 text-rose-700',
    'Graduated' => 'bg-violet-50 text-violet-700',
    'Archived' => 'bg-amber-50 text-amber-700',
];

$viewPillActive = 'rounded-full bg-blue-600 text-white font-black text-sm px-4 py-2 shadow-md transition-all';
$viewPillInactive = 'rounded-full text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white font-bold text-sm px-4 py-2 transition-all';
$viewToggle = '<nav class="inline-flex flex-wrap items-center gap-1 rounded-full border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-800 dark:bg-slate-900/90" aria-label="Student records view">'
    . '<a href="student-list.php?view=active" class="' . ($isArchivedView ? $viewPillInactive : $viewPillActive) . '"'
    . (!$isArchivedView ? ' aria-current="page"' : '') . '>Active</a>'
    . '<a href="student-list.php?view=archived" class="' . ($isArchivedView ? $viewPillActive : $viewPillInactive) . '"'
    . ($isArchivedView ? ' aria-current="page"' : '') . '>Archived</a>'
    . '</nav>';

adminHead($isArchivedView ? 'Archived students' : 'Students');
adminSidebar(
    'students',
    $isArchivedView ? 'Archived students' : 'Students',
    $isArchivedView ? 'Archived student records' : 'Search profiles and filter by grade or section',
);
?>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <p class="text-sm font-semibold text-slate-500">
            <?= $totalStudents ?> <?= $isArchivedView ? 'archived' : 'active' ?>
            student<?= $totalStudents === 1 ? '' : 's' ?>
        </p>
        <?= $viewToggle ?>
    </div>
    <?php if (!$isArchivedView): ?>
    <div class="flex flex-col gap-3 sm:flex-row">
        <a href="import-students.php" class="btn btn-success"
            aria-label="Import students from a CSV or Excel spreadsheet"><?= icon('upload') ?> Import CSV / Excel</a>
        <a href="add-student.php" class="btn btn-primary shrink-0">Add student</a>
    </div>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/../includes/student-placement-options.php';

// Shared pill class strings: js/capsule-control.js rewrites className on every
// selection change, so the same strings must be mirrored into the group's
// data-capsule-active-class / data-capsule-inactive-class attributes.
$filterPillActiveClass = 'filter-pill filter-pill-active';
$filterPillInactiveClass = 'filter-pill filter-pill-inactive';

// "Top Classes" is presentation logic, not a database table: Tier 1..3 are the
// first three canonical sections for ONE grade (JHS fill order, then SHS
// Academic/Techpro). Because "Tier 1" is a different section per grade, the
// group is intentionally grade-scoped and is disabled while Grade Level = All.
$tierMap = [];
foreach ([7, 8, 9, 10] as $tierGrade) {
    $tierMap[(string) $tierGrade] = studentSectionsForGrade($tierGrade);
}
foreach ([11, 12] as $tierGrade) {
    $tierMap[(string) $tierGrade] = array_slice(array_merge(
        studentSectionsForGrade($tierGrade, 'Academic'),
        studentSectionsForGrade($tierGrade, 'Techpro'),
    ), 0, 3);
}
?>
<div class="card mb-5">
    <div class="card-body space-y-5">
        <!-- Row 1: section + search + reset -->
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div id="section-filter-container" class="table-filter-control w-full shrink-0 sm:w-52">
                <label for="section-filter" class="filter-label">Section</label>
                <select id="section-filter" class="field">
                    <option value="">All sections</option>
                    <?php foreach ($sections as $section): ?>
                    <option><?= htmlspecialchars($section) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="table-filter-control min-w-0 flex-1">
                <label for="student-search" class="filter-label">Search</label>
                <input type="text" id="student-search" class="field" placeholder="Name or LRN">
            </div>

            <button type="button" id="clear-student-filters"
                class="btn btn-secondary h-[42px] w-full shrink-0 px-4 text-sm font-semibold sm:w-auto">Clear
                filters</button>
        </div>

        <div class="filter-separator"></div>

        <!-- Row 2: grade level -->
        <div class="min-w-0">
            <p class="filter-label">Grade level</p>
            <div class="filter-pills" role="group" aria-label="Filter by grade"
                data-capsule-group="grade_filter" data-capsule-value=""
                data-capsule-active-class="<?= htmlspecialchars($filterPillActiveClass, ENT_QUOTES) ?>"
                data-capsule-inactive-class="<?= htmlspecialchars($filterPillInactiveClass, ENT_QUOTES) ?>">
                <?php $gradeGroupCount = 0; ?>
                <?php foreach ($gradeItems as $gradeItem): ?>
                <?php if (($gradeItem['type'] ?? '') === 'group'): ?>
                <?php if ($gradeGroupCount > 0): ?>
                <span class="filter-divider hidden sm:block" aria-hidden="true"></span>
                <?php endif; ?>
                <div class="inline-flex flex-wrap items-center gap-2">
                    <?php foreach ($gradeItem['items'] as $groupItem): ?>
                    <?php if (($groupItem['type'] ?? '') === 'label'): ?>
                    <span class="filter-group-badge"><?= htmlspecialchars($groupItem['label']) ?></span>
                    <?php elseif (($groupItem['type'] ?? '') !== 'divider'): ?>
                    <button type="button" class="<?= $filterPillInactiveClass ?>" data-capsule-item
                        data-value="<?= htmlspecialchars((string) $groupItem['value'], ENT_QUOTES) ?>"
                        aria-pressed="false"><?= htmlspecialchars($groupItem['label']) ?></button>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php $gradeGroupCount++; ?>
                <?php else: ?>
                <button type="button"
                    class="<?= $gradeItem['value'] === '' ? $filterPillActiveClass : $filterPillInactiveClass ?>"
                    data-capsule-item data-value="<?= htmlspecialchars((string) $gradeItem['value'], ENT_QUOTES) ?>"
                    aria-pressed="<?= $gradeItem['value'] === '' ? 'true' : 'false' ?>"><?= htmlspecialchars($gradeItem['label']) ?></button>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <select id="grade-filter" class="sr-only" tabindex="-1" aria-hidden="true">
                <option value="">All grades</option>
                <?php foreach ([7, 8, 9, 10, 11, 12] as $grade): ?>
                <option value="<?= $grade ?>">Grade <?= $grade ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Row 3: status + top classes (fluid, content-width columns) -->
        <div class="flex flex-col gap-6 lg:flex-row lg:items-start">
            <div class="min-w-0">
                <p class="filter-label">Enrollment status</p>
                <?= ui_capsule_group('status_filter', $statusPillItems, '', [
                    'aria_label' => 'Filter by enrollment status',
                    'hidden_input' => false,
                    'container_class' => 'filter-pills',
                    'active_class' => $filterPillActiveClass,
                    'inactive_class' => $filterPillInactiveClass,
                ]) ?>
                <select id="status-filter" class="hidden" tabindex="-1" aria-hidden="true">
                    <?php foreach ($statusItems as $statusItem): ?>
                    <option value="<?= htmlspecialchars($statusItem['value']) ?>"><?= htmlspecialchars($statusItem['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="top-classes-filter" class="filter-block-divider min-w-0"
                data-tier-map="<?= htmlspecialchars(json_encode($tierMap), ENT_QUOTES) ?>">
                <p class="filter-label">Top classes</p>
                <div data-top-classes-pills>
                    <?= ui_capsule_group('top_class_filter', [['value' => '', 'label' => 'All']], '', [
                        'aria_label' => 'Top classes honor section filter',
                        'hidden_input' => false,
                        'container_class' => 'filter-pills',
                        'active_class' => $filterPillActiveClass,
                        'inactive_class' => $filterPillInactiveClass,
                    ]) ?>
                </div>
                <p id="top-classes-note" class="filter-hint hidden" role="status">
                    Select a specific grade to filter by top sections.
                </p>
                <select id="tier-filter" class="hidden" tabindex="-1" aria-hidden="true">
                    <option value="">All tiers</option>
                    <option value="1">Tier 1</option>
                    <option value="2">Tier 2</option>
                    <option value="3">Tier 3</option>
                </select>
            </div>
        </div>
    </div>
</div>

<div class="card overflow-hidden">
    <div class="w-full overflow-x-auto">
        <table class="w-full min-w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr>
                    <th class="px-5 py-4">Student</th>
                    <th class="px-5 py-4">LRN</th>
                    <th class="px-5 py-4">Grade</th>
                    <th class="px-5 py-4">Section</th>
                    <?php if ($isArchivedView): ?>
                    <th class="px-5 py-4">Attendance records</th>
                    <th class="px-5 py-4">Status</th>
                    <?php else: ?>
                    <th class="px-5 py-4">Status</th>
                    <th class="px-5 py-4">Today</th>
                    <?php endif; ?>
                    <th class="px-5 py-4"></th>
                </tr>
            </thead>
            <tbody id="student-rows" class="divide-y divide-slate-100" data-page-size="<?= $limit ?>"
                data-total="<?= $totalStudents ?>"
                data-section-map="<?= htmlspecialchars(json_encode($sectionMap), ENT_QUOTES) ?>"
                <?php if (!$isArchivedView): ?>data-empty-action-url="add-student.php"
                data-empty-title="No students have been added yet."
                data-empty-detail="Add a student or import a class list to begin."<?php else: ?>
                data-empty-title="No archived students."
                data-empty-detail="Profiles archived from the active roster will appear here."<?php endif; ?>>
                <?php while ($row = $result->fetch_assoc()): ?>
                <?php
                    $displayName = trim(($row['LAST_NAME'] ?? '') . ', ' . ($row['FIRST_NAME'] ?? '') . (!empty($row['MIDDLE_NAME']) ? ', ' . $row['MIDDLE_NAME'] : ''), ', ') ?: $row['STUDENT_NAME'];
                    $archiveReason = trim((string) ($row['ARCHIVE_REASON'] ?? ''));
                    if ($isArchivedView) {
                        $rowStatus = match (true) {
                            stripos($archiveReason, 'graduat') !== false => 'Graduated',
                            stripos($archiveReason, 'transfer') !== false => 'Transferred',
                            stripos($archiveReason, 'drop') !== false => 'Dropped',
                            stripos($archiveReason, 'enroll') !== false => 'Enrolled',
                            default => 'Archived',
                        };
                    } else {
                        $rowStatus = (string) ($row['ENROLLMENT_STATUS'] ?? 'Enrolled');
                    }
                    $rowStatusClass = $statusClassMap[$rowStatus] ?? 'bg-slate-100 text-slate-700';
                ?>
                <tr class="hover:bg-slate-50" data-grade="<?= (int) $row['GRADE'] ?>"
                    data-section="<?= htmlspecialchars($row['SECTION'] ?? '') ?>"
                    data-status="<?= htmlspecialchars($rowStatus) ?>">
                    <td class="px-5 py-4">
                        <div class="flex items-center gap-3"><img src="../<?= htmlspecialchars($row['IMAGE_PATH']) ?>"
                                alt="" class="h-10 w-10 rounded-full object-cover"><span
                                class="font-bold text-slate-900"><?= htmlspecialchars($displayName) ?></span></div>
                    </td>
                    <td class="px-5 py-4 text-slate-600"><?= htmlspecialchars($row['STUDENT_ID']) ?></td>
                    <td class="px-5 py-4"><span
                            class="rounded-full bg-violet-50 px-3 py-1 font-bold text-violet-700">Grade
                            <?= htmlspecialchars($row['GRADE'] ?? '—') ?></span></td>
                    <td class="px-5 py-4"><span
                            class="rounded-full bg-blue-50 px-3 py-1 font-bold text-blue-700"><?= htmlspecialchars($row['SECTION'] ?? 'N/A') ?></span>
                    </td>
                    <?php if ($isArchivedView): ?>
                    <td class="px-5 py-4 font-bold text-slate-700"><?= (int) $row['attendance_records'] ?></td>
                    <td class="px-5 py-4"><span class="rounded-full px-3 py-1 font-bold <?= $rowStatusClass ?>"
                            title="<?= htmlspecialchars($archiveReason ?: 'Archived') ?>"><?= htmlspecialchars($rowStatus) ?></span>
                    </td>
                    <td class="px-5 py-4 text-right">
                        <div class="flex flex-wrap justify-end gap-2">
                            <a class="btn btn-secondary inline-flex h-7 items-center justify-center rounded border border-blue-500/20 bg-blue-500/10 px-2.5 py-0 text-xs font-medium text-blue-600 transition hover:bg-blue-500/20"
                                href="archived-student-record.php?id=<?= (int) $row['ID'] ?>">Open record</a>
                            <form action="restore-archived-student.php" method="post" class="inline">
                                <input type="hidden" name="csrf_token"
                                    value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="id" value="<?= (int) $row['ID'] ?>">
                                <button
                                    class="btn btn-secondary inline-flex h-7 items-center justify-center rounded border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-0 text-xs font-medium text-emerald-700 transition hover:bg-emerald-500/20">Restore</button>
                            </form>
                        </div>
                    </td>
                    <?php else: ?>
                    <td class="px-5 py-4"><span
                            class="rounded-full px-3 py-1 font-bold <?= $rowStatusClass ?>"><?= htmlspecialchars($rowStatus) ?></span>
                    </td>
                    <?php
                        $todayLabel = ($row['attendance_remark'] ?? '') === 'Absent' ? 'Absent' : ($row['attended_today'] ? 'Present' : 'Not recorded');
                        $todayClass = $todayLabel === 'Absent' ? 'bg-rose-50 text-rose-700' : ($todayLabel === 'Present' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700');
                    ?>
                    <td class="px-5 py-4"><span
                            class="rounded-full px-3 py-1 font-bold <?= $todayClass ?>"><?= $todayLabel ?></span></td>
                    <td class="px-5 py-4 text-right"><a
                            class="btn btn-secondary inline-flex h-7 items-center justify-center rounded border border-blue-500/20 bg-blue-500/10 px-2.5 py-0 text-xs font-medium text-blue-600 transition hover:bg-blue-500/20"
                            href="edit-student.php?id=<?= (int) $row['ID'] ?>">Open profile</a></td>
                    <?php endif; ?>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
    window.studentSectionMap = <?= json_encode($sectionMap) ?>;
</script>
<script src="../js/capsule-control.js?v=3"></script>
<script src="../js/student-pagination.js?v=12"></script>
<script>
    // student-pagination.js follows a "conditional Section filter" rule: it hides
    // the Section control until a grade is chosen. This roster keeps the control
    // available at all times (it can filter by any section), so re-show it after
    // the shared controller runs and after every grade change. Kept here rather
    // than in the shared file so teacher/students.php is unaffected.
    (() => {
        const sectionField = document.getElementById('section-filter-container');
        const gradeSelect = document.getElementById('grade-filter');
        if (!sectionField) return;
        const keepSectionVisible = () => {
            sectionField.hidden = false;
            sectionField.classList.remove('hidden');
        };
        keepSectionVisible();
        gradeSelect?.addEventListener('change', keepSectionVisible);
    })();
</script>
<script>
    (() => {
        document.getElementById('clear-student-filters').onclick = () => {
            const search = document.getElementById('student-search'),
                grade = document.getElementById('grade-filter'),
                section = document.getElementById('section-filter'),
                status = document.getElementById('status-filter'),
                tier = document.getElementById('tier-filter');
            search.value = '';
            // Reset the visible pills without firing duplicate capsule events;
            // the mirrored selects below are the source of truth for filtering.
            window.CapsuleControl?.setValue('grade_filter', '', { silent: true });
            window.CapsuleControl?.setValue('status_filter', '', { silent: true });
            window.CapsuleControl?.setValue('top_class_filter', '', { silent: true });
            grade.value = '';
            grade.dispatchEvent(new Event('change'));
            status.value = '';
            status.dispatchEvent(new Event('change'));
            section.value = '';
            section.dispatchEvent(new Event('change'));
            if (tier) tier.value = '';
            search.dispatchEvent(new Event('input'));
        };
    })();
</script>
<script>
    (() => {
        const gradeSelect = document.getElementById('grade-filter');
        const sectionSelect = document.getElementById('section-filter');
        const tierSelect = document.getElementById('tier-filter');
        const topClassesBlock = document.getElementById('top-classes-filter');
        const topClassesGroup = document.querySelector('[data-capsule-group="top_class_filter"]');
        const topClassesPills = document.querySelector('[data-top-classes-pills]');
        const topClassesNote = document.getElementById('top-classes-note');
        if (!gradeSelect || !sectionSelect || !topClassesGroup) return;

        const sectionMap = window.studentSectionMap || {};
        let tierMap = {};
        try {
            tierMap = JSON.parse(topClassesBlock?.dataset.tierMap || '{}');
        } catch (_) {
            tierMap = {};
        }
        let topSections = [];

        const syncTopClassSelection = () => {
            const selected = sectionSelect.value || '';
            const index = topSections.indexOf(selected);
            const activeValue = index >= 0 ? selected : '';
            window.CapsuleControl?.setValue('top_class_filter', activeValue, { silent: true });
            // The tier select mirrors the selected honor-section rank; the
            // section select remains the filtering source of truth.
            if (tierSelect) tierSelect.value = index >= 0 ? String(index + 1) : '';
        };

        // Tier pills only make sense for a single grade (Tier 1 is a different
        // section per grade), so the group is muted with a helper note while
        // Grade Level = "All" instead of rendering a meaningless lone "All" pill.
        const setTopClassesEnabled = (enabled) => {
            topClassesPills?.classList.toggle('hidden', !enabled);
            topClassesNote?.classList.toggle('hidden', enabled);
            topClassesBlock?.setAttribute('aria-disabled', String(!enabled));
        };

        const renderTopClasses = () => {
            const grade = gradeSelect.value || '';
            // Prefer the canonical Tier 1..3 list computed in PHP; fall back to
            // the DB-derived section map if a grade is missing from it.
            const canonical = grade ? (tierMap[grade] || []) : [];
            const fallback = grade ? (sectionMap[grade] || []) : [];
            topSections = (canonical.length ? canonical : fallback).slice(0, 3);
            setTopClassesEnabled(Boolean(grade));

            const inactiveClass = topClassesGroup.dataset.capsuleInactiveClass || '';
            const items = [
                { value: '', label: 'All' },
                ...topSections.map((section, index) => ({
                    value: section,
                    label: `Tier ${index + 1} · ${section}`,
                })),
            ];

            topClassesGroup.replaceChildren(...items.map(item => {
                const button = document.createElement('button');
                button.type = 'button';
                button.dataset.capsuleItem = '';
                button.dataset.value = item.value;
                button.setAttribute('aria-pressed', 'false');
                button.className = inactiveClass;
                button.textContent = item.label;
                return button;
            }));

            syncTopClassSelection();
        };

        gradeSelect.addEventListener('change', renderTopClasses);
        sectionSelect.addEventListener('change', syncTopClassSelection);

        document.addEventListener('capsule:change', event => {
            if (event.detail.name !== 'top_class_filter') return;
            if (sectionSelect.value === event.detail.value) return;
            sectionSelect.value = event.detail.value;
            sectionSelect.dispatchEvent(new Event('change'));
        });

        renderTopClasses();
    })();
</script>
<?php adminFoot(); ?>
