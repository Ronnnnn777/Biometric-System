<?php
require '../config/db_conn.php';
require '../includes/student-profile-schema.php';
ensureStudentProfileColumns($conn);
require 'page-guard.php';
require 'components.php';

$today = date('Y-m-d');
$stmt = $conn->prepare('SELECT s.*, (SELECT COUNT(*) FROM attendance a WHERE TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID) AND DATE(a.TIME_IN)=?) attended_today FROM students s ORDER BY s.LAST_NAME, s.FIRST_NAME, s.GRADE, s.SECTION');
$stmt->bind_param('s', $today);
$stmt->execute();
$result = $stmt->get_result();
$sections = [];
$sectionResult = $conn->query("SELECT DISTINCT SECTION FROM students WHERE SECTION IS NOT NULL AND SECTION<>'' ORDER BY SECTION");
while ($item = $sectionResult->fetch_assoc()) $sections[] = $item['SECTION'];

adminHead('Students');
adminSidebar('students', 'Students', 'Search profiles and filter by grade or section');
?>
<div class="mb-6 flex flex-col gap-4">
  <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <div><h2 class="text-2xl font-black text-slate-900">Student directory</h2><p class="mt-1 text-slate-500"><?= $result->num_rows ?> registered student<?= $result->num_rows === 1 ? '' : 's' ?></p></div>
    <div class="flex flex-col gap-3 sm:flex-row">
      <a href="import-students.php" class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 text-center text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700 focus:outline-none focus:ring-4 focus:ring-emerald-200" aria-label="Import students from a CSV or Excel spreadsheet"><span aria-hidden="true">⇧</span> Import CSV / Excel</a>
      <a href="add-student.php" class="shrink-0 rounded-xl bg-blue-600 px-4 py-3 text-center text-sm font-bold text-white hover:bg-blue-700">Add student</a>
    </div>
  </div>
  <div class="grid gap-3 sm:grid-cols-4">
    <input id="student-search" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm" placeholder="Search name or ID">
    <select id="grade-filter" class="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm"><option value="">All grades</option><?php foreach ([7, 8, 9, 10, 11, 12] as $grade): ?><option value="<?= $grade ?>">Grade <?= $grade ?></option><?php endforeach; ?></select>
    <select id="section-filter" class="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm"><option value="">All sections</option><?php foreach ($sections as $section): ?><option><?= htmlspecialchars($section) ?></option><?php endforeach; ?></select>
    <select id="status-filter" class="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm"><option value="">All statuses</option><?php foreach (studentEnrollmentStatuses() as $status): ?><option><?= htmlspecialchars($status) ?></option><?php endforeach; ?></select>
  </div>
</div>

<div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
  <div class="overflow-x-auto">
    <table class="min-w-full text-left text-sm">
      <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr><th class="px-5 py-4">Student</th><th class="px-5 py-4">ID</th><th class="px-5 py-4">Grade</th><th class="px-5 py-4">Section</th><th class="px-5 py-4">Status</th><th class="px-5 py-4">Today</th><th class="px-5 py-4"></th></tr></thead>
      <tbody id="student-rows" class="divide-y divide-slate-100">
        <?php while ($row = $result->fetch_assoc()): ?>
          <tr class="hover:bg-slate-50" data-grade="<?= (int) $row['GRADE'] ?>" data-section="<?= htmlspecialchars($row['SECTION'] ?? '') ?>" data-status="<?= htmlspecialchars($row['ENROLLMENT_STATUS'] ?? 'Enrolled') ?>">
            <?php $displayName = trim(($row['LAST_NAME'] ?? '') . ', ' . ($row['FIRST_NAME'] ?? '') . (!empty($row['MIDDLE_NAME']) ? ', ' . $row['MIDDLE_NAME'] : ''), ', ') ?: $row['STUDENT_NAME']; ?>
            <td class="px-5 py-4"><div class="flex items-center gap-3"><img src="../<?= htmlspecialchars($row['IMAGE_PATH']) ?>" alt="" class="h-10 w-10 rounded-full object-cover"><span class="font-bold text-slate-900"><?= htmlspecialchars($displayName) ?></span></div></td>
            <td class="px-5 py-4 text-slate-600"><?= htmlspecialchars($row['STUDENT_ID']) ?></td>
            <td class="px-5 py-4"><span class="rounded-full bg-violet-50 px-3 py-1 font-bold text-violet-700">Grade <?= htmlspecialchars($row['GRADE'] ?? '—') ?></span></td>
            <td class="px-5 py-4"><span class="rounded-full bg-blue-50 px-3 py-1 font-bold text-blue-700"><?= htmlspecialchars($row['SECTION'] ?? 'N/A') ?></span></td>
            <?php $status=$row['ENROLLMENT_STATUS'] ?? 'Enrolled'; $statusClass=$status==='Enrolled'?'bg-emerald-50 text-emerald-700':($status==='Transferred'?'bg-blue-50 text-blue-700':($status==='Dropped'?'bg-rose-50 text-rose-700':'bg-slate-100 text-slate-700')); ?><td class="px-5 py-4"><span class="rounded-full px-3 py-1 font-bold <?= $statusClass ?>"><?= htmlspecialchars($status) ?></span></td>
            <td class="px-5 py-4"><span class="rounded-full px-3 py-1 font-bold <?= $row['attended_today'] ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' ?>"><?= $row['attended_today'] ? 'Present' : 'Not recorded' ?></span></td>
            <td class="px-5 py-4 text-right"><a class="font-bold text-blue-600" href="edit-student.php?id=<?= $row['ID'] ?>">Open profile</a></td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
<script src="../js/student-pagination.js?v=7"></script>
<?php adminFoot(); ?>
