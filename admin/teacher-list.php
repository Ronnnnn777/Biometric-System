<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/teacher-profile-schema.php";
require "../includes/teacher-assignment-schema.php";
ensureTeacherProfileColumns($conn);
ensureTeacherAssignmentSchema($conn);
require "components.php";
$teachers = $conn->query(
    "SELECT TEACHER_ID,TEACHER_NAME,TEACHER_USER,CREATED_AT FROM teachers WHERE COALESCE(TEACHER_STATUS,'Active')='Active' ORDER BY TEACHER_NAME",
);
adminHead("Teachers");
adminSidebar(
    "teachers",
    "Teachers",
    "Manage teacher access and grade-section assignments.",
);
?>
<script src="../js/table-pagination.js?v=5"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const rows = document.querySelector('table tbody');
        if (!rows) return;
        const card = rows.closest('.overflow-hidden'),
            top = document.createElement('div'),
            bottom = document.createElement('div');
        card?.insertAdjacentElement('beforebegin', top);
        card?.insertAdjacentElement('afterend', bottom);
        createTablePagination({
            rows,
            pager: [top, bottom],
            label: 'teacher accounts'
        });
    });
</script>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <div>
        <h2 class="text-2xl font-black text-slate-900">Teacher accounts</h2>
        <p class="mt-1 text-slate-500"><?= $teachers->num_rows ?> account<?= $teachers->num_rows ===
 1
     ? ""
     : "s" ?> with system access</p>
    </div><a href="add-teacher.php"
        class="rounded-xl bg-blue-600 px-4 py-3 text-center text-sm font-bold text-white hover:bg-blue-700">+ Add
        teacher</a>
</div>
<div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
    <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr>
                    <th class="px-5 py-4">Teacher</th>
                    <th class="px-5 py-4">Username</th>
                    <th class="px-5 py-4">Assigned grades and sections</th>
                    <th class="px-5 py-4">Created</th>
                    <th class="px-5 py-4"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100"><?php if (
     $teachers->num_rows
 ):
     while ($teacher = $teachers->fetch_assoc()):
         $assignments = teacherAssignments(
             $conn,
             (int) $teacher["TEACHER_ID"],
         ); ?><tr class="hover:bg-slate-50">
                    <td class="px-5 py-4 font-bold text-slate-900"><?= htmlspecialchars(
    $teacher["TEACHER_NAME"],
) ?></td>
                    <td class="px-5 py-4 text-slate-600"><?= htmlspecialchars(
    $teacher["TEACHER_USER"],
) ?></td>
                    <td class="px-5 py-4">
                        <div class="flex flex-wrap gap-2"><?php
foreach ($assignments as $assignment):

    $label = "Grade " . $assignment["GRADE"] . " | " . $assignment["SECTION"];
    $detail = in_array((int) $assignment["GRADE"], [11, 12], true)
        ? $assignment["STRAND"]
        : $assignment["SUBJECT"];
    ?><span class="rounded-full bg-violet-50 px-3 py-1 font-bold text-violet-700"><?=
htmlspecialchars($label) . (
$detail ? " · " . htmlspecialchars($detail) : ""
) ?></span><?php
endforeach;
if (
    !$assignments
): ?><span class="text-slate-500">Not assigned</span><?php endif;
?></div>
                    </td>
                    <td class="px-5 py-4 text-slate-500"><?= htmlspecialchars(
    date("M j, Y", strtotime($teacher["CREATED_AT"])),
) ?></td>
                    <td class="px-5 py-4 text-right"><a href="edit-teacher.php?id=<?= $teacher[
    "TEACHER_ID"
] ?>" class="font-bold text-blue-600 hover:text-blue-700">Open profile</a></td>
                </tr><?php
     endwhile;
 else:
      ?><tr>
                    <td colspan="5" class="px-5 py-12 text-center text-slate-500">No teacher accounts yet. Add the first
                        teacher to get started.</td>
                </tr><?php
 endif; ?></tbody>
        </table>
    </div>
</div><?php adminFoot(); ?>