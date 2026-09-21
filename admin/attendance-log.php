<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/attendance-schema.php';
ensureAttendanceTimeOutColumn($conn);
require 'components.php';

$conn->query('CREATE TABLE IF NOT EXISTS sections (SECTION_ID INT AUTO_INCREMENT PRIMARY KEY, SECTION VARCHAR(80) NOT NULL UNIQUE)');
$sectionResult = $conn->query("SELECT SECTION FROM sections UNION SELECT DISTINCT SECTION FROM students WHERE SECTION IS NOT NULL AND SECTION<>'' UNION SELECT DISTINCT SECTION FROM teachers WHERE SECTION IS NOT NULL AND SECTION<>'' ORDER BY SECTION");
$sections = [];
while ($row = $sectionResult->fetch_assoc()) $sections[] = $row['SECTION'];

$studentResult = $conn->query('SELECT STUDENT_ID, STUDENT_NAME FROM students ORDER BY STUDENT_NAME');
$students = [];
while ($row = $studentResult->fetch_assoc()) $students[] = $row;

adminHead('Attendance log');
adminSidebar('attendance', 'Attendance log', 'Filter and export attendance records.');
?>
<div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
  <div class="flex flex-col justify-between gap-4 border-b border-slate-100 pb-5 sm:flex-row sm:items-end">
    <div><h2 class="text-2xl font-black text-slate-900">Attendance log</h2><p class="mt-1 text-slate-500">Records update quietly when attendance changes.</p></div>
    <a id="export-link" href="export-attendance.php?date=<?= date('Y-m-d') ?>&section=all" class="rounded-xl border border-slate-300 px-4 py-3 text-center text-sm font-bold text-slate-700 hover:bg-slate-50">Export spreadsheet</a>
  </div>
  <div class="mt-5 grid gap-4 lg:grid-cols-3">
    <label class="text-sm font-bold text-slate-700">Section<select id="section-filter" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3"><option value="all">All sections</option><?php foreach ($sections as $section): ?><option value="<?= htmlspecialchars($section) ?>"><?= htmlspecialchars($section) ?></option><?php endforeach; ?></select></label>
    <label class="text-sm font-bold text-slate-700">Student<select id="student-filter" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3"><option value="">All students</option><?php foreach ($students as $student): ?><option value="<?= htmlspecialchars($student['STUDENT_ID']) ?>"><?= htmlspecialchars($student['STUDENT_NAME'] . ' · ' . $student['STUDENT_ID']) ?></option><?php endforeach; ?></select></label>
    <label class="text-sm font-bold text-slate-700">Date<input id="date-filter" type="date" value="<?= date('Y-m-d') ?>" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3"></label>
  </div>
  <div class="mt-6 overflow-x-auto">
    <table class="min-w-full text-left text-sm"><thead class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500"><tr><th class="px-4 py-3">Student ID</th><th class="px-4 py-3">Name</th><th class="px-4 py-3">Grade</th><th class="px-4 py-3">Section</th><th class="px-4 py-3">Date</th><th class="px-4 py-3">Time in</th><th class="px-4 py-3">Time out</th><th class="px-4 py-3">Status</th></tr></thead><tbody id="attendance-rows" class="divide-y divide-slate-100" aria-live="polite"></tbody></table>
  </div>
  <div id="attendance-pagination"></div>
</div>
<script src="../js/quiet-attendance-refresh.js"></script>
<script src="../js/table-pagination.js"></script>
<script>
const sectionFilter=document.getElementById('section-filter'),studentFilter=document.getElementById('student-filter'),dateFilter=document.getElementById('date-filter'),rows=document.getElementById('attendance-rows'),exportLink=document.getElementById('export-link');
const attendancePager=createTablePagination({rows,pager:document.getElementById('attendance-pagination'),label:'attendance records'});
createQuietAttendanceRefresh({rows,controls:[sectionFilter,studentFilter,dateFilter],exportLink,endpoint:'get-latest-attendance.php',getParams:()=>new URLSearchParams({section:sectionFilter.value,student:studentFilter.value,date:dateFilter.value}),onRowsUpdated:()=>attendancePager.refresh()});
</script>
<?php adminFoot(); ?>
