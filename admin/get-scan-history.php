<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/attendance-schema.php";
require "../includes/attendance-scan-events-schema.php";
ensureAttendanceTimeOutColumn($conn);
ensureAttendanceScanEventsSchema($conn);
header("Content-Type: application/json");

$dateFrom = $_GET["date_from"] ?? date("Y-m-d");
$dateTo = $_GET["date_to"] ?? $dateFrom;
$grade = (string) ($_GET["grade"] ?? "all");
$section = trim((string) ($_GET["section"] ?? "all"));
$student = trim((string) ($_GET["student"] ?? ""));
$source = trim((string) ($_GET["source"] ?? "all"));
$resultFilter = trim((string) ($_GET["result"] ?? "all"));
$page = max(1, (int) ($_GET["page"] ?? 1));
$pageSize = 15;

foreach (['date_from' => $dateFrom, 'date_to' => $dateTo] as $value) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "message" => "Invalid date range."]);
        exit();
    }
}
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
$start = $dateFrom . " 00:00:00";
$end = date("Y-m-d", strtotime($dateTo . " +1 day")) . " 00:00:00";

$filters = [];
$types = "ss";
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
if ($source !== "" && $source !== "all") {
    $filters[] = "e.SCANNER_SOURCE=?";
    $types .= "s";
    $params[] = $source;
}
if ($resultFilter !== "" && $resultFilter !== "all") {
    $filters[] = "e.SCAN_RESULT=?";
    $types .= "s";
    $params[] = $resultFilter;
}
if ($student !== "") {
    $filters[] = "(s.STUDENT_ID LIKE ? OR s.STUDENT_NAME LIKE ? OR CONCAT_WS(' ',s.LAST_NAME,s.FIRST_NAME,s.MIDDLE_NAME) LIKE ? OR CONCAT_WS(' ',s.FIRST_NAME,s.MIDDLE_NAME,s.LAST_NAME) LIKE ?)";
    $like = "%" . $student . "%";
    $types .= "ssss";
    array_push($params, $like, $like, $like, $like);
}
$filterSql = $filters ? " AND " . implode(" AND ", $filters) : "";

$countStmt = $conn->prepare(
    "SELECT COUNT(*) total FROM attendance_scan_events e
       LEFT JOIN students s ON TRIM(s.STUDENT_ID)=TRIM(e.STUDENT_ID)
      WHERE e.SCANNED_AT>=? AND e.SCANNED_AT<?{$filterSql}",
);
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = (int) ($countStmt->get_result()->fetch_assoc()["total"] ?? 0);
$pageCount = max(1, (int) ceil($total / $pageSize));
$page = min($page, $pageCount);
$offset = ($page - 1) * $pageSize;

$listStmt = $conn->prepare(
    "SELECT e.ID, e.STUDENT_ID, e.EVENT_TYPE, e.SCAN_RESULT, e.SCANNED_AT, e.SCANNER_SOURCE,
            e.SCANNER_NAME, e.GRADE_SNAPSHOT, e.SECTION_SNAPSHOT, e.DETAIL,
            s.STUDENT_NAME, s.LAST_NAME, s.FIRST_NAME, s.MIDDLE_NAME
       FROM attendance_scan_events e
       LEFT JOIN students s ON TRIM(s.STUDENT_ID)=TRIM(e.STUDENT_ID)
      WHERE e.SCANNED_AT>=? AND e.SCANNED_AT<?{$filterSql}
      ORDER BY e.SCANNED_AT DESC, e.ID DESC
      LIMIT " . (int) $pageSize . " OFFSET " . (int) $offset . "
",
);
$listTypes = $types;
$listStmt->bind_param($listTypes, ...$params);
$listStmt->execute();
$result = $listStmt->get_result();

$eventLabels = [
    "time_in" => "Morning In",
    "lunch_out" => "Lunch Out",
    "afternoon_time_in" => "Afternoon In",
    "time_out" => "Final Out",
    "ignored" => "Ignored",
    "unrecognized" => "Unrecognized face",
];
$resultLabels = [
    "success" => ["Successful", "bg-emerald-50 text-emerald-700"],
    "rejected" => ["Rejected", "bg-rose-50 text-rose-700"],
    "cooldown" => ["Cooldown", "bg-amber-50 text-amber-700"],
    "duplicate" => ["Duplicate", "bg-slate-100 text-slate-600"],
    "ignored" => ["Ignored", "bg-slate-100 text-slate-600"],
    "ambiguous" => ["Ambiguous", "bg-amber-50 text-amber-700"],
    "unrecognized" => ["Unrecognized", "bg-rose-50 text-rose-700"],
];

