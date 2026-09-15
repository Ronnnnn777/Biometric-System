<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/attendance-schema.php';
require '../includes/student-profile-schema.php';
require '../includes/teacher-profile-schema.php';
require '../includes/teacher-assignment-schema.php';
ensureAttendanceTimeOutColumn($conn);
ensureStudentProfileColumns($conn);
ensureTeacherProfileColumns($conn);
ensureTeacherAssignmentSchema($conn);
require 'components.php';

$teacherId = (int) ($_SESSION['teacher-id'] ?? 0);
$assignments = teacherAssignments($conn, $teacherId);
if (!$assignments) {
    http_response_code(403);
    exit('Your teacher account does not have an assigned grade and section.');
}

$sectionResult = $conn->prepare("SELECT DISTINCT s.GRADE, s.SECTION FROM students s INNER JOIN teacher_assignments ta ON ta.TEACHER_ID = ? AND ta.GRADE = s.GRADE WHERE s.SECTION IS NOT NULL AND s.SECTION <> '' AND s.ENROLLMENT_STATUS='Enrolled' ORDER BY s.GRADE, s.SECTION");
$sectionResult->bind_param('i', $teacherId);
$sectionResult->execute();
$sections = [];
$sectionRows = $sectionResult->get_result();
while ($item = $sectionRows->fetch_assoc()) $sections[] = $item;

$studentResult = $conn->prepare("SELECT DISTINCT s.STUDENT_ID, s.STUDENT_NAME, s.GRADE, s.SECTION FROM students s INNER JOIN teacher_assignments ta ON ta.TEACHER_ID = ? AND ta.GRADE = s.GRADE WHERE s.ENROLLMENT_STATUS='Enrolled' ORDER BY s.STUDENT_NAME");
$studentResult->bind_param('i', $teacherId);
$studentResult->execute();
$students = [];
$studentRows = $studentResult->get_result();
while ($student = $studentRows->fetch_assoc()) $students[] = $student;

teacherHead('Attendance');
teacherShell('Attendance records');
?>
<section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
  <div class="flex flex-wrap items-end justify-between gap-4 border-b border-slate-100 pb-5">
    <div><h2 class="text-2xl font-black text-slate-900">Attendance logs</h2><p class="mt-1 text-slate-500">Updates quietly when attendance changes in your assigned grades.</p></div>
    <a id="export-link" href="export-attendance.php?date=<?= date('Y-m-d') ?>&section=all" class="rounded-xl bg-emerald-600 px-4 py-3 text-sm font-bold text-white hover:bg-emerald-700">Export spreadsheet</a>
  </div>
  <div class="mt-5 grid gap-4 lg:grid-cols-3">
    <label class="text-sm font-bold text-slate-700">Grade and section<select id="section-filter" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3"><option value="">All assigned sections</option><?php foreach ($sections as $item): ?><option value="<?= (int) $item['GRADE'] ?>|<?= htmlspecialchars($item['SECTION']) ?>">Grade <?= (int) $item['GRADE'] ?> | <?= htmlspecialchars($item['SECTION']) ?></option><?php endforeach; ?></select></label>
    <label class="text-sm font-bold text-slate-700">Student<select id="student-filter" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3"><option value="">All students</option><?php foreach ($students as $student): ?><option value="<?= htmlspecialchars($student['STUDENT_ID']) ?>"><?= htmlspecialchars($student['STUDENT_NAME'] . ' · ' . $student['STUDENT_ID'] . ' · Grade ' . $student['GRADE'] . ' | ' . $student['SECTION']) ?></option><?php endforeach; ?></select></label>
    <label class="text-sm font-bold text-slate-700">Date<input id="date-filter" type="date" value="<?= date('Y-m-d') ?>" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3"></label>
  </div>
  <div class="mt-6 overflow-x-auto">
    <table class="min-w-full text-left text-sm"><thead class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500"><tr><th class="px-4 py-3">Student ID</th><th class="px-4 py-3">Name</th><th class="px-4 py-3">Grade</th><th class="px-4 py-3">Section</th><th class="px-4 py-3">Date</th><th class="px-4 py-3">Time in</th><th class="px-4 py-3">Time out</th><th class="px-4 py-3">Status</th></tr></thead><tbody id="attendance-rows" class="divide-y divide-slate-100" aria-live="polite"></tbody></table>
  </div>
</section>
<script src="../js/quiet-attendance-refresh.js"></script>
<script>
const section=document.getElementById('section-filter'),student=document.getElementById('student-filter'),date=document.getElementById('date-filter'),rows=document.getElementById('attendance-rows'),exportLink=document.getElementById('export-link');
createQuietAttendanceRefresh({rows,controls:[section,student,date],exportLink,endpoint:'get-attendance.php',getParams:()=>{const selected=section.value.split('|');return new URLSearchParams({section:selected[1]||'all',grade:selected[0]||'',student:student.value,date:date.value});}});
</script>
<?php teacherFoot(); ?>
