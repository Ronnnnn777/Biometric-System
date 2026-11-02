<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/attendance-schema.php";
require "../includes/attendance-scan-events-schema.php";
require "../includes/school-calendar-schema.php";
ensureAttendanceTimeOutColumn($conn);
ensureAttendanceScanEventsSchema($conn);
ensureSchoolCalendarSchema($conn);
header("Content-Type: application/json");

$date = $_GET["date"] ?? date("Y-m-d");
$grade = (string) ($_GET["grade"] ?? "all");
$section = trim((string) ($_GET["section"] ?? "all"));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "message" => "Invalid date."]);
    exit();
}
$start = $date . " 00:00:00";
$end = date("Y-m-d", strtotime($date . " +1 day")) . " 00:00:00";

$gradeFilter = in_array($grade, ["7", "8", "9", "10", "11", "12"], true) ? (int) $grade : null;
$sectionFilter = $section !== "" && $section !== "all" ? $section : null;

$day = schoolDayInfo($conn, $date);

/* Scanned today: distinct students with at least one successful scan. */
$sql =
    "SELECT COUNT(DISTINCT e.STUDENT_ID) total FROM attendance_scan_events e
      WHERE e.SCAN_RESULT='success' AND e.STUDENT_ID IS NOT NULL
        AND e.SCANNED_AT>=? AND e.SCANNED_AT<?";
$types = "ss";
$params = [$start, $end];
if ($gradeFilter !== null) {
    $sql .= " AND e.GRADE_SNAPSHOT=?";
    $types .= "i";
    $params[] = $gradeFilter;
}
if ($sectionFilter !== null) {
    $sql .= " AND e.SECTION_SNAPSHOT=?";
    $types .= "s";
    $params[] = $sectionFilter;
}
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$scannedToday = (int) ($stmt->get_result()->fetch_assoc()["total"] ?? 0);

/* Eligible roster for the date (current standing placement). */
$sql =
    "SELECT COUNT(*) total FROM students s
      WHERE COALESCE(s.ENROLLMENT_STATUS,'Enrolled')='Enrolled'";
$types = "";
$params = [];
if ($gradeFilter !== null) {
    $sql .= " AND s.GRADE=?";
    $types .= "i";
    $params[] = $gradeFilter;
}
if ($sectionFilter !== null) {
    $sql .= " AND s.SECTION=?";
    $types .= "s";
    $params[] = $sectionFilter;
}
$stmt = $conn->prepare($sql);
if ($types !== "") {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$eligible = (int) ($stmt->get_result()->fetch_assoc()["total"] ?? 0);

/* Not scanned only makes sense on an instructional day. */
$notScanned = $day["is_school_day"] ? max(0, $eligible - $scannedToday) : null;

/* Needs review = incomplete records (after the finalize cutoff) + rejected scans. */
$needsReview = 0;
$incomplete = 0;
$rejected = 0;
if ($day["is_school_day"]) {
    $finalized = $date < date("Y-m-d") || date("H:i:s") >= ATTENDANCE_ABSENCE_FINALIZE_AT;
    if ($finalized) {
        $sql =
            "SELECT COUNT(*) total FROM attendance a
               LEFT JOIN students s ON TRIM(s.STUDENT_ID)=TRIM(a.STUDENT_ID)
              WHERE DATE(a.TIME_IN)=? AND TIME(a.TIME_IN)<>'00:00:00'
                AND a.TIME_OUT IS NULL
                AND COALESCE(a.ATTENDANCE_REMARK,'') NOT IN ('Absent','Excused absence')";
        $types = "s";
        $params = [$date];
        if ($gradeFilter !== null) {
            $sql .= " AND COALESCE(a.GRADE_SNAPSHOT,s.GRADE)=?";
            $types .= "i";
            $params[] = $gradeFilter;
        }
        if ($sectionFilter !== null) {
            $sql .= " AND COALESCE(NULLIF(a.SECTION_SNAPSHOT,''),s.SECTION)=?";
            $types .= "s";
            $params[] = $sectionFilter;
        }
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $incomplete = (int) ($stmt->get_result()->fetch_assoc()["total"] ?? 0);
    }

    $sql =
        "SELECT COUNT(*) total FROM attendance_scan_events e
          WHERE e.SCAN_RESULT IN ('rejected','ambiguous')
            AND e.SCANNED_AT>=? AND e.SCANNED_AT<?";
    $types = "ss";
    $params = [$start, $end];
    if ($gradeFilter !== null) {
        $sql .= " AND e.GRADE_SNAPSHOT=?";
        $types .= "i";
        $params[] = $gradeFilter;
    }
    if ($sectionFilter !== null) {
        $sql .= " AND e.SECTION_SNAPSHOT=?";
        $types .= "s";
        $params[] = $sectionFilter;
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rejected = (int) ($stmt->get_result()->fetch_assoc()["total"] ?? 0);

    $needsReview = $incomplete + $rejected;
}

$typeLabel = schoolCalendarTypeLabel($day["type"]);
$dayLabel = $typeLabel;
if ($day["label"] !== "" && $day["label"] !== $typeLabel) {
    $dayLabel .= " · " . $day["label"];
}

echo json_encode([
    "ok" => true,
    "date" => $date,
    "is_school_day" => $day["is_school_day"],
    "day_type" => $day["type"],
    "day_label" => $dayLabel,
    "scanned_today" => $scannedToday,
    "not_scanned" => $notScanned,
    "needs_review" => $needsReview,
    "needs_review_breakdown" => ["incomplete" => $incomplete, "rejected" => $rejected],
    "eligible" => $eligible,
    "activation_date" => attendanceScanEventsActivationDate($conn),
]);
