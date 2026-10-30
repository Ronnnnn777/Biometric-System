<?php
require "page-guard.php";
require_once "../config/db_conn.php";
require_once "../includes/attendance-schema.php";
require "../includes/csrf-token.php";
require_once "../includes/student-profile-schema.php";
require_once "../includes/teacher-profile-schema.php";
require_once "../includes/teacher-assignment-schema.php";
ensureAttendanceTimeOutColumn($conn);
ensureStudentProfileColumns($conn);
ensureTeacherProfileColumns($conn);
ensureTeacherAssignmentSchema($conn);
require "components.php";

$teacherId = (int) ($_SESSION["teacher-id"] ?? 0);
$assignments = teacherAdvisoryAssignments($conn, $teacherId);
if (!$assignments) {
    teacherHead('Students'); teacherShell('Students');
    echo '<div class="mb-6 flex flex-wrap justify-between gap-3"><h2 class="text-2xl font-black">Assigned classes</h2><a href="grading.php" class="btn btn-secondary">Grading</a></div><section class="card p-5"><p class="font-bold">No advisory section assigned for this school year.</p><p class="mt-2 text-sm text-slate-500">Ask the administrator to assign your advisory section for the active school year.</p></section>';
    teacherFoot(); exit;
}

$sectionResult = $conn->prepare(
    "SELECT DISTINCT s.GRADE, s.SECTION FROM students s INNER JOIN current_teacher_advisory_assignments ta ON ta.TEACHER_ID = ? AND ta.GRADE = s.GRADE AND (ta.SECTION=s.SECTION AND ta.STRAND=s.STRAND) WHERE s.SECTION IS NOT NULL AND s.SECTION <> '' AND s.ENROLLMENT_STATUS='Enrolled' ORDER BY s.GRADE, s.SECTION",
);
$sectionResult->bind_param("i", $teacherId);
$sectionResult->execute();
$sections = [];
$sectionRows = $sectionResult->get_result();
while ($item = $sectionRows->fetch_assoc()) {
    $sections[] = $item;
}
$students = [];
// Keep every date control and export link anchored to the school's current date.
$today = date("Y-m-d");

