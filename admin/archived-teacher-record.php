<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/teacher-profile-schema.php";
require "../includes/teacher-assignment-schema.php";
require "../includes/attendance-schema.php";
ensureTeacherProfileColumns($conn);
ensureTeacherAssignmentSchema($conn);
ensureAttendanceTimeOutColumn($conn);
require "components.php";

$teacherId = (int) ($_GET["id"] ?? 0);
$teacherUser = trim((string) ($_GET["teacher_user"] ?? ""));
if ($teacherUser !== "") {
    $teacherQuery = $conn->prepare(
        "SELECT TEACHER_ID, TEACHER_NAME, TEACHER_USER, CREATED_AT, ARCHIVED_AT FROM teachers WHERE TEACHER_USER=? AND TEACHER_STATUS='Archived' LIMIT 1",
    );
    $teacherQuery->bind_param("s", $teacherUser);
} else {
    $teacherQuery = $conn->prepare(
        "SELECT TEACHER_ID, TEACHER_NAME, TEACHER_USER, CREATED_AT, ARCHIVED_AT FROM teachers WHERE TEACHER_ID=? AND TEACHER_STATUS='Archived' LIMIT 1",
    );
    $teacherQuery->bind_param("i", $teacherId);
}
$teacherQuery->execute();
$teacher = $teacherQuery->get_result()->fetch_assoc();
if (!$teacher) {
    http_response_code(404);
    exit("Archived teacher record not found.");
}

$teacherId = (int) $teacher["TEACHER_ID"];

$assignments = teacherAssignments($conn, $teacherId);
$placementFilters = [];
foreach ($assignments as $assignment) {
    $grade = (int) $assignment["GRADE"];
    $section = $conn->real_escape_string((string) $assignment["SECTION"]);
    $placementFilters[] =
        trim((string) $assignment["SUBJECT"]) !== ""
            ? "s.GRADE={$grade}"
            : "(s.GRADE={$grade} AND s.SECTION='{$section}')";
}
$records = false;
if ($placementFilters) {
    $records = $conn->query(
        "SELECT DISTINCT a.ID, a.STUDENT_ID, COALESCE(s.STUDENT_NAME, a.STUDENT_NAME) AS STUDENT_NAME, s.GRADE, s.SECTION, a.TIME_IN, a.LUNCH_OUT, a.AFTERNOON_TIME_IN, a.TIME_OUT, a.ATTENDANCE_REMARK, a.ATTENDANCE_NOTE FROM attendance a INNER JOIN students s ON TRIM(s.STUDENT_ID)=TRIM(a.STUDENT_ID) WHERE (" .
            implode(" OR ", $placementFilters) .
            ") ORDER BY a.TIME_IN DESC",
    );
}

adminHead("Archived teacher record");
adminSidebar(
    "archived_teachers",
    "Archived teacher record",
    "Read-only profile, assignments, and retained class attendance history.",
);
?>
<style>
    #archived-teacher-attendance-rows {
        font-size: .75rem;
    }

    #archived-teacher-attendance-rows td,
    #archived-teacher-attendance-rows th {
        padding: .65rem .5rem;
    }

    #archived-teacher-attendance-rows td:first-child {
        max-width: 10rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    @media (min-width: 768px) {
        .archived-teacher-record-layout>section table {
            width: 100%;
            table-layout: fixed;
        }

        .archived-teacher-record-layout>section .overflow-x-auto {
            overflow-x: visible;
        }
    }

    @media (min-width: 1024px) and (max-width: 1279px) {
        .archived-teacher-record-layout {
            grid-template-columns: minmax(0, 1fr);
        }
    }
</style>
<div class="mb-6 flex flex-wrap items-center justify-between gap-4"><a href="archived-teachers.php"
        class="inline-flex items-center gap-1 text-sm font-bold text-blue-600 hover:text-blue-800"><?= icon('arrow-left') ?>Back to archived teachers</a><span
        class="rounded-full bg-amber-50 px-3 py-1 text-sm font-bold text-amber-700">Archived record</span></div>
