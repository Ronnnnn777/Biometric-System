<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/attendance-schema.php";
require "../includes/csrf-token.php";
require "../includes/student-placement-options.php";
require "../includes/section-catalog-schema.php";
ensureAttendanceTimeOutColumn($conn);
ensureSectionCatalogColumns($conn);
seedDefaultTeacherSections($conn);
require "components.php";

$sectionResult = $conn->query(
    "SELECT GRADE, SECTION FROM sections WHERE IS_ACTIVE=1 AND GRADE BETWEEN 7 AND 12 UNION SELECT DISTINCT GRADE, SECTION FROM students WHERE GRADE BETWEEN 7 AND 12 AND SECTION IS NOT NULL AND SECTION<>'' ORDER BY GRADE, SECTION",
);
$sections = [];
while ($row = $sectionResult->fetch_assoc()) {
    $sections[] = [
        "grade" => (int) $row["GRADE"],
        "section" => $row["SECTION"],
    ];
}

$gradeResult = $conn->query(
    "SELECT DISTINCT GRADE FROM students WHERE GRADE BETWEEN 7 AND 12 ORDER BY GRADE",
);
$grades = [];
while ($row = $gradeResult->fetch_assoc()) {
    $grades[] = (int) $row["GRADE"];
}
$latestAttendanceResult = $conn->query(
    "SELECT MAX(DATE(TIME_IN)) AS latest_date FROM attendance",
);
$latestAttendance = $latestAttendanceResult
    ? $latestAttendanceResult->fetch_assoc()
    : null;
$defaultAttendanceDate = $latestAttendance["latest_date"] ?? date("Y-m-d");

adminHead("Attendance log");
adminSidebar(
    "attendance",
    "Attendance log",
    "Filter and export attendance records.",
);
?>
<style>
    #attendance-log-table {
        width: 100%;
        table-layout: fixed;
        font-size: .8125rem;
    }

    #attendance-log-table th,
    #attendance-log-table td {
        padding: .75rem .5rem;
        vertical-align: middle;
    }

    #attendance-log-table th {
        line-height: 1.2;
    }

    #attendance-log-table th:nth-child(1) {
        width: 10%;
    }

    #attendance-log-table th:nth-child(2) {
        width: 16%;
    }

    #attendance-log-table th:nth-child(3) {
        width: 8%;
    }

    #attendance-log-table th:nth-child(4) {
        width: 9%;
    }

    #attendance-log-table th:nth-child(5) {
        width: 8%;
    }

    #attendance-log-table th:nth-child(6) {
        width: 7%;
    }

    #attendance-log-table th:nth-child(7) {
        width: 7%;
    }

    #attendance-log-table th:nth-child(8) {
        width: 7%;
    }

    #attendance-log-table th:nth-child(9) {
        width: 7%;
    }

    #attendance-log-table th:nth-child(10) {
        width: 8%;
    }

    #attendance-log-table th:nth-child(11) {
        width: 13%;
    }

    #attendance-log-table .rounded-full {
        padding: .25rem .45rem;
    }

    #attendance-log-table td:nth-child(2) {
        overflow-wrap: anywhere;
    }

    #attendance-log-table td:nth-child(11) .flex {
        flex-wrap: nowrap;
        gap: .75rem;
    }

    #attendance-log-table td:nth-child(11) a,
    #attendance-log-table td:nth-child(11) button {
        white-space: nowrap;
    }

    @media (min-width: 768px) {
        .attendance-log-scroll {
            overflow-x: visible;
        }
    }
</style>
<div class="card p-5">
    <div class="flex flex-col justify-between gap-4 border-b border-slate-100 pb-5 sm:flex-row sm:items-end">
        <p class="text-slate-500">Records update quietly when attendance changes.</p>
        <a id="export-link" href="export-attendance.php?date=<?= htmlspecialchars(
        $defaultAttendanceDate,
    ) ?>&grade=all&section=all"
            class="btn btn-success">Export
            spreadsheet</a>
    </div>
    <div class="table-filter-bar mt-5">
        <div class="flex flex-wrap items-end gap-3">
            <label class="table-filter-control min-w-[10rem] flex-1 text-sm font-bold text-slate-700">Grade<select
                    id="grade-filter"
                    class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3">
                    <option value="all">All grades</option><?php foreach (
          $grades
          as $grade
      ): ?><option value="<?= $grade ?>">Grade <?= $grade ?></option><?php endforeach; ?>
                </select></label>
            <label class="table-filter-control min-w-[10rem] flex-1 text-sm font-bold text-slate-700">Section<select
                    id="section-filter"
                    class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3">
                    <option value="all">All sections</option><?php foreach (
          $sections
          as $section
      ): ?><option value="<?= htmlspecialchars(
    $section["section"],
) ?>" data-grade="<?= $section["grade"] ?>"><?= htmlspecialchars(
    $section["section"],
) ?></option><?php endforeach; ?>
                </select></label>
            <label class="table-filter-control min-w-[10rem] flex-1 text-sm font-bold text-slate-700">Student
                search<input id="student-filter" type="search"
                    class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3"
                    placeholder="Name or LRN"></label>
            <label class="table-filter-control min-w-[10rem] flex-1 text-sm font-bold text-slate-700">Date<input
                    id="date-filter" type="date" value="<?= htmlspecialchars(
          $defaultAttendanceDate,
      ) ?>" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3"></label>
            <button id="clear-attendance-filters" type="button"
                class="table-filter-reset shrink-0">Clear filters</button>
        </div>
    </div>
    <div class="attendance-log-scroll mt-6 overflow-x-auto">
        <table id="attendance-log-table" class="text-left">
            <thead class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500">
                <tr>
                    <th>LRN</th>
                    <th>Name</th>
                    <th>Grade</th>
                    <th>Section</th>
                    <th>Date</th>
                    <th>Morning in</th>
                    <th>Lunch out</th>
                    <th>Afternoon in</th>
                    <th>Final out</th>
                    <th>Status</th>
                    <th>Reason / action</th>
                </tr>
            </thead>
            <tbody id="attendance-rows" class="divide-y divide-slate-100" aria-live="polite"></tbody>
        </table>
    </div>
    <div id="attendance-pagination"></div>
