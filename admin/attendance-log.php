<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/attendance-schema.php";
require "../includes/csrf-token.php";
require "../includes/student-placement-options.php";
require "../includes/section-catalog-schema.php";
require "../includes/attendance-scan-events-schema.php";
require "../includes/school-calendar-schema.php";
ensureAttendanceTimeOutColumn($conn);
ensureSectionCatalogColumns($conn);
ensureAttendanceScanEventsSchema($conn);
ensureSchoolCalendarSchema($conn);
seedDefaultTeacherSections($conn);
require "components.php";

$sectionResult = $conn->query(
    "SELECT GRADE, SECTION FROM sections WHERE IS_ACTIVE=1 AND GRADE BETWEEN 7 AND 12 UNION SELECT DISTINCT GRADE, SECTION FROM students WHERE GRADE BETWEEN 7 AND 12 AND SECTION IS NOT NULL AND SECTION<>'' ORDER BY GRADE, SECTION",
);
$sections = [];
while ($row = $sectionResult->fetch_assoc()) {
    $sections[] = ["grade" => (int) $row["GRADE"], "section" => $row["SECTION"]];
}

$gradeResult = $conn->query(
    "SELECT DISTINCT GRADE FROM students WHERE GRADE BETWEEN 7 AND 12 ORDER BY GRADE",
);
$grades = [];
while ($row = $gradeResult->fetch_assoc()) {
    $grades[] = (int) $row["GRADE"];
}
$limit = 15;
$defaultAttendanceDate = date("Y-m-d");
$previousSchoolDate = previousSchoolDay($conn, $defaultAttendanceDate);
$scanHistoryActivation = attendanceScanEventsActivationDate($conn);

