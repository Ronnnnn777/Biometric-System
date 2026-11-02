<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/attendance-scan-events-schema.php";
ensureAttendanceScanEventsSchema($conn);
header("Content-Type: application/json");

// A filter-aware cursor. A global MAX(ID) would miss events that fall outside
// the active grade/section/student scope, so the cursor is computed inside the
// same scope the Live Scans tab is showing.
$date = $_GET["date"] ?? date("Y-m-d");
$grade = (string) ($_GET["grade"] ?? "all");
$section = trim((string) ($_GET["section"] ?? "all"));
$student = trim((string) ($_GET["student"] ?? ""));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "message" => "Invalid date."]);
    exit();
}
$start = $date . " 00:00:00";
$end = date("Y-m-d", strtotime($date . " +1 day")) . " 00:00:00";

$sql =
    "SELECT MAX(e.ID) latest_id, COUNT(DISTINCT e.STUDENT_ID) success_total, MAX(e.SCANNED_AT) latest_time
       FROM attendance_scan_events e
       LEFT JOIN students s ON TRIM(s.STUDENT_ID)=TRIM(e.STUDENT_ID)
      WHERE e.SCAN_RESULT='success' AND e.STUDENT_ID IS NOT NULL
        AND e.SCANNED_AT>=? AND e.SCANNED_AT<?";
$types = "ss";
$params = [$start, $end];
if (in_array($grade, ["7", "8", "9", "10", "11", "12"], true)) {
    $sql .= " AND e.GRADE_SNAPSHOT=?";
    $types .= "i";
    $params[] = (int) $grade;
}
if ($section !== "" && $section !== "all") {
    $sql .= " AND e.SECTION_SNAPSHOT=?";
    $types .= "s";
    $params[] = $section;
}
if ($student !== "") {
    $sql .= " AND (s.STUDENT_ID LIKE ? OR s.STUDENT_NAME LIKE ? OR CONCAT_WS(' ',s.LAST_NAME,s.FIRST_NAME,s.MIDDLE_NAME) LIKE ? OR CONCAT_WS(' ',s.FIRST_NAME,s.MIDDLE_NAME,s.LAST_NAME) LIKE ?)";
    $like = "%" . $student . "%";
    $types .= "ssss";
    array_push($params, $like, $like, $like, $like);
}
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

echo json_encode([
    "ok" => true,
    "latest_id" => (int) ($row["latest_id"] ?? 0),
    "success_total" => (int) ($row["success_total"] ?? 0),
    "latest_time" => (string) ($row["latest_time"] ?? ""),
]);
