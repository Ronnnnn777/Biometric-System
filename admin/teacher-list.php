<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/teacher-profile-schema.php';
require '../includes/teacher-assignment-schema.php';
require '../includes/csrf-token.php';
ensureTeacherProfileColumns($conn);
ensureTeacherAssignmentSchema($conn);
require 'components.php';

$limit = 15;
$view = strtolower(trim((string) ($_GET['view'] ?? 'active')));
if (!in_array($view, ['active', 'archived'], true)) {
    $view = 'active';
}
$isArchivedView = $view === 'archived';
$teacherWhere = $isArchivedView
    ? "TEACHER_STATUS='Archived'"
    : "COALESCE(NULLIF(TEACHER_STATUS,''),'Active')='Active'";

$teacherCount = $conn->query("SELECT COUNT(*) AS total FROM teachers WHERE $teacherWhere");
$totalTeachers = (int) ($teacherCount ? $teacherCount->fetch_assoc()['total'] ?? 0 : 0);
$teacherOrder = $isArchivedView ? 'ARCHIVED_AT DESC, TEACHER_NAME' : 'TEACHER_NAME';
$teachers = $conn->query(
    "SELECT TEACHER_ID, TEACHER_NAME, TEACHER_USER, CREATED_AT, ARCHIVED_AT
     FROM teachers
     WHERE $teacherWhere
     ORDER BY $teacherOrder",
);

$viewPillActive = 'rounded-full bg-blue-600 text-white font-black text-sm px-4 py-2 shadow-md transition-all';
$viewPillInactive = 'rounded-full text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white font-bold text-sm px-4 py-2 transition-all';
$viewToggle = '<nav class="inline-flex flex-wrap items-center gap-1 rounded-full border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-800 dark:bg-slate-900/90" aria-label="Teacher records view">'
    . '<a href="teacher-list.php?view=active" class="' . ($isArchivedView ? $viewPillInactive : $viewPillActive) . '"'
    . (!$isArchivedView ? ' aria-current="page"' : '') . '>Active</a>'
    . '<a href="teacher-list.php?view=archived" class="' . ($isArchivedView ? $viewPillActive : $viewPillInactive) . '"'
    . ($isArchivedView ? ' aria-current="page"' : '') . '>Archived</a>'
    . '</nav>';

adminHead($isArchivedView ? 'Archived teachers' : 'Teachers');
adminSidebar(
    'teachers',
    $isArchivedView ? 'Archived teachers' : 'Teachers',
    $isArchivedView ? 'Retained teacher accounts and access assignments.' : 'Manage teacher access and grade-section assignments.',
);
?>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <p class="text-sm font-semibold text-slate-500">
            <?= $totalTeachers ?> <?= $isArchivedView ? 'archived' : 'active' ?>
            teacher<?= $totalTeachers === 1 ? ' account' : ' accounts' ?>
        </p>
        <?= $viewToggle ?>
    </div>
    <?php if (!$isArchivedView): ?>
    <a href="add-teacher.php" class="btn btn-primary">+ Add teacher</a>
    <?php endif; ?>
</div>