adminHead("Attendance log");
adminSidebar(
    "attendance",
    "Attendance log",
    "Scan activity, daily attendance, and scan history.",
);
?>
<style>
    #live-scans-table,
    #scan-history-table {
        width: 100%;
        table-layout: fixed;
        font-size: .8125rem;
    }

    #live-scans-table th,
    #live-scans-table td,
    #scan-history-table th,
    #scan-history-table td {
        padding: .75rem .5rem;
        vertical-align: middle;
    }

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

    #attendance-log-table th:nth-child(1) { width: 10%; }
    #attendance-log-table th:nth-child(2) { width: 16%; }
    #attendance-log-table th:nth-child(3) { width: 8%; }
    #attendance-log-table th:nth-child(4) { width: 9%; }
    #attendance-log-table th:nth-child(5) { width: 8%; }
    #attendance-log-table th:nth-child(6) { width: 7%; }
    #attendance-log-table th:nth-child(7) { width: 7%; }
    #attendance-log-table th:nth-child(8) { width: 7%; }
    #attendance-log-table th:nth-child(9) { width: 7%; }
    #attendance-log-table th:nth-child(10) { width: 8%; }
    #attendance-log-table th:nth-child(11) { width: 13%; }

    #attendance-log-table .rounded-full {
        padding: .25rem .45rem;
    }

    #attendance-log-table td:nth-child(2) {
        overflow-wrap: anywhere;
    }

    #attendance-log-table td:nth-child(11),
    #attendance-log-table td:nth-child(11) a,
    #attendance-log-table td:nth-child(11) button {
        white-space: nowrap;
    }

    #attendance-log-table td:nth-child(11) > div > div {
        flex-wrap: nowrap;
    }

    .attendance-tab {
        border-radius: .75rem;
        padding: .5rem .85rem;
        font-size: .875rem;
        font-weight: 700;
        color: #475569;
        transition: background-color .15s, color .15s;
    }

    .attendance-tab:hover { background: #f1f5f9; color: #0f172a; }

    .attendance-tab[aria-selected="true"] {
        background: var(--maruhat-blue, #1f4f91);
        color: #fff;
        box-shadow: 0 1px 2px rgb(15 23 42 / .12);
    }

    @keyframes scan-row-flash {
        from { background-color: #dcfce7; }
        to { background-color: transparent; }
    }

    .scan-row-new { animation: scan-row-flash 2.4s ease-out; }
</style>
<div class="w-full flex-1 min-w-0 max-w-full space-y-6">
<div class="card p-5 w-full">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-100 pb-5 w-full">
        <div>
            <p class="text-slate-500">Verified scanner activity, the daily roster, and every scan event in one place.</p>
            <p id="activation-note" class="mt-1 text-xs text-slate-400" <?= $scanHistoryActivation === null ? "hidden" : "" ?>>
                Verified scan events are only recorded from <span id="activation-date"><?= htmlspecialchars(
                    (string) $scanHistoryActivation,
                ) ?></span> onward. Older attendance may have been recorded manually.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a id="history-export" href="export-scan-events.php?date_from=<?= htmlspecialchars(
                $defaultAttendanceDate,
            ) ?>&date_to=<?= htmlspecialchars(
    $defaultAttendanceDate,
) ?>" class="btn btn-secondary btn-sm" hidden>Export scans</a>
            <a id="export-link" href="export-attendance.php?date=<?= htmlspecialchars(
                $defaultAttendanceDate,
            ) ?>&grade=all&section=all" class="btn btn-success">Export daily</a>
        </div>
    </div>

    <div class="mt-5 grid w-full gap-4 sm:grid-cols-3">
        <article class="card card-body">
            <p class="text-sm font-bold text-emerald-700">Scanned today</p>
            <p id="summary-scanned" class="mt-3 text-4xl font-black text-slate-900">—</p>
            <p class="card-muted mt-2">Students with a verified biometric scan</p>
        </article>
        <article class="card card-body">
            <p class="text-sm font-bold text-slate-500">Not scanned</p>
            <p id="summary-not-scanned" class="mt-3 text-4xl font-black text-slate-900">—</p>
            <p id="summary-not-scanned-note" class="card-muted mt-2">Enrolled students without a scan yet</p>
        </article>
        <article class="card card-body">
            <p class="text-sm font-bold text-amber-700">Needs review</p>
            <p id="summary-needs-review" class="mt-3 text-4xl font-black text-slate-900">—</p>
            <p id="summary-needs-review-note" class="card-muted mt-2">Incomplete records and rejected scans</p>
        </article>
    </div>

    <div class="mt-6 flex w-full flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <nav class="flex items-center gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-sm"
            role="tablist" aria-label="Attendance views">
            <button type="button" class="attendance-tab" role="tab" id="tab-live" data-tab="live"
                aria-selected="true" aria-controls="panel-live">Live scans</button>
            <button type="button" class="attendance-tab" role="tab" id="tab-daily" data-tab="daily"
                aria-selected="false" aria-controls="panel-daily">Daily attendance</button>
            <button type="button" class="attendance-tab" role="tab" id="tab-history" data-tab="history"
                aria-selected="false" aria-controls="panel-history">Scan history</button>
        </nav>
        <button id="toggle-advanced" type="button"
            class="self-start rounded-xl border border-slate-300 px-3 py-2 text-sm font-bold text-slate-600 hover:bg-slate-100 sm:self-auto">Advanced
            filters</button>
    </div>

    <div class="table-filter-bar mt-5">
        <div class="flex w-full flex-wrap items-end gap-3">
            <label class="table-filter-control flex-1 w-full text-sm font-bold text-slate-700">Date<input
                    id="date-filter" type="date" value="<?= htmlspecialchars(
                        $defaultAttendanceDate,
                    ) ?>" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3"></label>
            <div class="flex items-end gap-2">
                <button id="today-button" type="button"
                    class="table-filter-reset shrink-0">Today</button>
                <button id="previous-day-button" type="button" data-date="<?= htmlspecialchars(
                    $previousSchoolDate,
                ) ?>" class="table-filter-reset shrink-0">Previous school day</button>
            </div>
            <label class="table-filter-control flex-1 w-full text-sm font-bold text-slate-700">Grade<select
                    id="grade-filter"
                    class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3">
                    <option value="all">All grades</option><?php foreach (
                        $grades
                        as $grade
                    ): ?><option value="<?= $grade ?>">Grade <?= $grade ?></option><?php endforeach; ?>
                </select></label>
            <label id="section-field" hidden
                class="table-filter-control flex-1 w-full text-sm font-bold text-slate-700">Section<select
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
            <label class="table-filter-control flex-1 w-full text-sm font-bold text-slate-700">Student
                search<input id="student-filter" type="search"
                    class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3"
                    placeholder="Name or LRN"></label>
            <button id="clear-attendance-filters" type="button"
                class="table-filter-reset shrink-0">Clear filters</button>
        </div>
        <div id="advanced-filters" class="mt-4 hidden border-t border-slate-200 pt-4"><div class="flex flex-wrap items-end gap-3">
            <label class="table-filter-control flex-1 text-sm font-bold text-slate-700">Attendance
                status<select id="status-filter"
                    class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3">
                    <option value="all">All statuses</option>
                    <option value="recorded">Recorded</option>
                    <option value="not-recorded">Not recorded</option>
                    <option value="Excused absence">Excused absence</option>
                    <option value="Excused late">Excused late</option>
                    <option value="Early dismissal">Early dismissal</option>
                    <option value="Emergency dismissal">Emergency dismissal</option>
                </select></label>
            <label class="table-filter-control flex-1 text-sm font-bold text-slate-700">Scanner
                source<select id="source-filter"
                    class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3">
                    <option value="all">All sources</option>
                    <option value="webcam">Webcam</option>
                    <option value="mobile">Mobile scanner</option>
                </select></label>
            <label class="table-filter-control flex-1 text-sm font-bold text-slate-700">Scan
                result<select id="result-filter"
                    class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3">
                    <option value="all">All results</option>
                    <option value="success">Successful</option>
                    <option value="rejected">Rejected</option>
                    <option value="cooldown">Cooldown</option>
                    <option value="duplicate">Duplicate</option>
                    <option value="ignored">Ignored</option>
                    <option value="unrecognized">Unrecognized</option>
                </select></label>
            <label class="table-filter-control flex-1 text-sm font-bold text-slate-700">History
                from<input id="date-from-filter" type="date" value="<?= htmlspecialchars(
                    $defaultAttendanceDate,
                ) ?>" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3"></label>
            <label class="table-filter-control flex-1 text-sm font-bold text-slate-700">History
                to<input id="date-to-filter" type="date" value="<?= htmlspecialchars(
                    $defaultAttendanceDate,
                ) ?>" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3"></label>
        </div></div>
    </div>

    <section id="panel-live" role="tabpanel" aria-labelledby="tab-live" class="mt-6">
        <div class="attendance-log-scroll w-full overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
            <table id="live-scans-table" class="w-full text-left border-collapse">
                <thead class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500">
                    <tr>
                        <th>Student</th>
                        <th>LRN</th>
                        <th>Grade</th>
                        <th>Section</th>
                        <th>Latest scan</th>
                        <th>Event</th>
                        <th>Status</th>
                        <th>Scanner</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="live-rows" class="divide-y divide-slate-100" aria-live="polite"></tbody>
            </table>
        </div>
        <div id="live-pagination"></div>
    </section>

    <section id="panel-daily" role="tabpanel" aria-labelledby="tab-daily" class="mt-6" hidden>
        <div class="attendance-log-scroll w-full overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
            <table id="attendance-log-table" class="w-full text-left border-collapse">
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
    </section>

    <section id="panel-history" role="tabpanel" aria-labelledby="tab-history" class="mt-6" hidden>
        <p id="history-notice" class="mb-3 hidden rounded-xl bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800"></p>
        <div class="attendance-log-scroll w-full overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
            <table id="scan-history-table" class="w-full text-left border-collapse">
                <thead class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500">
                    <tr>
                        <th>Scan time</th>
                        <th>Student</th>
                        <th>LRN</th>
                        <th>Grade</th>
                        <th>Section</th>
                        <th>Event</th>
                        <th>Result</th>
                        <th>Scanner</th>
                        <th>Detail</th>
                    </tr>
                </thead>
                <tbody id="history-rows" class="divide-y divide-slate-100" aria-live="polite"></tbody>
            </table>
        </div>
        <div id="history-pagination"></div>
    </section>
