<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/attendance-schema.php";
ensureAttendanceTimeOutColumn($conn);

$grade = $_GET["grade"] ?? "all";
$section = $_GET["section"] ?? "all";
$filterDate = $_GET["date"] ?? date("Y-m-d");
$student = trim($_GET["student"] ?? "");
$where = ["COALESCE(s.ENROLLMENT_STATUS,'Enrolled')='Enrolled'"];
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    http_response_code(400);
    exit("Invalid date.");
}
if (in_array((string) $grade, ["7", "8", "9", "10", "11", "12"], true)) {
    $where[] = "s.GRADE=" . (int) $grade;
}
if ($section !== "" && $section !== "all") {
    $where[] = "s.SECTION='" . mysqli_real_escape_string($conn, $section) . "'";
}
if ($student !== "") {
    $safe = mysqli_real_escape_string($conn, $student);
    $where[] = "(s.STUDENT_ID LIKE '%$safe%' OR s.STUDENT_NAME LIKE '%$safe%' OR CONCAT_WS(' ',s.LAST_NAME,s.FIRST_NAME,s.MIDDLE_NAME) LIKE '%$safe%' OR CONCAT_WS(' ',s.FIRST_NAME,s.MIDDLE_NAME,s.LAST_NAME) LIKE '%$safe%')";
}
$safeDate = mysqli_real_escape_string($conn, $filterDate);
$query =
    "SELECT a.ID ATTENDANCE_ID,s.STUDENT_ID,s.STUDENT_NAME,s.LAST_NAME,s.FIRST_NAME,s.MIDDLE_NAME,s.GRADE,s.SECTION,a.TIME_IN,a.LUNCH_OUT,a.AFTERNOON_TIME_IN,a.TIME_OUT,a.ATTENDANCE_REMARK,a.ATTENDANCE_NOTE FROM students s LEFT JOIN attendance a ON TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID) AND DATE(a.TIME_IN)='$safeDate' WHERE " .
    implode(" AND ", $where) .
    " ORDER BY s.GRADE,s.SECTION,s.LAST_NAME,s.FIRST_NAME,s.STUDENT_NAME";
$result = $conn->query($query);

if ($result && $result->num_rows):
    while ($row = $result->fetch_assoc()):

        $hasRecord = (int) ($row["ATTENDANCE_ID"] ?? 0) > 0;
        $timeIn = $hasRecord ? new DateTime($row["TIME_IN"]) : null;
        $lunchOut =
            $hasRecord && !empty($row["LUNCH_OUT"])
                ? new DateTime($row["LUNCH_OUT"])
                : null;
        $afternoonTimeIn =
            $hasRecord && !empty($row["AFTERNOON_TIME_IN"])
                ? new DateTime($row["AFTERNOON_TIME_IN"])
                : null;
        $timeOut =
            $hasRecord && !empty($row["TIME_OUT"])
                ? new DateTime($row["TIME_OUT"])
                : null;
        $name =
            trim(
                ($row["LAST_NAME"] ?? "") .
                    ", " .
                    ($row["FIRST_NAME"] ?? "") .
                    (!empty($row["MIDDLE_NAME"])
                        ? ", " . $row["MIDDLE_NAME"]
                        : ""),
                ", ",
            ) ?:
            $row["STUDENT_NAME"];
        $remark = (string) ($row["ATTENDANCE_REMARK"] ?? "");
        $note = (string) ($row["ATTENDANCE_NOTE"] ?? "");
        $isAbsent = $hasRecord && attendanceIsAbsent($remark);
        $noTime = $isAbsent || $remark === "Excused absence";
        $status = $hasRecord
            ? attendanceStatusLabel(
                $row["TIME_IN"],
                $row["TIME_OUT"] ?? null,
                $remark,
                $row["LUNCH_OUT"] ?? null,
                $row["AFTERNOON_TIME_IN"] ?? null,
            )
            : "Not recorded";
        $statusClass = $hasRecord
            ? attendanceStatusClass(
                $row["TIME_IN"],
                $row["TIME_OUT"] ?? null,
                $remark,
                $row["LUNCH_OUT"] ?? null,
                $row["AFTERNOON_TIME_IN"] ?? null,
            )
            : "bg-slate-100 text-slate-600";
        ?>