</div>
<div id="attendance-remark-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-5"
    role="dialog" aria-modal="true" aria-labelledby="attendance-remark-title">
    <form action="update-attendance-remark.php" method="post"
        class="card w-full max-w-md p-6 shadow-2xl">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 id="attendance-remark-title" class="text-xl font-black text-slate-900">Attendance reason</h2>
                <p class="mt-1 text-sm text-slate-500">Use this for approved exceptions, including an excuse letter.</p>
            </div><button id="close-attendance-remark" type="button"
                class="rounded-lg p-2 text-slate-400 hover:text-slate-800"
                aria-label="Close"><?= icon('x') ?></button>
        </div><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(
    $_SESSION["csrf_token"],
) ?>"><input id="attendance-remark-id" type="hidden" name="attendance_id"><input id="attendance-remark-student"
            type="hidden" name="student_id"><input id="attendance-remark-date" type="hidden"
            name="attendance_date"><label class="mt-5 block text-sm font-bold text-slate-700">Attendance status<select
                id="attendance-remark-select" name="attendance_remark"
                class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3">
                <option value="">Choose attendance status</option>
                <option value="Excused absence">Excused absence</option>
                <option value="Excused late">Excused late</option>
                <option value="Early dismissal">Early dismissal</option>
                <option value="Emergency dismissal">Emergency dismissal</option>
            </select></label><label class="mt-5 block text-sm font-bold text-slate-700">Short reason / comment<textarea
                id="attendance-note" name="attendance_note" maxlength="255" rows="4"
                class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 font-normal"
                placeholder="Example: Excuse letter for illness."></textarea></label>
        <div class="mt-6 flex gap-3"><button
                class="btn btn-primary flex-1">Save attendance
                note</button><button id="cancel-attendance-remark" type="button"
                class="btn btn-secondary">Cancel</button></div>
    </form>
</div>
<script src="../js/quiet-attendance-refresh.js"></script>
<script src="../js/table-pagination.js?v=6"></script>
<script>
    const gradeFilter = document.getElementById('grade-filter'),
        sectionFilter = document.getElementById('section-filter'),
        studentFilter = document.getElementById('student-filter'),
        dateFilter = document.getElementById('date-filter'),
        rows = document.getElementById('attendance-rows'),
        exportLink = document.getElementById('export-link');
    const attendanceSections = <?= json_encode($sections) ?>;

    function updateAttendanceSections() {
        const grade = gradeFilter.value,
            previous = sectionFilter.value;
        sectionFilter.replaceChildren(new Option('All sections', 'all'));
        attendanceSections.filter(item => grade === 'all' || String(item.grade) === grade).forEach(item => sectionFilter
            .add(new Option(item.section, item.section, false, item.section === previous)));
        if (!Array.from(sectionFilter.options).some(option => option.selected)) sectionFilter.value = 'all';
    }
    gradeFilter.addEventListener('change', updateAttendanceSections);
    const attendancePager = createTablePagination({
        rows,
        pager: document.getElementById('attendance-pagination'),
        label: 'attendance records',
        perPage: 10
    });
    createQuietAttendanceRefresh({
        rows,
        controls: [gradeFilter, sectionFilter, studentFilter, dateFilter],
        exportLink,
        endpoint: 'get-latest-attendance.php',
        getParams: () => new URLSearchParams({
            grade: gradeFilter.value,
            section: sectionFilter.value,
            student: studentFilter.value,
            date: dateFilter.value
        }),
        onRowsUpdated: () => attendancePager.refresh()
    });
    let studentSearchTimer;
    studentFilter.addEventListener('input', () => {
        clearTimeout(studentSearchTimer);
        studentSearchTimer = setTimeout(() => studentFilter.dispatchEvent(new Event('change')), 180);
    });
    document.getElementById('clear-attendance-filters').onclick = () => {
        gradeFilter.value = 'all';
        updateAttendanceSections();
        studentFilter.value = '';
        dateFilter.value = <?= json_encode(
            $defaultAttendanceDate,
        ) ?>;
        [gradeFilter, sectionFilter, studentFilter, dateFilter].forEach(control => control.dispatchEvent(new Event(
            'change')));
    };
    const remarkModal = document.getElementById('attendance-remark-modal'),
        closeRemark = () => remarkModal.classList.replace('flex', 'hidden');
    rows.addEventListener('click', event => {
        const button = event.target.closest('[data-attendance-edit]');
        if (!button) return;
        document.getElementById('attendance-remark-id').value = button.dataset.id;
        document.getElementById('attendance-remark-student').value = button.dataset.studentId || '';
        document.getElementById('attendance-remark-date').value = button.dataset.date || '';
        document.getElementById('attendance-remark-select').value = button.dataset.remark || '';
        document.getElementById('attendance-note').value = button.dataset.note || '';
        remarkModal.classList.replace('hidden', 'flex');
    });
    document.getElementById('close-attendance-remark').onclick = closeRemark;
    document.getElementById('cancel-attendance-remark').onclick = closeRemark;
    remarkModal.addEventListener('click', event => {
        if (event.target === remarkModal) closeRemark()
    });
</script>
<?php adminFoot(); ?>