</div>
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
        </div>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(
            $_SESSION["csrf_token"],
        ) ?>">
        <input id="attendance-remark-id" type="hidden" name="attendance_id">
        <input id="attendance-remark-student" type="hidden" name="student_id">
        <input id="attendance-remark-date" type="hidden" name="attendance_date">
        <div class="mt-5 flex flex-col gap-4">
            <label class="block text-sm font-bold text-slate-700">Attendance status
                <select id="attendance-remark-select" name="attendance_remark"
                    class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3">
                    <option value="">Choose attendance status</option>
                    <option value="Excused absence">Excused absence</option>
                    <option value="Excused late">Excused late</option>
                    <option value="Early dismissal">Early dismissal</option>
                    <option value="Emergency dismissal">Emergency dismissal</option>
                </select>
            </label>
            <label class="block text-sm font-bold text-slate-700">Short reason / comment
                <textarea id="attendance-note" name="attendance_note" maxlength="255" rows="4"
                    class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 font-normal"
                    placeholder="Example: Excuse letter for illness."></textarea>
            </label>
        </div>
        <div class="mt-6 flex gap-3"><button
                class="btn btn-primary flex-1">Save attendance
                note</button><button id="cancel-attendance-remark" type="button"
                class="btn btn-secondary">Cancel</button></div>
    </form>
