<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/attendance-schema.php";
require "../includes/attendance-scan-events-schema.php";
ensureAttendanceTimeOutColumn($conn);
ensureAttendanceScanEventsSchema($conn);
header("Content-Type: application/json");

$grade = $_GET["grade"] ?? "all";
$section = trim((string) ($_GET["section"] ?? "all"));
$filterDate = $_GET["date"] ?? date("Y-m-d");
$student = trim($_GET["student"] ?? "");
$page = max(1, (int) ($_GET["page"] ?? 1));
$pageSize = 15;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "message" => "Invalid date."]);
    exit();
}

$status = trim((string) ($_GET["status"] ?? "all"));

$where = ["COALESCE(s.ENROLLMENT_STATUS,'Enrolled')='Enrolled'"];
$types = "";
$params = [];
if (in_array((string) $grade, ["7", "8", "9", "10", "11", "12"], true)) {
    $where[] = "s.GRADE=?";
    $types .= "i";
    $params[] = (int) $grade;
}
if ($section !== "" && $section !== "all") {
    $where[] = "s.SECTION=?";
    $types .= "s";
    $params[] = $section;
}
if ($student !== "") {
    $where[] = "(s.STUDENT_ID LIKE ? OR s.STUDENT_NAME LIKE ? OR CONCAT_WS(' ',s.LAST_NAME,s.FIRST_NAME,s.MIDDLE_NAME) LIKE ? OR CONCAT_WS(' ',s.FIRST_NAME,s.MIDDLE_NAME,s.LAST_NAME) LIKE ?)";
    $like = "%" . $student . "%";
    $types .= "ssss";
    array_push($params, $like, $like, $like, $like);
}
if ($status === "recorded") {
    $where[] = "a.ID IS NOT NULL";
} elseif ($status === "not-recorded") {
    $where[] = "a.ID IS NULL";
} elseif ($status !== "" && $status !== "all" && in_array($status, attendanceRemarkOptions(), true)) {
    $where[] = "a.ATTENDANCE_REMARK=?";
    $types .= "s";
    $params[] = $status;
}
$whereSql = implode(" AND ", $where);

// The roster count is date-independent (every enrolled student is listed),
// but the attendance join is included so status filters resolve.
$countStmt = $conn->prepare(
    "SELECT COUNT(*) total FROM students s
       LEFT JOIN attendance a ON TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID) AND DATE(a.TIME_IN)=?
      WHERE {$whereSql}",
);
$countTypes = "s" . $types;
$countParams = array_merge([$filterDate], $params);
$countStmt->bind_param($countTypes, ...$countParams);
$countStmt->execute();
$total = (int) ($countStmt->get_result()->fetch_assoc()["total"] ?? 0);
$pageCount = max(1, (int) ceil($total / $pageSize));
$page = min($page, $pageCount);
$offset = ($page - 1) * $pageSize;

$query =
    "SELECT a.ID ATTENDANCE_ID,s.STUDENT_ID,s.STUDENT_NAME,s.LAST_NAME,s.FIRST_NAME,s.MIDDLE_NAME,s.GRADE,s.SECTION,COALESCE(a.GRADE_SNAPSHOT,s.GRADE) DISPLAY_GRADE,COALESCE(NULLIF(a.SECTION_SNAPSHOT,''),s.SECTION) DISPLAY_SECTION,a.TIME_IN,a.LUNCH_OUT,a.AFTERNOON_TIME_IN,a.TIME_OUT,a.ATTENDANCE_REMARK,a.ATTENDANCE_NOTE
       FROM students s
       LEFT JOIN attendance a ON TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID) AND DATE(a.TIME_IN)=?
      WHERE {$whereSql}
      ORDER BY (a.TIME_IN IS NOT NULL AND TIME(a.TIME_IN)<>'00:00:00' AND COALESCE(a.ATTENDANCE_REMARK,'') NOT IN ('Absent','Excused absence')) DESC,s.GRADE,s.SECTION,s.LAST_NAME,s.FIRST_NAME,s.STUDENT_NAME
      LIMIT " . (int) $pageSize . " OFFSET " . (int) $offset . "
