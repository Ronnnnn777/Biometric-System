<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/student-profile-schema.php";
require "../includes/attendance-schema.php";
require "components.php";

ensureStudentProfileColumns($conn);
ensureAttendanceTimeOutColumn($conn);

$id = (int) ($_GET["id"] ?? 0);
$studentQuery = $conn->prepare(
    "SELECT * FROM students WHERE ID=? AND ENROLLMENT_STATUS='Archived' LIMIT 1",
);
$studentQuery->bind_param("i", $id);
$studentQuery->execute();
$student = $studentQuery->get_result()->fetch_assoc();
if (!$student) {
    http_response_code(404);
    exit("Archived student record not found.");
}

$recordsQuery = $conn->prepare(
    "SELECT TIME_IN,LUNCH_OUT,AFTERNOON_TIME_IN,TIME_OUT,ATTENDANCE_REMARK,ATTENDANCE_NOTE FROM attendance WHERE TRIM(STUDENT_ID)=TRIM(?) AND IS_AUTO_ABSENT=0 ORDER BY TIME_IN DESC",
);
$recordsQuery->bind_param("s", $student["STUDENT_ID"]);
$recordsQuery->execute();
$records = $recordsQuery->get_result();
$displayName =
    trim(
        ($student["LAST_NAME"] ?? "") .
            ", " .
            ($student["FIRST_NAME"] ?? "") .
            (!empty($student["MIDDLE_NAME"])
                ? ", " . $student["MIDDLE_NAME"]
                : ""),
        ", ",
    ) ?:
    $student["STUDENT_NAME"];
adminHead("Archived student record");
adminSidebar(
    "students",
    "Archived student record",
    "Read-only profile and retained attendance history.",
);
?>
<style>
    @media (min-width:1024px) {
        .archived-record-layout>section {
            order: 1
        }

        .archived-record-layout>aside {
            order: 2
        }
    }
</style>
<a href="student-list.php?view=archived"
    class="mb-4 inline-flex items-center gap-1.5 text-sm font-bold text-slate-600 transition hover:text-blue-700">
    <?= icon('arrow-left', 'h-4 w-4') ?> Back to Archived Students
</a>
<div class="mb-6 flex flex-wrap items-center justify-end gap-4"><span
        class="rounded-full bg-amber-50 px-3 py-1 text-sm font-bold text-amber-700">Archived record</span></div>