<div class="archived-teacher-record-layout grid gap-7 lg:grid-cols-[minmax(0,1fr)_360px]">
    <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
        <div class="border-b border-slate-100 p-6">
            <h2 class="text-xl font-black text-slate-900">Class attendance history</h2>
            <p class="mt-1 text-sm text-slate-500">All saved attendance records from this teacher's retained class
                assignments.</p>
        </div>
        <div id="archived-teacher-attendance-top"></div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-5 py-4">Student</th>
                        <th class="px-5 py-4">Grade</th>
                        <th class="px-5 py-4">Section</th>
                        <th class="px-5 py-4">Date</th>
                        <th class="px-5 py-4">Morning in</th>
                        <th class="px-5 py-4">Final out</th>
                        <th class="px-5 py-4">Status</th>
                    </tr>
                </thead>
                <tbody id="archived-teacher-attendance-rows" class="divide-y divide-slate-100">
                    <?php if ($records && $records->num_rows):
      while ($record = $records->fetch_assoc()):

          $timeIn = new DateTime($record["TIME_IN"]);
          $remark = (string) ($record["ATTENDANCE_REMARK"] ?? "");
          $noTime =
              attendanceIsAbsent($remark) || $remark === "Excused absence";
          $timeOut = empty($record["TIME_OUT"])
              ? null
              : new DateTime($record["TIME_OUT"]);
          $status = attendanceStatusLabel(
              $record["TIME_IN"],
              $record["TIME_OUT"] ?? null,
              $remark,
              $record["LUNCH_OUT"] ?? null,
              $record["AFTERNOON_TIME_IN"] ?? null,
          );
          ?>
                    <tr>
                        <td class="px-5 py-4 font-bold text-slate-900"><?= htmlspecialchars(
        $record["STUDENT_NAME"],
    ) ?></td>
                        <td class="px-5 py-4 text-slate-700">Grade <?= (int) $record[
    "GRADE"
] ?></td>
                        <td class="px-5 py-4 text-slate-700"><?= htmlspecialchars(
    $record["SECTION"],
) ?></td>
                        <td class="px-5 py-4 text-slate-700"><?= $timeIn->format(
    "M j, Y",
) ?></td>
                        <td class="px-5 py-4 text-slate-700"><?= $noTime
    ? "&mdash;"
    : $timeIn->format(
        "g:i A",
    ) ?></td>
                        <td class="px-5 py-4 text-slate-700"><?= $noTime
    ? "&mdash;"
    : ($timeOut
        ? $timeOut->format("g:i A")
        : "&mdash;") ?></td>
                        <td class="px-5 py-4"><span class="rounded-full px-3 py-1 text-xs font-bold <?= attendanceStatusClass(
    $record["TIME_IN"],
    $record["TIME_OUT"] ?? null,
    $remark,
    $record["LUNCH_OUT"] ?? null,
    $record["AFTERNOON_TIME_IN"] ?? null,
) ?>"><?= htmlspecialchars($status) ?></span></td>
                    </tr>
                    <?php
      endwhile;
  else:
       ?><tr>
                        <td colspan="7" class="px-5 py-12 text-center text-slate-500">No attendance records were
                            retained for this teacher's assignments.</td>
                    </tr><?php
  endif; ?>
                </tbody>
            </table>
        </div>
        <div id="archived-teacher-attendance-bottom" class="px-6 pb-6"></div>
    </section>
    <aside class="h-fit rounded-2xl bg-slate-900 p-6 text-white">
        <p class="text-sm font-bold uppercase tracking-widest text-blue-300">Teacher record</p>
        <h2 class="mt-4 text-2xl font-black text-white"><?= htmlspecialchars(
      $teacher["TEACHER_NAME"],
  ) ?></h2>
        <p class="mt-1 break-all text-sm text-slate-300"><?= htmlspecialchars(
    $teacher["TEACHER_USER"],
) ?></p>
        <div class="mt-6 border-t border-white/10 pt-5">
            <p class="text-sm font-bold text-slate-300">Retained assignments</p>
            <div class="mt-3 flex flex-wrap gap-2"><?php
foreach ($assignments as $assignment):
    $detail = in_array((int) $assignment["GRADE"], [11, 12], true)
        ? $assignment["STRAND"]
        : $assignment[
            "SUBJECT"
        ]; ?><span class="rounded-full bg-white/10 px-3 py-1 text-sm font-bold text-white">Grade <?= (int) $assignment[
    "GRADE"
] ?> &middot; <?=
 htmlspecialchars($assignment["SECTION"]) .
 ($detail ? " &middot; " . htmlspecialchars($detail) : "")
 ?></span><?php
endforeach;
if (
    !$assignments
): ?><span class="text-sm text-slate-400">No assignments retained</span><?php endif;
?></div>
        </div>
        <div class="mt-6 space-y-3 border-t border-white/10 pt-5 text-sm">
            <p class="flex justify-between gap-3"><span class="text-slate-400">Account created</span><span
                    class="font-bold text-white"><?= $teacher[
    "CREATED_AT"
]
    ? htmlspecialchars(date("M j, Y", strtotime($teacher["CREATED_AT"])))
    : "&mdash;" ?></span></p>
            <p class="flex justify-between gap-3"><span class="text-slate-400">Archived</span><span
                    class="font-bold text-white"><?= $teacher[
    "ARCHIVED_AT"
]
    ? htmlspecialchars(date("M j, Y", strtotime($teacher["ARCHIVED_AT"])))
    : "&mdash;" ?></span></p>
        </div>
        <p class="mt-6 text-sm leading-6 text-slate-300">This is a read-only archive. The teacher cannot sign in or
            change these records.</p>
    </aside>
</div>
<script src="../js/table-pagination.js?v=5"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const rows = document.getElementById('archived-teacher-attendance-rows');
        if (rows) createTablePagination({
            rows,
            pager: [document.getElementById('archived-teacher-attendance-top'), document.getElementById(
                'archived-teacher-attendance-bottom')],
            label: 'attendance records'
        });
    });
</script>
<?php adminFoot(); ?>