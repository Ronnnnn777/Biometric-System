<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/attendance-schema.php';
require '../includes/csrf-token.php';
require '../includes/student-placement-options.php';
require '../includes/section-catalog-schema.php';
ensureAttendanceTimeOutColumn($conn);
ensureSectionCatalogColumns($conn);
seedDefaultTeacherSections($conn);
require 'components.php';

$sectionResult = $conn->query("SELECT GRADE, SECTION FROM sections WHERE IS_ACTIVE=1 AND GRADE BETWEEN 7 AND 12 UNION SELECT DISTINCT GRADE, SECTION FROM students WHERE GRADE BETWEEN 7 AND 12 AND SECTION IS NOT NULL AND SECTION<>'' ORDER BY GRADE, SECTION");
$sections = [];
while ($row = $sectionResult->fetch_assoc()) $sections[] = ['grade' => (int) $row['GRADE'], 'section' => $row['SECTION']];

$gradeResult = $conn->query('SELECT DISTINCT GRADE FROM students WHERE GRADE BETWEEN 7 AND 12 ORDER BY GRADE');
$grades = [];
while ($row = $gradeResult->fetch_assoc()) $grades[] = (int) $row['GRADE'];
$studentResult = $conn->query('SELECT STUDENT_ID, STUDENT_NAME, GRADE, SECTION FROM students WHERE GRADE BETWEEN 7 AND 12 ORDER BY LAST_NAME, FIRST_NAME, STUDENT_NAME');
$students = [];
while ($row = $studentResult->fetch_assoc()) $students[] = $row;

adminHead('Attendance log');
adminSidebar('attendance', 'Attendance log', 'Filter and export attendance records.');
?>
<div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
  <div class="flex flex-col justify-between gap-4 border-b border-slate-100 pb-5 sm:flex-row sm:items-end">
    <div><h2 class="text-2xl font-black text-slate-900">Attendance log</h2><p class="mt-1 text-slate-500">Records update quietly when attendance changes.</p></div>
    <a id="export-link" href="export-attendance.php?date=<?= date('Y-m-d') ?>&grade=all&section=all" class="rounded-xl bg-emerald-600 px-4 py-3 text-center text-sm font-bold text-white shadow-sm hover:bg-emerald-700">Export spreadsheet</a>
  </div>
  <div class="mt-5 grid gap-4 lg:grid-cols-4">
    <label class="text-sm font-bold text-slate-700">Grade<select id="grade-filter" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3"><option value="all">All grades</option><?php foreach ($grades as $grade): ?><option value="<?= $grade ?>">Grade <?= $grade ?></option><?php endforeach; ?></select></label>
    <label class="text-sm font-bold text-slate-700">Section<select id="section-filter" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3"><option value="all">All sections</option><?php foreach ($sections as $section): ?><option value="<?= htmlspecialchars($section['section']) ?>" data-grade="<?= $section['grade'] ?>"><?= htmlspecialchars($section['section']) ?></option><?php endforeach; ?></select></label>
    <label class="text-sm font-bold text-slate-700">Student<select id="student-filter" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3"><option value="">All students</option><?php foreach ($students as $student): ?><option value="<?= htmlspecialchars($student['STUDENT_ID']) ?>"><?= htmlspecialchars($student['STUDENT_NAME'] . ' · ' . $student['STUDENT_ID']) ?></option><?php endforeach; ?></select></label>
    <label class="text-sm font-bold text-slate-700">Date<input id="date-filter" type="date" value="<?= date('Y-m-d') ?>" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3"></label>
  </div>
  <div id="attendance-pagination-top"></div>
  <div class="mt-6 overflow-x-auto">
    <table class="min-w-full text-left text-sm"><thead class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500"><tr><th class="px-4 py-3">Student ID</th><th class="px-4 py-3">Name</th><th class="px-4 py-3">Grade</th><th class="px-4 py-3">Section</th><th class="px-4 py-3">Date</th><th class="px-4 py-3">Time in</th><th class="px-4 py-3">Time out</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Reason / action</th></tr></thead><tbody id="attendance-rows" class="divide-y divide-slate-100" aria-live="polite"></tbody></table>
  </div>
  <div id="attendance-pagination"></div>