<div class="archived-record-layout grid gap-7 lg:grid-cols-[minmax(0,1fr)_360px]">
    <aside class="h-fit rounded-2xl bg-slate-900 p-6 text-white lg:order-2">
        <p class="text-sm font-bold uppercase tracking-widest text-blue-300">Student record</p>
        <div class="mt-5 aspect-square overflow-hidden rounded-xl bg-slate-800"><img src="../<?= htmlspecialchars(
      $student["IMAGE_PATH"],
  ) ?>" alt="<?= htmlspecialchars(
    $displayName,
) ?>" class="h-full w-full object-cover"></div>
        <h2 class="mt-5 text-2xl font-black text-white"><?= htmlspecialchars(
    $displayName,
) ?></h2>
        <p class="mt-1 text-slate-300"><?= htmlspecialchars(
    $student["STUDENT_ID"],
) ?></p>
        <div class="mt-5 space-y-3 text-sm">
            <p class="flex justify-between gap-3"><span class="text-slate-400">Grade</span><span
                    class="font-bold text-white">Grade <?= htmlspecialchars(
    $student["GRADE"],
) ?></span></p>
            <p class="flex justify-between gap-3"><span class="text-slate-400">Section</span><span
                    class="font-bold text-white"><?= htmlspecialchars(
    $student["SECTION"],
) ?></span></p><?php if (
    !empty($student["STRAND"])
): ?><p class="flex justify-between gap-3"><span class="text-slate-400">Strand</span><span
                    class="font-bold text-white"><?= htmlspecialchars(
    $student["STRAND"],
) ?></span></p><?php endif; ?>
        </div>
        <p class="mt-6 text-sm leading-6 text-slate-300">Archived for reference. The student cannot scan attendance.</p>
    </aside>
    <section class="card overflow-hidden lg:order-1">
        <div class="border-b border-slate-100 p-6">
            <h3 class="text-xl font-black text-slate-900">Attendance history</h3>
            <p class="mt-1 text-sm text-slate-500">All attendance records retained for this student.</p>
        </div>
        <div class="w-full overflow-x-auto">
            <table class="w-full min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-5 py-4">Date</th>
                        <th class="px-5 py-4">Morning in</th>
                        <th class="px-5 py-4">Lunch out</th>
                        <th class="px-5 py-4">Afternoon in</th>
                        <th class="px-5 py-4">Final out</th>
                        <th class="px-5 py-4">Status</th>
                        <th class="px-5 py-4">Reason / comment</th>
                    </tr>
                </thead>
                <tbody id="archived-attendance-rows" class="divide-y divide-slate-100"><?php if (
      $records->num_rows
  ):
      while ($record = $records->fetch_assoc()):

          $timeIn = new DateTime($record["TIME_IN"]);
          $lunchOut = empty($record["LUNCH_OUT"])
              ? null
              : new DateTime($record["LUNCH_OUT"]);
          $afternoonTimeIn = empty($record["AFTERNOON_TIME_IN"])
              ? null
              : new DateTime($record["AFTERNOON_TIME_IN"]);
          $timeOut = empty($record["TIME_OUT"])
              ? null
              : new DateTime($record["TIME_OUT"]);
          $remark = (string) ($record["ATTENDANCE_REMARK"] ?? "");
          $noTime =
              attendanceIsAbsent($remark) || $remark === "Excused absence";
          $status = attendanceStatusLabel(
              $record["TIME_IN"],
              $record["TIME_OUT"] ?? null,
              $remark,
              $record["LUNCH_OUT"] ?? null,
              $record["AFTERNOON_TIME_IN"] ?? null,
          );
          ?><tr>
                        <td class="px-5 py-4 text-slate-700"><?= $timeIn->format(
    "F j, Y",
) ?></td>
                        <td class="px-5 py-4 text-slate-700"><?= $noTime
    ? "&mdash;"
    : $timeIn->format(
        "g:i A",
    ) ?></td>
                        <td class="px-5 py-4 text-slate-700"><?= $noTime
    ? "&mdash;"
    : ($lunchOut
        ? $lunchOut->format("g:i A")
        : "&mdash;") ?></td>
                        <td class="px-5 py-4 text-slate-700"><?= $noTime
    ? "&mdash;"
    : ($afternoonTimeIn
        ? $afternoonTimeIn->format("g:i A")
        : "&mdash;") ?></td>
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
) ?>"><?= htmlspecialchars(
    $status,
) ?></span></td>
                        <td class="px-5 py-4 text-sm text-slate-600"><?= !empty(
    $record["ATTENDANCE_NOTE"]
)
    ? htmlspecialchars($record["ATTENDANCE_NOTE"])
    : '<span class="text-slate-400">&mdash;</span>' ?></td>
                    </tr><?php
      endwhile;
  else:
       ?><tr>
                        <td colspan="7" class="px-5 py-12 text-center text-slate-500">No attendance records were
                            retained for this student.</td>
                    </tr><?php
  endif; ?></tbody>
            </table>
        </div>
        <div id="archived-attendance-pagination" class="px-6 pb-6"></div>
    </section>
</div>
<script src="../js/table-pagination.js?v=7"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const rows = document.getElementById('archived-attendance-rows');
        if (rows) createTablePagination({
            rows,
            pager: document.getElementById('archived-attendance-pagination'),
            label: 'attendance records'
        });
    });
</script>
<?php adminFoot(); ?>