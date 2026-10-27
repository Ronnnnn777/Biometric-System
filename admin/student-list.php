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

// One active, ordered catalog supplies PHP options and browser filters.
$sectionCatalog = studentSectionPlacementOptions($conn);
// The sectioning helper designates the first DB-ordered section in each
// grade/strand as Section 1 (honor). This is a section label, not a student award.
$honorSectionMap = [];
foreach ($sectionCatalog as $catalogGrade => $placements) {
    $groups = $catalogGrade >= 11 ? $placements : ['' => $placements];
    foreach ($groups as $catalogStrand => $names) {
        $honorSectionMap[$catalogGrade][$catalogStrand] = $names[0] ?? '';
    }
}

// Archived status remains available only in the archived roster.
$statusItems = [['value' => '', 'label' => 'All Statuses']];
foreach (studentEnrollmentStatuses() as $statusOption) {
    if ($statusOption === 'Archived' && !$isArchivedView) {
        continue;
    }
    $statusItems[] = ['value' => $statusOption, 'label' => $statusOption];
}

$gradeItems = [['value' => '', 'label' => 'All Grades']];
foreach ([7, 8, 9, 10, 11, 12] as $grade) {
    $gradeItems[] = ['value' => (string) $grade, 'label' => 'Grade ' . $grade];
}

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

?>
<div class="card mb-5">
    <div class="card-body space-y-3">
        <!-- Grade is the first step in section filtering. -->
        <div class="min-w-0">
            <p class="filter-label">Grade level</p>
            <div class="filter-pills" role="group" aria-label="Filter by grade"
                data-capsule-group="grade_filter" data-capsule-value=""
                data-capsule-active-class="<?= htmlspecialchars($filterPillActiveClass, ENT_QUOTES) ?>"
                data-capsule-inactive-class="<?= htmlspecialchars($filterPillInactiveClass, ENT_QUOTES) ?>">
                <?php foreach ($gradeItems as $gradeItem): ?>
                <button type="button"
                    class="<?= $gradeItem['value'] === '' ? $filterPillActiveClass : $filterPillInactiveClass ?>"
                    data-capsule-item data-value="<?= htmlspecialchars((string) $gradeItem['value'], ENT_QUOTES) ?>"
                    aria-pressed="<?= $gradeItem['value'] === '' ? 'true' : 'false' ?>"><?= htmlspecialchars($gradeItem['label']) ?></button>
                <?php endforeach; ?>
            </div>
            <select id="grade-filter" class="sr-only" tabindex="-1" aria-hidden="true">
                <option value="">All Grades</option>
                <?php foreach ([7, 8, 9, 10, 11, 12] as $grade): ?>
                <option value="<?= $grade ?>">Grade <?= $grade ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
            <div id="section-filter-container" class="table-filter-control w-full min-w-0 flex-1 sm:min-w-64">
                <label for="section-filter" class="filter-label">Section</label>
                <select id="section-filter" class="field min-h-11 py-2 pr-10 text-base leading-6 [&>option]:text-base disabled:opacity-60" data-always-visible="true" disabled>
                    <option value="">All Sections</option>
                </select>
            </div>
            <div class="table-filter-control w-full min-w-0 flex-1 sm:min-w-64">
                <label for="status-filter" class="filter-label">Enrollment Status</label>
                <select id="status-filter" class="field min-h-11 py-2 pr-10 text-base leading-6 [&>option]:text-base">
                    <?php foreach ($statusItems as $statusItem): ?>
                    <option value="<?= htmlspecialchars($statusItem['value'], ENT_QUOTES) ?>"><?= htmlspecialchars($statusItem['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="strand-filter-container" class="table-filter-control w-full min-w-0 flex-1 sm:min-w-[12rem]" hidden>
                <label for="strand-filter" class="filter-label">Strand</label>
                <select id="strand-filter" class="field h-11" disabled>
                    <option value="">All Strands</option>
                    <?php foreach (array_keys($sectionCatalog[11]) as $strandOption): ?>
                    <option value="<?= htmlspecialchars($strandOption, ENT_QUOTES) ?>"><?= htmlspecialchars($strandOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div class="table-filter-control min-w-0 flex-1">
                <label for="student-search" class="filter-label">Search</label>
                <input type="search" id="student-search" class="field h-11" placeholder="Student name or LRN">
            </div>
            <button type="button" id="clear-student-filters"
                class="btn btn-secondary h-11 w-full shrink-0 px-4 text-sm font-semibold sm:w-auto">Clear Filters</button>
        </div>
    </div>
</div>

<div class="card overflow-hidden">
    <div class="w-full overflow-x-auto">
        <table class="w-full min-w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr>
                    <th class="w-12 px-4 py-3 text-center text-xs font-black uppercase tracking-wider text-slate-400">#</th>
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
                data-honor-sections="<?= htmlspecialchars(json_encode($honorSectionMap), ENT_QUOTES) ?>"
                data-section-catalog="<?= htmlspecialchars(json_encode($sectionCatalog), ENT_QUOTES) ?>"
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
                    data-strand="<?= htmlspecialchars($row['STRAND'] ?? '') ?>"
                    data-status="<?= htmlspecialchars($rowStatus) ?>"
                    data-search="<?= htmlspecialchars($displayName . ' ' . $row['STUDENT_ID'], ENT_QUOTES) ?>">
                    <td class="student-index w-12 px-4 py-3 text-center text-sm font-semibold text-slate-400"></td>
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
<script src="../js/capsule-control.js?v=3"></script>
<script src="../js/student-pagination.js?v=18"></script>
<?php adminFoot(); ?>