</div>
<div id="attendance-remark-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-5" role="dialog" aria-modal="true" aria-labelledby="attendance-remark-title"><form action="update-attendance-remark.php" method="post" class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"><div class="flex items-start justify-between gap-4"><div><h2 id="attendance-remark-title" class="text-xl font-black text-slate-900">Attendance reason</h2><p class="mt-1 text-sm text-slate-500">Use this only for approved exceptions.</p></div><button id="close-attendance-remark" type="button" class="rounded-lg px-2 text-2xl leading-none text-slate-400 hover:text-slate-800" aria-label="Close">&times;</button></div><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"><input id="attendance-remark-id" type="hidden" name="attendance_id"><label class="mt-5 block text-sm font-bold text-slate-700">Attendance status<select id="attendance-remark-select" name="attendance_remark" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3"><option value="">Choose attendance status</option><option value="Excused late">Excused late</option><option value="Early dismissal">Early dismissal</option></select></label><label class="mt-5 block text-sm font-bold text-slate-700">Short reason / comment<textarea id="attendance-note" name="attendance_note" maxlength="255" rows="4" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 font-normal" placeholder="Example: Family emergency; arrived in the afternoon."></textarea></label><div class="mt-6 flex gap-3"><button class="flex-1 rounded-xl bg-blue-600 px-4 py-3 font-bold text-white hover:bg-blue-700">Save attendance note</button><button id="cancel-attendance-remark" type="button" class="rounded-xl border border-slate-300 px-4 py-3 font-bold text-slate-700">Cancel</button></div></form></div>
<script src="../js/quiet-attendance-refresh.js"></script>
<script src="../js/table-pagination.js?v=5"></script>
<script>
const gradeFilter=document.getElementById('grade-filter'),sectionFilter=document.getElementById('section-filter'),studentFilter=document.getElementById('student-filter'),dateFilter=document.getElementById('date-filter'),rows=document.getElementById('attendance-rows'),exportLink=document.getElementById('export-link');
const attendanceSections=<?= json_encode($sections) ?>;
const attendanceStudents=<?= json_encode($students) ?>;
function updateAttendanceStudents(){const grade=gradeFilter.value,section=sectionFilter.value,previous=studentFilter.value;studentFilter.replaceChildren(new Option('All students',''));attendanceStudents.filter(item=>(grade==='all'||String(item.GRADE)===grade)&&(section==='all'||item.SECTION===section)).forEach(item=>studentFilter.add(new Option(item.STUDENT_NAME+' · '+item.STUDENT_ID,item.STUDENT_ID,false,item.STUDENT_ID===previous)));if(!Array.from(studentFilter.options).some(option=>option.selected))studentFilter.value='';}
function updateAttendanceSections(){const grade=gradeFilter.value,previous=sectionFilter.value;sectionFilter.replaceChildren(new Option('All sections','all'));attendanceSections.filter(item=>grade==='all'||String(item.grade)===grade).forEach(item=>sectionFilter.add(new Option(item.section,item.section,false,item.section===previous)));if(!Array.from(sectionFilter.options).some(option=>option.selected))sectionFilter.value='all';updateAttendanceStudents();}
gradeFilter.addEventListener('change',updateAttendanceSections);sectionFilter.addEventListener('change',updateAttendanceStudents);updateAttendanceStudents();
const attendancePager=createTablePagination({rows,pager:[document.getElementById('attendance-pagination-top'),document.getElementById('attendance-pagination')],label:'attendance records'});
createQuietAttendanceRefresh({rows,controls:[gradeFilter,sectionFilter,studentFilter,dateFilter],exportLink,endpoint:'get-latest-attendance.php',getParams:()=>new URLSearchParams({grade:gradeFilter.value,section:sectionFilter.value,student:studentFilter.value,date:dateFilter.value}),onRowsUpdated:()=>attendancePager.refresh()});
const remarkModal=document.getElementById('attendance-remark-modal'),closeRemark=()=>remarkModal.classList.replace('flex','hidden');rows.addEventListener('click',event=>{const button=event.target.closest('[data-attendance-edit]');if(!button)return;document.getElementById('attendance-remark-id').value=button.dataset.id;document.getElementById('attendance-remark-select').value=button.dataset.remark||'';document.getElementById('attendance-note').value=button.dataset.note||'';remarkModal.classList.replace('hidden','flex');});document.getElementById('close-attendance-remark').onclick=closeRemark;document.getElementById('cancel-attendance-remark').onclick=closeRemark;remarkModal.addEventListener('click',event=>{if(event.target===remarkModal)closeRemark()});
</script>
<?php adminFoot(); ?>
