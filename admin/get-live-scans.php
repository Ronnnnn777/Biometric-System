<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/attendance-schema.php";
require "../includes/attendance-scan-events-schema.php";
ensureAttendanceTimeOutColumn($conn);
ensureAttendanceScanEventsSchema($conn);
header("Content-Type: application/json");

$date = $_GET["date"] ?? date("Y-m-d");
$grade = (string) ($_GET["grade"] ?? "all");
$section = trim((string) ($_GET["section"] ?? "all"));
$student = trim((string) ($_GET["student"] ?? ""));
$page = max(1, (int) ($_GET["page"] ?? 1));
$pageSize = 15;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "message" => "Invalid date."]);
    exit();
}

$start = $date . " 00:00:00";
$end = date("Y-m-d", strtotime($date . " +1 day")) . " 00:00:00";

// Filter fragments shared by the count and the page query.
$filters = [];
$types = "ss"; // inner latest subquery (start, end)
$params = [$start, $end];
if (in_array($grade, ["7", "8", "9", "10", "11", "12"], true)) {
    $filters[] = "e.GRADE_SNAPSHOT=?";
    $types .= "i";
    $params[] = (int) $grade;
}
if ($section !== "" && $section !== "all") {
    $filters[] = "e.SECTION_SNAPSHOT=?";
    $types .= "s";
    $params[] = $section;
}
if ($student !== "") {
    $filters[] = "(s.STUDENT_ID LIKE ? OR s.STUDENT_NAME LIKE ? OR CONCAT_WS(' ',s.LAST_NAME,s.FIRST_NAME,s.MIDDLE_NAME) LIKE ? OR CONCAT_WS(' ',s.FIRST_NAME,s.MIDDLE_NAME,s.LAST_NAME) LIKE ?)";
    $like = "%" . $student . "%";
    $types .= "ssss";
    array_push($params, $like, $like, $like, $like);
}
$filterSql = $filters ? " AND " . implode(" AND ", $filters) : "";

// One row per student: their latest successful scan for the day.
$latestJoin =
    "INNER JOIN (SELECT STUDENT_ID, MAX(ID) LAST_ID FROM attendance_scan_events
                   WHERE SCAN_RESULT='success' AND STUDENT_ID IS NOT NULL
                     AND SCANNED_AT>=? AND SCANNED_AT<?
                   GROUP BY STUDENT_ID) latest ON latest.LAST_ID=e.ID";

$countSql =
    "SELECT COUNT(*) total FROM (
        SELECT e.STUDENT_ID
          FROM attendance_scan_events e
          {$latestJoin}
          LEFT JOIN students s ON TRIM(s.STUDENT_ID)=TRIM(e.STUDENT_ID)
         WHERE e.SCAN_RESULT='success' AND e.STUDENT_ID IS NOT NULL{$filterSql}
         GROUP BY e.STUDENT_ID
     ) t";
$countStmt = $conn->prepare($countSql);
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = (int) ($countStmt->get_result()->fetch_assoc()["total"] ?? 0);
$pageCount = max(1, (int) ceil($total / $pageSize));
$page = min($page, $pageCount);
$offset = ($page - 1) * $pageSize;

$listSql =
    "SELECT e.ID EVENT_ID, e.STUDENT_ID, e.EVENT_TYPE, e.SCANNED_AT, e.SCANNER_SOURCE,
            e.SCANNER_NAME, e.SCANNER_ID, e.GRADE_SNAPSHOT, e.SECTION_SNAPSHOT,
            s.STUDENT_NAME, s.LAST_NAME, s.FIRST_NAME, s.MIDDLE_NAME,
            a.ID ATTENDANCE_ID, a.TIME_IN, a.LUNCH_OUT, a.AFTERNOON_TIME_IN, a.TIME_OUT, a.ATTENDANCE_REMARK
       FROM attendance_scan_events e
       {$latestJoin}
       LEFT JOIN students s ON TRIM(s.STUDENT_ID)=TRIM(e.STUDENT_ID)
       LEFT JOIN attendance a ON TRIM(a.STUDENT_ID)=TRIM(e.STUDENT_ID) AND DATE(a.TIME_IN)='$date'
      WHERE e.SCAN_RESULT='success' AND e.STUDENT_ID IS NOT NULL{$filterSql}
      ORDER BY e.SCANNED_AT DESC, e.ID DESC
      LIMIT " . (int) $pageSize . " OFFSET " . (int) $offset . "
";
$listTypes = $types;
$listParams = $params;
$listStmt = $conn->prepare($listSql);
$listStmt->bind_param($listTypes, ...$listParams);
$listStmt->execute();
$result = $listStmt->get_result();

$eventLabels = [
    "time_in" => "Morning In",
    "lunch_out" => "Lunch Out",
    "afternoon_time_in" => "Afternoon In",
    "time_out" => "Final Out",
];

