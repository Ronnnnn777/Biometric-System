<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/attendance-schema.php";
require "../includes/attendance-scan-events-schema.php";
require "../includes/xlsx-export.php";
ensureAttendanceScanEventsSchema($conn);

$dateFrom = $_GET["date_from"] ?? date("Y-m-d");
$dateTo = $_GET["date_to"] ?? $dateFrom;
$grade = (string) ($_GET["grade"] ?? "all");
$section = trim((string) ($_GET["section"] ?? "all"));
$student = trim((string) ($_GET["student"] ?? ""));
$source = trim((string) ($_GET["source"] ?? "all"));
$resultFilter = trim((string) ($_GET["result"] ?? "all"));

foreach ([$dateFrom, $dateTo] as $value) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        http_response_code(400);
        exit("Invalid date range.");
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

$stmt = $conn->prepare(
    "SELECT e.STUDENT_ID, e.EVENT_TYPE, e.SCAN_RESULT, e.SCANNED_AT, e.SCANNER_SOURCE,
            e.SCANNER_NAME, e.GRADE_SNAPSHOT, e.SECTION_SNAPSHOT, e.DETAIL,
            s.STUDENT_NAME, s.LAST_NAME, s.FIRST_NAME, s.MIDDLE_NAME
       FROM attendance_scan_events e
       LEFT JOIN students s ON TRIM(s.STUDENT_ID)=TRIM(e.STUDENT_ID)
      WHERE e.SCANNED_AT>=? AND e.SCANNED_AT<?{$filterSql}
      ORDER BY e.SCANNED_AT DESC, e.ID DESC",
);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$eventLabels = [
    "time_in" => "Morning In",
    "lunch_out" => "Lunch Out",
    "afternoon_time_in" => "Afternoon In",
    "time_out" => "Final Out",
    "ignored" => "Ignored",
    "unrecognized" => "Unrecognized face",
];
$resultLabels = [
    "success" => "Successful",
    "rejected" => "Rejected",
    "cooldown" => "Cooldown",
    "duplicate" => "Duplicate",
    "ignored" => "Ignored",
    "ambiguous" => "Ambiguous",
    "unrecognized" => "Unrecognized",
];

$rows = [];
while ($row = $result->fetch_assoc()) {
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
        $lrn = "";
    }
    $scanTime = new DateTime($row["SCANNED_AT"]);
    $rows[] = [
        $scanTime->format("Y-m-d"),
        $scanTime->format("g:i:s A"),
        $lrn,
        $name,
        $row["GRADE_SNAPSHOT"] ?? "",
        $row["SECTION_SNAPSHOT"] ?? "",
        $eventLabels[$row["EVENT_TYPE"]] ?? ucfirst((string) $row["EVENT_TYPE"]),
        $resultLabels[$row["SCAN_RESULT"]] ?? ucfirst((string) $row["SCAN_RESULT"]),
        attendanceScannerSourceLabel((string) $row["SCANNER_SOURCE"], (string) $row["SCANNER_NAME"]),
        (string) ($row["DETAIL"] ?? ""),
    ];
}

$rangePart = $dateFrom === $dateTo ? $dateFrom : $dateFrom . "_to_" . $dateTo;
downloadAttendanceXlsx(
    "scan-events-" . preg_replace('/[^A-Za-z0-9_-]/', "_", $rangePart) . ".xlsx",
    ["Date", "Time", "LRN", "Student Name", "Grade", "Section", "Event", "Result", "Scanner", "Detail"],
    $rows,
);