$rows = "";
$count = 0;
while ($row = $result->fetch_assoc()) {
    $count++;
    if (!empty($row["STUDENT_ID"])) {
        $name =
            trim(
                ($row["LAST_NAME"] ?? "") .
                    ", " .
                    ($row["FIRST_NAME"] ?? "") .
                    (!empty($row["MIDDLE_NAME"]) ? ", " . $row["MIDDLE_NAME"] : ""),
                ", ",
            ) ?: (string) ($row["STUDENT_NAME"] ?? "");
        $lrn = (string) $row["STUDENT_ID"];
    } else {
        $name = "Unidentified face";
        $lrn = "—";
    }
    $scanTime = new DateTime($row["SCANNED_AT"]);
    $eventLabel = $eventLabels[$row["EVENT_TYPE"]] ?? ucfirst((string) $row["EVENT_TYPE"]);
    [$resultLabel, $resultClass] = $resultLabels[$row["SCAN_RESULT"]] ?? [ucfirst((string) $row["SCAN_RESULT"]), "bg-slate-100 text-slate-600"];
    $sourceLabel = attendanceScannerSourceLabel((string) $row["SCANNER_SOURCE"], (string) $row["SCANNER_NAME"]);
    $rowGrade = ($row["GRADE_SNAPSHOT"] ?? "") !== "" ? $row["GRADE_SNAPSHOT"] : "—";
    $rowSection = ($row["SECTION_SNAPSHOT"] ?? "") !== "" ? $row["SECTION_SNAPSHOT"] : "—";

    $rows .=
        '<tr class="transition hover:bg-slate-50">'
        . '<td class="px-4 py-4"><span class="block text-sm font-black text-slate-900 whitespace-nowrap">' . htmlspecialchars($scanTime->format("g:i:s A")) . '</span><span class="text-[11px] text-slate-500">' . htmlspecialchars($scanTime->format("M j, Y")) . '</span></td>'
        . '<td class="px-4 py-4 font-bold text-slate-900">' . htmlspecialchars($name) . '</td>'
        . '<td class="px-4 py-4 font-medium text-slate-700">' . htmlspecialchars($lrn) . '</td>'
        . '<td class="px-4 py-4"><span class="rounded-full bg-violet-50 px-3 py-1 text-xs font-bold text-violet-700 whitespace-nowrap">Grade ' . htmlspecialchars((string) $rowGrade) . '</span></td>'
        . '<td class="px-4 py-4"><span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">' . htmlspecialchars((string) $rowSection) . '</span></td>'
        . '<td class="px-4 py-4 text-slate-700 whitespace-nowrap">' . htmlspecialchars($eventLabel) . '</td>'
        . '<td class="px-4 py-4"><span class="rounded-full px-3 py-1 text-xs font-bold whitespace-nowrap ' . $resultClass . '">' . htmlspecialchars($resultLabel) . '</span></td>'
        . '<td class="px-4 py-4 text-slate-600 whitespace-nowrap">' . htmlspecialchars($sourceLabel) . '</td>'
        . '<td class="px-4 py-4 text-slate-500">' . htmlspecialchars(mb_substr((string) $row["DETAIL"], 0, 80)) . '</td>'
        . "</tr>";
}

$activation = attendanceScanEventsActivationDate($conn);
$notice = "";
if ($count === 0) {
    $rows =
        '<tr><td colspan="9" class="px-4 py-14 text-center"><p class="text-base font-black text-slate-800">No scan events match the current filters.</p><p class="mt-1 text-sm text-slate-500">Adjust the date range, grade, section, or result filter.</p></td></tr>';
    if ($activation !== null && $dateTo < $activation) {
        $notice = "Verified scan events are only available from " . $activation . " onward.";
    }
} elseif ($activation !== null && $dateFrom < $activation) {
    $notice = "Verified scan events are only available from " . $activation . " onward. Earlier attendance was not produced by the scanner and is not listed here.";
}

echo json_encode([
    "ok" => true,
    "rows" => $rows,
    "total" => $total,
    "page" => $page,
    "page_size" => $pageSize,
    "page_count" => $pageCount,
    "notice" => $notice,
    "activation_date" => $activation,
]);
