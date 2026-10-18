<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/teacher-profile-schema.php";
require "../includes/teacher-assignment-schema.php";
ensureTeacherProfileColumns($conn);
ensureTeacherAssignmentSchema($conn);
require "components.php";

$teachers = $conn->query(
    "SELECT TEACHER_ID,TEACHER_NAME,TEACHER_USER,CREATED_AT,ARCHIVED_AT FROM teachers WHERE TEACHER_STATUS='Archived' ORDER BY ARCHIVED_AT DESC,TEACHER_NAME",
);
adminHead("Archived teachers");
adminSidebar(
    "archived_teachers",
    "Archived teachers",
    "Retained teacher accounts and access assignments.",
);
?>
<script src="../js/table-pagination.js?v=5"></script>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div>
        <p class="text-sm font-black uppercase tracking-widest text-amber-700">Records archive</p>
        <h2 class="mt-1 text-2xl font-black text-slate-900">Archived teachers</h2>
        <p class="mt-1 text-slate-500"><?= $teachers->num_rows ?> archived teacher account<?= $teachers->num_rows ===
 1
     ? ""
     : "s" ?>. Their profiles and assignments are retained, but they cannot sign in.</p>
    </div>
    <a href="teacher-list.php"
        class="rounded-xl border border-slate-300 bg-white px-4 py-3 text-center text-sm font-bold text-slate-700 hover:bg-slate-50">View
        active teachers</a>
</div>
<div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
    <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr>
                    <th class="px-5 py-4">Teacher</th>
                    <th class="px-5 py-4">Username</th>
                    <th class="px-5 py-4">Retained assignments</th>
                    <th class="px-5 py-4">Archived</th>
                    <th class="px-5 py-4">Status</th>
                </tr>
            </thead>
            <tbody id="archived-teacher-rows" class="divide-y divide-slate-100"><?php if (
    $teachers->num_rows
):
    while ($teacher = $teachers->fetch_assoc()):
        $assignments = teacherAssignments(
            $conn,
            (int) $teacher["TEACHER_ID"],
        ); ?><tr class="hover:bg-amber-50/40">
                    <td class="px-5 py-4 font-bold text-slate-900"><?= htmlspecialchars(
    $teacher["TEACHER_NAME"],
) ?></td>
                    <td class="px-5 py-4 text-slate-600"><?= htmlspecialchars(
    $teacher["TEACHER_USER"],
) ?></td>
                    <td class="px-5 py-4">
                        <div class="flex flex-wrap gap-2"><?php
foreach ($assignments as $assignment):
    $detail = in_array((int) $assignment["GRADE"], [11, 12], true)
        ? $assignment["STRAND"]
        : $assignment[
            "SUBJECT"
        ]; ?><span class="rounded-full bg-violet-50 px-3 py-1 font-bold text-violet-700">Grade <?= (int) $assignment[
    "GRADE"
] ?> · <?=
 htmlspecialchars($assignment["SECTION"]) . (
 $detail ? " · " . htmlspecialchars($detail) : ""
 ) ?></span><?php
endforeach;
if (
    !$assignments
): ?><span class="text-slate-500">No assignments retained</span><?php endif;
?></div>
                    </td>
                    <td class="px-5 py-4 text-slate-600"><?= $teacher["ARCHIVED_AT"]
    ? htmlspecialchars(date("M j, Y g:i A", strtotime($teacher["ARCHIVED_AT"])))
    : "—" ?></td>
                    <td class="px-5 py-4"><span
                            class="rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700">Archived</span>
                    </td>
                </tr><?php
    endwhile;
else:
     ?><tr>
                    <td colspan="5" class="px-5 py-12 text-center text-slate-500">No archived teacher accounts.</td>
                </tr><?php
endif; ?></tbody>
        </table>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const rows = document.getElementById('archived-teacher-rows');
        if (!rows) return;
        rows.querySelectorAll('tr').forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length !== 5) return;
            const username = cells[1].textContent.trim(),
                status = cells[4],
                link = document.createElement('a');
            link.href = 'archived-teacher-record.php?teacher_user=' + encodeURIComponent(username);
            link.className = 'ml-4 whitespace-nowrap font-bold text-blue-600 hover:text-blue-700';
            link.textContent = 'Open record';
            status.appendChild(link);
        });
        const card = rows.closest('.overflow-hidden'),
            top = document.createElement('div'),
            bottom = document.createElement('div');
        card?.insertAdjacentElement('beforebegin', top);
        card?.insertAdjacentElement('afterend', bottom);
        createTablePagination({
            rows,
            pager: [top, bottom],
            label: 'archived teachers'
        });
    });
</script>
<?php adminFoot(); ?>