teacherHead("Attendance");
teacherShell("Attendance records");
?>
<section class="card p-5">
    <div class="flex flex-wrap items-end justify-between gap-4 border-b border-slate-100 pb-5">
        <p class="text-slate-500">Updates quietly when attendance changes in your assigned grades. Students who have timed in appear first.</p>
        <a id="export-link" href="export-attendance.php?date=<?= htmlspecialchars(
        $today,
    ) ?>&section=all"
            class="btn btn-success">Export
            spreadsheet</a>
    </div>
    <div class="table-filter-bar mt-5">
        <div class="flex w-full flex-wrap items-end gap-3">
            <label class="table-filter-control flex-1 w-full text-sm font-bold text-slate-700">Grade and
                section<select id="section-filter"
                    class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3">
                    <option value="">All assigned sections</option><?php foreach (
        $sections
        as $item
    ): ?><option value="<?= (int) $item["GRADE"] ?>|<?= htmlspecialchars(
    $item["SECTION"],
) ?>">Grade <?= (int) $item["GRADE"] ?> | <?= htmlspecialchars(
     $item["SECTION"],
 ) ?></option><?php endforeach; ?>
                </select></label>
            <label class="table-filter-control flex-1 w-full text-sm font-bold text-slate-700">Student<select
                    id="student-filter"
                    class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3">
                    <option value="">All students</option><?php foreach (
        $students
        as $student
    ): ?><option value="<?= htmlspecialchars(
    $student["STUDENT_ID"],
) ?>"><?= htmlspecialchars(
    $student["STUDENT_NAME"] .
        " · " .
        $student["STUDENT_ID"] .
        " · Grade " .
        $student["GRADE"] .
        " | " .
        $student["SECTION"],
) ?></option><?php endforeach; ?>
                </select></label>
            <label class="table-filter-control flex-1 w-full text-sm font-bold text-slate-700">Date<input
                    id="date-filter" type="date" value="<?= htmlspecialchars(
        $today,
    ) ?>" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3"></label>
            <button id="clear-attendance-filters" type="button"
                class="table-filter-reset shrink-0">Clear filters</button>
        </div>
    </div>
    <style>
        #teacher-attendance-log-table {
            width: 100%;
            table-layout: fixed;
            font-size: .75rem
        }

        #teacher-attendance-log-table th,
        #teacher-attendance-log-table td {
            padding: .65rem .35rem;
            vertical-align: middle
        }

        #teacher-attendance-log-table th {
            line-height: 1.2
        }

        #teacher-attendance-log-table th:nth-child(1) {
            width: 11%
        }

        #teacher-attendance-log-table th:nth-child(2) {
            width: 13%
        }

        #teacher-attendance-log-table th:nth-child(3) {
            width: 9%
        }

        #teacher-attendance-log-table th:nth-child(4) {
            width: 10%
        }

        #teacher-attendance-log-table th:nth-child(5) {
            width: 8%
        }

        #teacher-attendance-log-table th:nth-child(6) {
            width: 7.5%
        }

        #teacher-attendance-log-table th:nth-child(7) {
            width: 7%
        }

        #teacher-attendance-log-table th:nth-child(8) {
            width: 7.5%
        }

        #teacher-attendance-log-table th:nth-child(9) {
            width: 7%
        }

        #teacher-attendance-log-table th:nth-child(10) {
            width: 8%
        }

        #teacher-attendance-log-table th:nth-child(11) {
            width: 12%
        }

        #teacher-attendance-log-table .rounded-full {
            padding: .25rem .45rem
        }

        #teacher-attendance-log-table td:nth-child(2) {
            overflow-wrap: anywhere
        }

        @media(min-width:768px) {
            .teacher-attendance-log-scroll {
                overflow-x: visible
            }
        }
    </style>
    <div class="teacher-attendance-log-scroll mt-6 w-full overflow-x-auto">
        <table id="teacher-attendance-log-table" class="w-full text-left">
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
            <tbody id="attendance-rows" data-page-size="15" class="divide-y divide-slate-100" aria-live="polite"></tbody>
        </table>
    </div>
    <div id="attendance-pagination"></div>
    <style>
        #teacher-attendance-log-table {
            font-size: .8125rem
        }

        #teacher-attendance-log-table th,
        #teacher-attendance-log-table td {
            padding: .75rem .5rem
        }

        #teacher-attendance-log-table th:nth-child(1) {
            width: 10.5%
        }

        #teacher-attendance-log-table th:nth-child(2) {
            width: 15.5%
        }

        #teacher-attendance-log-table th:nth-child(3) {
            width: 8.5%
        }

        #teacher-attendance-log-table th:nth-child(4) {
            width: 9.5%
        }

        #teacher-attendance-log-table th:nth-child(5) {
            width: 8%
        }

        #teacher-attendance-log-table th:nth-child(6) {
            width: 6.5%
        }

        #teacher-attendance-log-table th:nth-child(7) {
            width: 6.5%
        }

        #teacher-attendance-log-table th:nth-child(8) {
            width: 6.5%
        }

        #teacher-attendance-log-table th:nth-child(9) {
            width: 6.5%
        }

        #teacher-attendance-log-table th:nth-child(10) {
            width: 8.5%
        }

        #teacher-attendance-log-table th:nth-child(11) {
            width: 13.5%
        }
    </style>
    <style>
        #teacher-attendance-log-table th:nth-child(1) {
            width: 10%
        }

        #teacher-attendance-log-table th:nth-child(2) {
            width: 16%
        }

        #teacher-attendance-log-table th:nth-child(3) {
            width: 9%
        }

        #teacher-attendance-log-table th:nth-child(4) {
            width: 10%
        }

        #teacher-attendance-log-table th:nth-child(5) {
            width: 8%
        }

        #teacher-attendance-log-table th:nth-child(6),
        #teacher-attendance-log-table th:nth-child(7),
        #teacher-attendance-log-table th:nth-child(8),
        #teacher-attendance-log-table th:nth-child(9) {
            width: 8%
        }

        #teacher-attendance-log-table th:nth-child(10) {
            width: 8%
        }

        #teacher-attendance-log-table th:nth-child(11) {
            width: 7%
        }
    </style>
</section>
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
<script src="../js/table-pagination.js?v=7"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('clear-attendance-filters').onclick = () => {
            const section = document.getElementById('section-filter'),
                student = document.getElementById('student-filter'),
                date = document.getElementById('date-filter');
            section.value = '';
            student.value = '';
            date.value = <?= json_encode($today) ?>;
            [section, student, date].forEach(control => control.dispatchEvent(new Event('change')));
        };
    });
</script>
<script>
    const legacyStudentFilter = document.getElementById('student-filter');
    if (legacyStudentFilter?.tagName === 'SELECT') {
        const label = legacyStudentFilter.parentElement,
            search = document.createElement('input');
        search.id = 'student-filter';
        search.type = 'search';
        search.autocomplete = 'off';
        search.placeholder = 'Name or LRN';
        search.className = 'mt-2 w-full rounded-xl border border-slate-300 px-4 py-3';
        label.firstChild.nodeValue = 'Search student';
        legacyStudentFilter.replaceWith(search);
    }
</script>
<script>
    const section = document.getElementById('section-filter'),
        student = document.getElementById('student-filter'),
        date = document.getElementById('date-filter'),
        rows = document.getElementById('attendance-rows'),
        exportLink = document.getElementById('export-link');
    const attendancePager = createTablePagination({
        rows,
        pager: document.getElementById('attendance-pagination'),
        label: 'attendance records'
    });
    createQuietAttendanceRefresh({
        rows,
        controls: [section, student, date],
        exportLink,
        endpoint: 'get-attendance.php',
        getParams: () => {
            const selected = section.value.split('|');
            return new URLSearchParams({
                section: selected[1] || 'all',
                grade: selected[0] || '',
                student: student.value,
                date: date.value
            });
        },
        onRowsUpdated: () => attendancePager.refresh()
    });
    let studentSearchTimer;
    student.addEventListener('input', () => {
        clearTimeout(studentSearchTimer);
        studentSearchTimer = setTimeout(() => student.dispatchEvent(new Event('change')), 250)
    });
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
<?php teacherFoot(); ?>