";
$listTypes = "s" . $types;
$listParams = array_merge([$filterDate], $params);
$stmt = $conn->prepare($query);
$stmt->bind_param($listTypes, ...$listParams);
$stmt->execute();
$result = $stmt->get_result();

$rows = "";
$count = 0;
while ($row = $result->fetch_assoc()):
    $count++;
    $hasRecord = (int) ($row["ATTENDANCE_ID"] ?? 0) > 0;
    $timeIn = $hasRecord && !empty($row["TIME_IN"]) ? new DateTime($row["TIME_IN"]) : null;
    $lunchOut = $hasRecord && !empty($row["LUNCH_OUT"]) ? new DateTime($row["LUNCH_OUT"]) : null;
    $afternoonTimeIn = $hasRecord && !empty($row["AFTERNOON_TIME_IN"]) ? new DateTime($row["AFTERNOON_TIME_IN"]) : null;
    $timeOut = $hasRecord && !empty($row["TIME_OUT"]) ? new DateTime($row["TIME_OUT"]) : null;
    $name =
        trim(
            ($row["LAST_NAME"] ?? "") .
                ", " .
                ($row["FIRST_NAME"] ?? "") .
                (!empty($row["MIDDLE_NAME"]) ? ", " . $row["MIDDLE_NAME"] : ""),
            ", ",
        ) ?: $row["STUDENT_NAME"];
    $remark = (string) ($row["ATTENDANCE_REMARK"] ?? "");
    $note = (string) ($row["ATTENDANCE_NOTE"] ?? "");
    $isAbsent = $hasRecord && attendanceIsAbsent($remark);
    $noTime = $isAbsent || $remark === "Excused absence";
    $status = $hasRecord
        ? attendanceStatusLabel($row["TIME_IN"], $row["TIME_OUT"] ?? null, $remark, $row["LUNCH_OUT"] ?? null, $row["AFTERNOON_TIME_IN"] ?? null)
        : "Not recorded";
    $statusClass = $hasRecord
        ? attendanceStatusClass($row["TIME_IN"], $row["TIME_OUT"] ?? null, $remark, $row["LUNCH_OUT"] ?? null, $row["AFTERNOON_TIME_IN"] ?? null)
        : "bg-slate-100 text-slate-600";

    $cells =
        '<td class="px-4 py-4 font-medium text-slate-700">' . htmlspecialchars($row["STUDENT_ID"]) . '</td>'
        . '<td class="px-4 py-4 font-bold text-slate-900">' . htmlspecialchars($name) . '</td>'
        . '<td class="px-4 py-4"><span class="rounded-full bg-violet-50 px-3 py-1 text-xs font-bold text-violet-700 whitespace-nowrap">Grade ' . htmlspecialchars($row["DISPLAY_GRADE"] ?? $row["GRADE"] ?? "N/A") . '</span></td>'
        . '<td class="px-4 py-4"><span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">' . htmlspecialchars($row["DISPLAY_SECTION"] ?? $row["SECTION"] ?? "N/A") . '</span></td>'
        . '<td class="px-4 py-4 text-slate-600">' . ($hasRecord ? $timeIn->format("M j, Y") : htmlspecialchars((new DateTime($filterDate))->format("M j, Y"))) . '</td>'
        . '<td class="px-4 py-4 text-slate-600">' . ($noTime || !$hasRecord ? "&mdash;" : $timeIn->format("g:i A")) . '</td>'
        . '<td class="px-4 py-4 text-slate-600">' . ($noTime || !$hasRecord ? "&mdash;" : ($lunchOut ? $lunchOut->format("g:i A") : "&mdash;")) . '</td>'
        . '<td class="px-4 py-4 text-slate-600">' . ($noTime || !$hasRecord ? "&mdash;" : ($afternoonTimeIn ? $afternoonTimeIn->format("g:i A") : "&mdash;")) . '</td>'
        . '<td class="px-4 py-4 text-slate-600">' . ($noTime || !$hasRecord ? "&mdash;" : ($timeOut ? $timeOut->format("g:i A") : "&mdash;")) . '</td>'
        . '<td class="px-4 py-4"><span class="block w-full rounded-xl px-2 py-1 text-center text-xs font-bold leading-tight ' . $statusClass . '">' . htmlspecialchars($status) . '</span></td>';

    $actionCell = '<td class="whitespace-nowrap px-4 py-4"><div class="flex flex-col items-start justify-center gap-1">';
    if (!$hasRecord) {
        $actionCell .= '<div class="flex items-center justify-start gap-2 w-full"><button type="button" data-attendance-edit data-id="" data-student-id="' . htmlspecialchars($row["STUDENT_ID"], ENT_QUOTES) . '" data-date="' . htmlspecialchars($filterDate, ENT_QUOTES) . '" data-remark="Excused absence" data-note="" class="inline-flex items-center justify-center h-7 px-2.5 text-xs font-medium rounded border border-blue-500/20 bg-blue-500/10 text-blue-600 hover:bg-blue-500/20">Update</button></div>';
    } elseif ($isAbsent) {
        $actionCell .= '<span class="text-[11px] font-bold leading-none text-rose-600">Marked absent</span>';
        $actionCell .= '<div class="flex items-center justify-start gap-2 w-full"><a class="inline-flex items-center justify-center h-7 px-2.5 text-xs font-medium rounded border border-blue-500/20 bg-blue-500/10 text-blue-600 hover:bg-blue-500/20" href="export-attendance.php?date=' . urlencode($filterDate) . '&section=' . urlencode($section) . '&student_id=' . urlencode($row["STUDENT_ID"]) . '">Export</a></div>';
    } else {
        $actionCell .= '<span class="block max-w-[15rem] truncate text-[11px] leading-none text-slate-400">' . ($note !== "" ? htmlspecialchars($note) : "No reason added") . '</span>';
        $actionCell .= '<div class="flex items-center justify-start gap-2 w-full"><button type="button" data-attendance-edit data-id="' . (int) $row["ATTENDANCE_ID"] . '" data-student-id="' . htmlspecialchars($row["STUDENT_ID"], ENT_QUOTES) . '" data-date="' . htmlspecialchars($filterDate, ENT_QUOTES) . '" data-remark="' . htmlspecialchars($remark, ENT_QUOTES) . '" data-note="' . htmlspecialchars($note, ENT_QUOTES) . '" class="inline-flex items-center justify-center h-7 px-2.5 text-xs font-medium rounded border border-blue-500/20 bg-blue-500/10 text-blue-600 hover:bg-blue-500/20">Update</button><a class="inline-flex items-center justify-center h-7 px-2.5 text-xs font-medium rounded border border-blue-500/20 bg-blue-500/10 text-blue-600 hover:bg-blue-500/20" href="export-attendance.php?date=' . urlencode($filterDate) . '&section=' . urlencode($section) . '&student_id=' . urlencode($row["STUDENT_ID"]) . '">Export</a></div>';
    }
    $actionCell .= '</div></td>';

    $rows .= '<tr class="transition hover:bg-slate-50">' . $cells . $actionCell . "</tr>";
endwhile;

if ($count === 0) {
    $rows =
        '<tr><td colspan="11" class="px-4 py-14 text-center"><p class="text-base font-black text-slate-800">No attendance records match the current filters.</p><p class="mt-1 text-sm text-slate-500">Try another date or adjust the grade, section, or student filter above.</p></td></tr>';
}

echo json_encode([
    "ok" => true,
    "rows" => $rows,
    "total" => $total,
    "page" => $page,
    "page_size" => $pageSize,
    "page_count" => $pageCount,
]);