</div>
<div id="scan-details-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-5"
    role="dialog" aria-modal="true" aria-labelledby="scan-details-title">
    <div class="card w-full max-w-md p-6 shadow-2xl">
        <div class="flex items-start justify-between gap-4">
            <h2 id="scan-details-title" class="text-xl font-black text-slate-900">Scan details</h2>
            <button id="close-scan-details" type="button" class="rounded-lg p-2 text-slate-400 hover:text-slate-800"
                aria-label="Close"><?= icon('x') ?></button>
        </div>
        <dl class="mt-5 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            <dt class="font-bold text-slate-500">Student</dt>
            <dd id="detail-name" class="text-slate-900"></dd>
            <dt class="font-bold text-slate-500">LRN</dt>
            <dd id="detail-lrn" class="text-slate-900"></dd>
            <dt class="font-bold text-slate-500">Grade / Section</dt>
            <dd id="detail-placement" class="text-slate-900"></dd>
            <dt class="font-bold text-slate-500">Event</dt>
            <dd id="detail-event" class="text-slate-900"></dd>
            <dt class="font-bold text-slate-500">Scan time</dt>
            <dd id="detail-time" class="text-slate-900"></dd>
            <dt class="font-bold text-slate-500">Scanner</dt>
            <dd id="detail-source" class="text-slate-900"></dd>
            <dt class="font-bold text-slate-500">Result</dt>
            <dd id="detail-result" class="text-slate-900"></dd>
        </dl>
    </div>
</div>
<script src="../js/attendance-tabs.js?v=1"></script>
<script src="../js/attendance-live-refresh.js?v=1"></script>
<script>
    const gradeFilter = document.getElementById('grade-filter'),
        sectionFilter = document.getElementById('section-filter'),
        sectionField = document.getElementById('section-field'),
        studentFilter = document.getElementById('student-filter'),
        dateFilter = document.getElementById('date-filter');
    const attendanceSections = <?= json_encode($sections) ?>;

    function updateAttendanceSections() {
        const grade = gradeFilter.value,
            previous = sectionFilter.value,
            allGrades = grade === 'all';
        if (sectionField) {
            sectionField.hidden = allGrades;
            sectionField.classList.toggle('hidden', allGrades);
        }
        sectionFilter.replaceChildren(new Option('All sections', 'all'));
        attendanceSections.filter(item => allGrades || String(item.grade) === grade).forEach(item => sectionFilter
            .add(new Option(item.section, item.section, false, item.section === previous)));
        if (!Array.from(sectionFilter.options).some(option => option.selected)) sectionFilter.value = 'all';
    }
    gradeFilter.addEventListener('change', updateAttendanceSections);
    updateAttendanceSections();

    document.addEventListener('DOMContentLoaded', () => {
        window.initAttendanceTabs({
            perPage: <?= $limit ?>,
            today: <?= json_encode($defaultAttendanceDate) ?>
        });
    });

    // Daily attendance reason modal (update-attendance-remark.php).
    const rows = document.getElementById('attendance-rows'),
        remarkModal = document.getElementById('attendance-remark-modal'),
        closeRemark = () => remarkModal.classList.replace('flex', 'hidden');
    document.addEventListener('click', event => {
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
        if (event.target === remarkModal) closeRemark();
    });

    // Scan details modal (Live scans).
    const detailsModal = document.getElementById('scan-details-modal'),
        closeDetails = () => detailsModal.classList.replace('flex', 'hidden');
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-scan-details]');
        if (!button) return;
        document.getElementById('detail-name').textContent = button.dataset.name || '—';
        document.getElementById('detail-lrn').textContent = button.dataset.studentId || '—';
        document.getElementById('detail-placement').textContent =
            'Grade ' + (button.dataset.grade || '—') + ' · ' + (button.dataset.section || '—');
        document.getElementById('detail-event').textContent = button.dataset.event || '—';
        document.getElementById('detail-time').textContent = button.dataset.time || '—';
        document.getElementById('detail-source').textContent = button.dataset.source || '—';
        document.getElementById('detail-result').textContent = button.dataset.result || '—';
        detailsModal.classList.replace('hidden', 'flex');
    });
    document.getElementById('close-scan-details').onclick = closeDetails;
    detailsModal.addEventListener('click', event => {
        if (event.target === detailsModal) closeDetails();
    });
</script>
<?php adminFoot(); ?>
