<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/student-profile-schema.php';
require '../includes/teacher-profile-schema.php';
require '../includes/teacher-assignment-schema.php';
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

$grades = [];
foreach ($assignments as $assignment) {
    $assignedGrade = (int) $assignment['GRADE'];
    if (!in_array($assignedGrade, $grades, true)) $grades[] = $assignedGrade;
}

$default = $assignments[0];
$grade = (int) ($_GET['grade'] ?? $default['GRADE']);
if (!in_array($grade, $grades, true)) {
    http_response_code(404);
    exit('That grade is not assigned to your account.');
}

$sectionResult = $conn->prepare("SELECT ta.GRADE, COALESCE(s.SECTION, ta.SECTION) SECTION, COUNT(DISTINCT s.ID) student_count, MAX(ta.STRAND) STRAND, MAX(ta.SUBJECT) SUBJECT FROM teacher_assignments ta LEFT JOIN students s ON s.GRADE = ta.GRADE AND s.SECTION IS NOT NULL AND s.SECTION <> '' AND s.ENROLLMENT_STATUS='Enrolled' WHERE ta.TEACHER_ID = ? AND ta.GRADE = ? GROUP BY ta.GRADE, COALESCE(s.SECTION, ta.SECTION) ORDER BY SECTION");
$sectionResult->bind_param('ii', $teacherId, $grade);
$sectionResult->execute();
$sections = [];
$sectionRows = $sectionResult->get_result();
while ($item = $sectionRows->fetch_assoc()) $sections[] = $item;

$section = trim($_GET['section'] ?? '');
$sectionExists = false;
foreach ($sections as $item) {
    if ($item['SECTION'] === $section) {
        $sectionExists = true;
        break;
    }
}
if (!$sectionExists) $section = $sections[0]['SECTION'] ?? '';

$today = date('Y-m-d');
$stmt = $conn->prepare("SELECT s.*, (SELECT COUNT(*) FROM attendance a WHERE TRIM(a.STUDENT_ID) = TRIM(s.STUDENT_ID) AND DATE(a.TIME_IN) = ?) attended_today FROM students s WHERE s.SECTION = ? AND s.GRADE = ? AND s.ENROLLMENT_STATUS='Enrolled' ORDER BY s.LAST_NAME, s.FIRST_NAME, s.STUDENT_NAME");
$stmt->bind_param('ssi', $today, $section, $grade);
$stmt->execute();
$students = $stmt->get_result();
teacherHead('Students');
teacherShell('Students');
?>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
  <div>
    <h2 class="text-2xl font-black text-slate-900">Students</h2>
    <p class="mt-1 text-slate-500">Choose an assigned grade and section to view its student list.</p>
  </div>
  <div class="flex gap-3">
    <a href="home.php" class="rounded-xl border border-slate-300 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50">Attendance logs</a>
    <input id="student-search" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm sm:w-72" placeholder="Search name or ID">
  </div>
</div>

<section class="mb-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
  <p class="text-xs font-bold uppercase tracking-widest text-slate-500">Current sections</p>
  <div class="mt-3 flex flex-wrap gap-2" aria-label="Assigned grades">
    <?php foreach ($grades as $assignedGrade): $isActiveGrade = $assignedGrade === $grade; ?>
      <a href="students.php?grade=<?= $assignedGrade ?>" class="rounded-xl px-4 py-2 text-sm font-bold transition <?= $isActiveGrade ? 'bg-blue-600 text-white shadow-lg shadow-blue-200' : 'bg-slate-100 text-slate-700 hover:bg-blue-50 hover:text-blue-700' ?>">Grade <?= $assignedGrade ?></a>
    <?php endforeach; ?>
  </div>
  <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
    <?php foreach ($sections as $item): $isActive = $item['SECTION'] === $section; $detail = in_array((int) $item['GRADE'], [11, 12], true) ? $item['STRAND'] : $item['SUBJECT']; ?>
      <a href="students.php?grade=<?= $grade ?>&section=<?= urlencode($item['SECTION']) ?>" class="rounded-xl border p-4 transition <?= $isActive ? 'border-blue-600 bg-blue-600 text-white shadow-lg shadow-blue-200' : 'border-slate-200 bg-white text-slate-900 hover:border-blue-300 hover:bg-blue-50' ?>">
        <span class="block text-lg font-black"><?= htmlspecialchars($item['SECTION']) ?></span>
        <?php if ($detail): ?><span class="mt-1 block text-sm font-bold <?= $isActive ? 'text-blue-100' : 'text-slate-600' ?>"><?= htmlspecialchars($detail) ?></span><?php endif; ?>
        <span class="mt-1 block text-sm <?= $isActive ? 'text-blue-100' : 'text-slate-500' ?>"><?= $item['student_count'] ?> student<?= $item['student_count'] == 1 ? '' : 's' ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<div class="mb-4">
  <h3 class="text-xl font-black text-slate-900">Grade <?= $grade ?> | <?= htmlspecialchars($section) ?> students</h3>
  <p class="mt-1 text-slate-500"><?= $students->num_rows ?> student<?= $students->num_rows === 1 ? '' : 's' ?> in this section</p>
</div>
<div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
  <div class="overflow-x-auto">
    <table class="min-w-full text-left text-sm">
      <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr><th class="px-5 py-4">Student</th><th class="px-5 py-4">ID</th><th class="px-5 py-4">Grade</th><th class="px-5 py-4">Section</th><th class="px-5 py-4">Today</th><th class="px-5 py-4"></th></tr></thead>
      <tbody id="student-rows" class="divide-y divide-slate-100">
      <?php while ($student = $students->fetch_assoc()): ?>
        <tr class="hover:bg-slate-50">
          <?php $displayName = trim(($student['LAST_NAME'] ?? '') . ', ' . ($student['FIRST_NAME'] ?? '') . (!empty($student['MIDDLE_NAME']) ? ', ' . $student['MIDDLE_NAME'] : ''), ', ') ?: $student['STUDENT_NAME']; ?>
          <td class="px-5 py-4"><div class="flex items-center gap-3"><img src="../<?= htmlspecialchars($student['IMAGE_PATH']) ?>" alt="" class="h-10 w-10 rounded-full object-cover"><span class="font-bold text-slate-900"><?= htmlspecialchars($displayName) ?></span></div></td>
          <td class="px-5 py-4 text-slate-600"><?= htmlspecialchars($student['STUDENT_ID']) ?></td>
          <td class="px-5 py-4"><span class="rounded-full bg-violet-50 px-3 py-1 font-bold text-violet-700">Grade <?= htmlspecialchars($student['GRADE'] ?? '—') ?></span></td>
          <td class="px-5 py-4"><span class="rounded-full bg-blue-50 px-3 py-1 font-bold text-blue-700"><?= htmlspecialchars($student['SECTION']) ?></span></td>
          <td class="px-5 py-4"><span class="rounded-full px-3 py-1 font-bold <?= $student['attended_today'] ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' ?>"><?= $student['attended_today'] ? 'Present' : 'Not recorded' ?></span></td>
          <td class="px-5 py-4 text-right"><a class="font-bold text-blue-600" href="student-profile.php?id=<?= $student['ID'] ?>&grade=<?= $grade ?>&section=<?= urlencode($section) ?>">Open profile</a></td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
<script src="../js/student-pagination.js?v=7"></script>
<?php teacherFoot(); ?>
