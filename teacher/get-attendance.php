<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/attendance-schema.php";
require_once "../includes/teacher-profile-schema.php";
require "../includes/teacher-assignment-schema.php";
ensureAttendanceTimeOutColumn($conn);
ensureTeacherProfileColumns($conn);
ensureTeacherAssignmentSchema($conn);
$section = trim($_GET["section"] ?? "all");
$grade = (int) ($_GET["grade"] ?? 0);
$date = $_GET["date"] ?? date("Y-m-d");
$student = trim($_GET["student"] ?? "");
$teacherId = (int) ($_SESSION["teacher-id"] ?? 0);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    exit("Invalid date.");
}
$stmt = $conn->prepare(
    "SELECT a.ID ATTENDANCE_ID, s.STUDENT_ID, s.STUDENT_NAME, s.LAST_NAME,
            s.FIRST_NAME, s.MIDDLE_NAME, s.GRADE, s.SECTION, a.TIME_IN,
            a.LUNCH_OUT, a.AFTERNOON_TIME_IN, a.TIME_OUT,
            a.ATTENDANCE_REMARK, a.ATTENDANCE_NOTE
       FROM students s
       LEFT JOIN attendance a ON TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID)
            AND DATE(a.TIME_IN)=?
      WHERE COALESCE(s.ENROLLMENT_STATUS, 'Enrolled')='Enrolled'
        AND EXISTS (
            SELECT 1
              FROM teacher_assignments ta
             WHERE ta.TEACHER_ID=?
               AND ta.GRADE=s.GRADE
               AND (ta.SUBJECT<>'' OR ta.SECTION=s.SECTION)
        )
        AND (?='all' OR s.SECTION=?)
        AND (?=0 OR s.GRADE=?)
        AND (
            ?=''
            OR s.STUDENT_ID LIKE CONCAT('%',?,'%')
            OR s.STUDENT_NAME LIKE CONCAT('%',?,'%')
            OR CONCAT_WS(' ',s.LAST_NAME,s.FIRST_NAME,s.MIDDLE_NAME) LIKE CONCAT('%',?,'%')
        )
      ORDER BY s.GRADE, s.SECTION, s.LAST_NAME, s.FIRST_NAME, s.STUDENT_NAME",
);
$stmt->bind_param(
    "sissiissss",
    $date,
    $teacherId,
    $section,
    $section,
    $grade,
    $grade,
    $student,
    $student,
    $student,
    $student,
);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows):
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
        $displayName =
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
<tr>
    <td class="px-4 py-4"><?= htmlspecialchars(
      $row["STUDENT_ID"],
  ) ?></td>
    <td class="px-4 py-4 font-bold"><?= htmlspecialchars(
    $displayName,
) ?></td>
    <td class="px-4 py-4"><span class="rounded-full bg-violet-50 px-3 py-1 text-xs font-bold text-violet-700"
            style="white-space: nowrap">Grade <?= htmlspecialchars(
      $row["GRADE"] ?? "—",
  ) ?></span></td>
    <td class="px-4 py-4"><a class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700 hover:bg-blue-100"
            href="students.php?grade=<?= (int) $row[
      "GRADE"
  ] ?>&section=<?= urlencode($row["SECTION"]) ?>"><?= htmlspecialchars(
    $row["SECTION"],
) ?></a></td>
    <td class="px-4 py-4"><?= $hasRecord
      ? $timeIn->format("M j, Y")
      : (new DateTime($date))->format(
          "M j, Y",
      ) ?></td>
    <td class="px-4 py-4"><?= $noTime || !$hasRecord
    ? "&mdash;"
    : $timeIn->format("g:i A") ?></td>
    <td class="px-4 py-4"><?= $noTime ||
!$hasRecord
    ? "&mdash;"
    : ($lunchOut
        ? $lunchOut->format("g:i A")
        : "&mdash;") ?></td>
    <td class="px-4 py-4"><?= $noTime || !$hasRecord
    ? "&mdash;"
    : ($afternoonTimeIn
        ? $afternoonTimeIn->format("g:i A")
        : "&mdash;") ?></td>
    <td class="px-4 py-4"><?= $noTime || !$hasRecord
    ? "&mdash;"
    : ($timeOut
        ? $timeOut->format("g:i A")
        : "&mdash;") ?></td>
    <td class="px-4 py-4"><span
            class="block w-full rounded-xl px-2 py-1 text-center text-xs font-bold leading-tight <?= $statusClass ?>"><?= htmlspecialchars(
    $status,
) ?></span></td>
    <td class="px-4 py-4"><?php if (
      !$hasRecord
  ): ?><button type="button" data-attendance-edit data-id="" data-student-id="<?= htmlspecialchars(
    $row["STUDENT_ID"],
    ENT_QUOTES,
) ?>" data-date="<?= htmlspecialchars(
    $date,
    ENT_QUOTES,
) ?>" data-remark="Excused absence" data-note=""
            class="btn btn-secondary btn-sm">Update</button><?php elseif (
      !$isAbsent
  ): ?><p class="max-w-xs text-xs text-slate-600"><?= $note !== ""
    ? htmlspecialchars($note)
    : '<span class="text-slate-400">No reason added</span>' ?></p><button type="button" data-attendance-edit data-id="<?= (int) $row[
    "ATTENDANCE_ID"
] ?>" data-student-id="<?= htmlspecialchars(
    $row["STUDENT_ID"],
    ENT_QUOTES,
) ?>" data-date="<?= htmlspecialchars(
    $date,
    ENT_QUOTES,
) ?>" data-remark="<?= htmlspecialchars(
    $remark,
    ENT_QUOTES,
) ?>" data-note="<?= htmlspecialchars(
    $note,
    ENT_QUOTES,
) ?>" class="mt-2 btn btn-secondary btn-sm">Update</button><?php else: ?><span
            class="inline-block text-center text-xs font-bold leading-tight text-rose-600"><span
                class="block">Marked</span>absent</span><?php endif; ?></td>
</tr>
<?php
    endwhile;
else:
     ?>
<tr>
    <td colspan="11" class="px-4 py-14 text-center">
        <p class="text-base font-black text-slate-800">No attendance records match the current filters.</p>
        <p class="mt-1 text-sm text-slate-500">Try another date or adjust the grade, section, or student filter above.
        </p>
    </td>
</tr>
<?php
endif; ?>