$rows = "";
$count = 0;
while ($row = $result->fetch_assoc()) {
    $count++;
    $name =
        trim(
            ($row["LAST_NAME"] ?? "") .
                ", " .
                ($row["FIRST_NAME"] ?? "") .
                (!empty($row["MIDDLE_NAME"]) ? ", " . $row["MIDDLE_NAME"] : ""),
            ", ",
        ) ?: (string) ($row["STUDENT_NAME"] ?? "");
    $scanTime = new DateTime($row["SCANNED_AT"]);
    $eventLabel = $eventLabels[$row["EVENT_TYPE"]] ?? ucfirst((string) $row["EVENT_TYPE"]);
    $hasRecord = (int) ($row["ATTENDANCE_ID"] ?? 0) > 0;
    $status = $hasRecord
        ? attendanceStatusLabel(
            $row["TIME_IN"],
            $row["TIME_OUT"] ?? null,
            (string) ($row["ATTENDANCE_REMARK"] ?? ""),
            $row["LUNCH_OUT"] ?? null,
            $row["AFTERNOON_TIME_IN"] ?? null,
        )
        : "—";
    $statusClass = $hasRecord
        ? attendanceStatusClass(
            $row["TIME_IN"],
            $row["TIME_OUT"] ?? null,
            (string) ($row["ATTENDANCE_REMARK"] ?? ""),
            $row["LUNCH_OUT"] ?? null,
            $row["AFTERNOON_TIME_IN"] ?? null,
        )
        : "bg-slate-100 text-slate-500";
    $sourceLabel = attendanceScannerSourceLabel(
        (string) $row["SCANNER_SOURCE"],
        (string) $row["SCANNER_NAME"],
    );
    $rowGrade = $row["GRADE_SNAPSHOT"] ?? "—";
    $rowSection = $row["SECTION_SNAPSHOT"] ?? "—";

    $rowKey = htmlspecialchars(
        (string) $row["STUDENT_ID"] . "|" . (string) $row["SCANNED_AT"] . "|" . (int) $row["EVENT_ID"],
        ENT_QUOTES,
    );
    $rows .=
        '<tr class="transition hover:bg-slate-50" data-key="' . $rowKey . '">'
        . '<td class="px-4 py-4 font-bold text-slate-900">' . htmlspecialchars($name) . '</td>'
        . '<td class="px-4 py-4 font-medium text-slate-700">' . htmlspecialchars((string) $row["STUDENT_ID"]) . '</td>'
        . '<td class="px-4 py-4"><span class="rounded-full bg-violet-50 px-3 py-1 text-xs font-bold text-violet-700 whitespace-nowrap">Grade ' . htmlspecialchars((string) $rowGrade) . '</span></td>'
        . '<td class="px-4 py-4"><span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">' . htmlspecialchars((string) $rowSection) . '</span></td>'
        . '<td class="px-4 py-4"><span class="block text-sm font-black text-slate-900 whitespace-nowrap">' . htmlspecialchars($scanTime->format("g:i:s A")) . '</span><span class="text-[11px] text-slate-500">' . htmlspecialchars($scanTime->format("M j, Y")) . '</span></td>'
        . '<td class="px-4 py-4"><span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700 whitespace-nowrap">' . htmlspecialchars($eventLabel) . '</span></td>'
        . '<td class="px-4 py-4"><span class="block w-full rounded-xl px-2 py-1 text-center text-xs font-bold leading-tight ' . $statusClass . '">' . htmlspecialchars($status) . '</span></td>'
        . '<td class="px-4 py-4 text-slate-600 whitespace-nowrap">' . htmlspecialchars($sourceLabel) . '</td>'
        . '<td class="px-4 py-4 whitespace-nowrap"><button type="button" data-scan-details data-student-id="' . htmlspecialchars((string) $row["STUDENT_ID"], ENT_QUOTES) . '" data-name="' . htmlspecialchars($name, ENT_QUOTES) . '" data-event="' . htmlspecialchars($eventLabel, ENT_QUOTES) . '" data-time="' . htmlspecialchars($scanTime->format("M j, Y g:i:s A"), ENT_QUOTES) . '" data-source="' . htmlspecialchars($sourceLabel, ENT_QUOTES) . '" data-result="Successful" data-grade="' . htmlspecialchars((string) $rowGrade, ENT_QUOTES) . '" data-section="' . htmlspecialchars((string) $rowSection, ENT_QUOTES) . '" class="inline-flex items-center justify-center h-7 px-2.5 text-xs font-medium rounded border border-blue-500/20 bg-blue-500/10 text-blue-600 hover:bg-blue-500/20">View details</button></td>'
        . "</tr>";
}

if ($count === 0) {
    $rows =
        '<tr><td colspan="9" class="px-4 py-14 text-center"><p class="text-base font-black text-slate-800">No scans recorded yet.</p><p class="mt-1 text-sm text-slate-500">Successful biometric scans for this date will appear here.</p></td></tr>';
}

echo json_encode([
    "ok" => true,
    "rows" => $rows,
    "total" => $total,
    "page" => $page,
    "page_size" => $pageSize,
    "page_count" => $pageCount,
]);