<div class="card overflow-hidden">
    <div class="w-full overflow-x-auto">
        <table class="w-full min-w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr>
                    <th class="px-5 py-4">Teacher</th>
                    <th class="px-5 py-4">Username</th>
                    <th class="px-5 py-4"><?= $isArchivedView ? 'Retained assignments' : 'Assigned grades and sections' ?>
                    </th>
                    <th class="px-5 py-4"><?= $isArchivedView ? 'Archived' : 'Created' ?></th>
                    <?php if ($isArchivedView): ?>
                    <th class="px-5 py-4">Status</th>
                    <?php endif; ?>
                    <th class="px-5 py-4"></th>
                </tr>
            </thead>
            <tbody id="teacher-rows" data-page-size="<?= $limit ?>" class="divide-y divide-slate-100">
                <?php if ($teachers && $teachers->num_rows): ?>
                <?php while ($teacher = $teachers->fetch_assoc()): ?>
                <?php
                    $assignments = teacherAssignments($conn, (int) $teacher['TEACHER_ID']);
                    $dateValue = $isArchivedView ? ($teacher['ARCHIVED_AT'] ?? '') : ($teacher['CREATED_AT'] ?? '');
                ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-4 font-bold text-slate-900"><?= htmlspecialchars($teacher['TEACHER_NAME']) ?></td>
                    <td class="px-5 py-4 text-slate-600"><?= htmlspecialchars($teacher['TEACHER_USER']) ?></td>
                    <td class="px-5 py-4">
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($assignments as $assignment): ?>
                            <?php
                                $detail = in_array((int) $assignment['GRADE'], [11, 12], true) ? $assignment['STRAND'] : $assignment['SUBJECT'];
                                $label = 'Grade ' . $assignment['GRADE'] . ' | ' . $assignment['SECTION'];
                            ?>
                            <span
                                class="rounded-full bg-violet-50 px-3 py-1 font-bold text-violet-700"><?= htmlspecialchars($label) . ($detail ? ' · ' . htmlspecialchars($detail) : '') ?></span>
                            <?php endforeach; ?>
                            <?php if (!$assignments): ?>
                            <span class="text-slate-500"><?= $isArchivedView ? 'No assignments retained' : 'Not assigned' ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-5 py-4 text-slate-500">
                        <?= $dateValue ? htmlspecialchars(date('M j, Y', strtotime($dateValue))) : '—' ?>
                    </td>
                    <?php if ($isArchivedView): ?>
                    <td class="px-5 py-4"><span
                            class="rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700">Archived</span>
                    </td>
                    <?php endif; ?>
                    <td class="px-5 py-4 text-right">
                        <?php if ($isArchivedView): ?>
                        <div class="flex flex-wrap justify-end gap-2">
                            <a class="btn btn-secondary inline-flex h-8 items-center justify-center rounded border border-blue-500/20 bg-blue-500/10 px-3 text-xs font-medium text-blue-600 transition hover:bg-blue-500/20"
                                href="archived-teacher-record.php?id=<?= (int) $teacher['TEACHER_ID'] ?>">Open record</a>
                            <form action="restore-archived-teacher.php" method="post" class="inline">
                                <input type="hidden" name="csrf_token"
                                    value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="id" value="<?= (int) $teacher['TEACHER_ID'] ?>">
                                <button
                                    class="btn btn-secondary inline-flex h-8 items-center justify-center rounded border border-emerald-500/20 bg-emerald-500/10 px-3 text-xs font-medium text-emerald-700 transition hover:bg-emerald-500/20">Restore</button>
                            </form>
                        </div>
                        <?php else: ?>
                        <a href="edit-teacher.php?id=<?= (int) $teacher['TEACHER_ID'] ?>"
                            class="btn btn-secondary inline-flex h-8 items-center justify-center rounded border border-blue-500/20 bg-blue-500/10 px-3 text-xs font-medium text-blue-600 transition hover:bg-blue-500/20">Open
                            profile</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php else: ?>
                <tr>
                    <td colspan="<?= $isArchivedView ? 6 : 5 ?>" class="px-5 py-12 text-center text-slate-500">
                        <?= $isArchivedView ? 'No archived teacher accounts.' : 'No teacher accounts yet. Add the first teacher to get started.' ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="../js/table-pagination.js?v=7"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const rows = document.getElementById('teacher-rows');
        if (!rows) return;
        const card = rows.closest('.overflow-hidden'),
            bottom = document.createElement('div');
        card?.insertAdjacentElement('afterend', bottom);
        createTablePagination({
            rows,
            pager: bottom,
            label: '<?= $isArchivedView ? 'archived teacher accounts' : 'teacher accounts' ?>',
            perPage: <?= $limit ?>,
            total: <?= $totalTeachers ?>
        });
    });
</script>
<?php adminFoot(); ?>