<tr class="transition hover:bg-slate-50">
  <td class="px-4 py-4 font-medium text-slate-700"><?= htmlspecialchars(
      $row["STUDENT_ID"],
  ) ?></td>
  <td class="px-4 py-4 font-bold text-slate-900"><?= htmlspecialchars(
      $name,
  ) ?></td>
  <td class="px-4 py-4"><span class="rounded-full bg-violet-50 px-3 py-1 text-xs font-bold text-violet-700" style="white-space: nowrap">Grade <?= htmlspecialchars(
      $row["GRADE"] ?? "N/A",
  ) ?></span></td>
  <td class="px-4 py-4"><span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700"><?= htmlspecialchars(
      $row["SECTION"] ?? "N/A",
  ) ?></span></td>
  <td class="px-4 py-4 text-slate-600"><?= $hasRecord
      ? $timeIn->format("M j, Y")
      : htmlspecialchars((new DateTime($filterDate))->format("M j, Y")) ?></td>
  <td class="px-4 py-4 text-slate-600"><?= $noTime || !$hasRecord
      ? "&mdash;"
      : $timeIn->format("g:i A") ?></td>
  <td class="px-4 py-4 text-slate-600"><?= $noTime || !$hasRecord
      ? "&mdash;"
      : ($lunchOut
          ? $lunchOut->format("g:i A")
          : "&mdash;") ?></td>
  <td class="px-4 py-4 text-slate-600"><?= $noTime || !$hasRecord
      ? "&mdash;"
      : ($afternoonTimeIn
          ? $afternoonTimeIn->format("g:i A")
          : "&mdash;") ?></td>
  <td class="px-4 py-4 text-slate-600"><?= $noTime || !$hasRecord
      ? "&mdash;"
      : ($timeOut
          ? $timeOut->format("g:i A")
          : "&mdash;") ?></td>
  <td class="px-4 py-4"><span class="block w-full rounded-xl px-2 py-1 text-center text-xs font-bold leading-tight <?= $statusClass ?>"><?= htmlspecialchars(
    $status,
) ?></span></td>
  <td class="px-4 py-4"><?php if (
      !$hasRecord
  ): ?><div class="flex items-center gap-3"><button type="button" data-attendance-edit data-id="" data-student-id="<?= htmlspecialchars(
    $row["STUDENT_ID"],
    ENT_QUOTES,
) ?>" data-date="<?= htmlspecialchars(
    $filterDate,
    ENT_QUOTES,
) ?>" data-remark="Excused absence" data-note="" class="text-xs font-bold text-blue-600 hover:text-blue-800">Update</button></div><?php elseif (
      $isAbsent
  ): ?><div class="flex items-center gap-3"><span class="inline-block text-center text-xs font-bold leading-tight text-rose-600"><span class="block">Marked</span>absent</span><a class="inline-block text-center text-xs font-bold leading-tight text-emerald-600" href="export-attendance.php?date=<?= urlencode(
    $filterDate,
) ?>&section=<?= urlencode($section) ?>&student_id=<?= urlencode(
    $row["STUDENT_ID"],
) ?>">Export student</a></div><?php else: ?><p class="max-w-xs text-xs text-slate-600"><?= $note !==
""
    ? htmlspecialchars($note)
    : '<span class="text-slate-400">No reason added</span>' ?></p><div class="mt-2 flex items-center gap-3"><button type="button" data-attendance-edit data-id="<?= (int) $row[
    "ATTENDANCE_ID"
] ?>" data-student-id="<?= htmlspecialchars(
    $row["STUDENT_ID"],
    ENT_QUOTES,
) ?>" data-date="<?= htmlspecialchars(
    $filterDate,
    ENT_QUOTES,
) ?>" data-remark="<?= htmlspecialchars(
    $remark,
    ENT_QUOTES,
) ?>" data-note="<?= htmlspecialchars(
    $note,
    ENT_QUOTES,
) ?>" class="text-xs font-bold text-blue-600 hover:text-blue-800">Update</button><a class="inline-block text-center text-xs font-bold leading-tight text-emerald-600" href="export-attendance.php?date=<?= urlencode(
    $filterDate,
) ?>&section=<?= urlencode($section) ?>&student_id=<?= urlencode(
    $row["STUDENT_ID"],
) ?>">Export student</a></div><?php endif; ?></td>
</tr>
<?php
    endwhile;
else:
     ?>
<tr><td colspan="11" class="px-4 py-14 text-center"><p class="text-base font-black text-slate-800">No attendance records match the current filters.</p><p class="mt-1 text-sm text-slate-500">Try another date or adjust the grade, section, or student filter above.</p></td></tr>
<?php
endif; ?>
