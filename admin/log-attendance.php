<?php
require "../config/db_conn.php";
require "../includes/attendance-schema.php";
require "../includes/attendance-scan-events-schema.php";
date_default_timezone_set('Asia/Manila');
header("Content-Type: application/json");

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// This endpoint writes attendance, so it must never be callable anonymously.
// It previously accepted any POST (see SY.md issue C4). It is now limited to an
// authenticated administrator scanner session and requires the session CSRF
// token. The webcam scanner uses admin/scan-match.php; this route is kept for
// backward compatibility but is no longer a public write path.
if (
    empty($_SESSION["is-authenticated"]) ||
    ($_SESSION["user-role"] ?? "") !== "administrator"
) {
    http_response_code(401);
    echo json_encode([
        "ok" => false,
        "message" => "Sign in as an administrator to record attendance.",
    ]);
    exit();
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    http_response_code(405);
    echo json_encode(["ok" => false, "message" => "POST is required."]);
    exit();
}

if (
    !hash_equals(
        $_SESSION["csrf_token"] ?? "",
        $_POST["csrf_token"] ?? "",
    )
) {
    http_response_code(403);
    echo json_encode([
        "ok" => false,
        "message" => "The request expired. Reload the scanner and try again.",
    ]);
    exit();
}

ensureAttendanceTimeOutColumn($conn);
ensureAttendanceScanEventsSchema($conn);

$studentId = trim((string) ($_POST["student_id"] ?? ""));
if ($studentId === "") {
    http_response_code(422);
    echo json_encode(["ok" => false, "message" => "Missing student id."]);
    exit();
}

$lookup = $conn->prepare(
    "SELECT STUDENT_ID, STUDENT_NAME, GRADE, STRAND, SECTION, COALESCE(ENROLLMENT_STATUS,'Enrolled') ENROLLMENT_STATUS
       FROM students WHERE TRIM(STUDENT_ID) = TRIM(?) LIMIT 1",
);
$lookup->bind_param("s", $studentId);
$lookup->execute();
$student = $lookup->get_result()->fetch_assoc();
if (!$student) {
    http_response_code(404);
    echo json_encode(["ok" => false, "message" => "Student not found."]);
    exit();
}
if ($student["ENROLLMENT_STATUS"] !== "Enrolled") {
    http_response_code(422);
    echo json_encode([
        "ok" => false,
        "message" =>
            "This student is " .
            strtolower($student["ENROLLMENT_STATUS"]) .
            " and cannot record attendance.",
    ]);
    exit();
}

$today = date("Y-m-d");
$scannerId = (string) ($_SESSION["scanner_id"] ?? "");
$scannerName = (string) ($_SESSION["scanner_name"] ?? "Webcam");

$existing = $conn->prepare(
    "SELECT ID FROM attendance WHERE TRIM(STUDENT_ID)=TRIM(?) AND DATE(TIME_IN)=? LIMIT 1",
);
$existing->bind_param("ss", $student["STUDENT_ID"], $today);
$existing->execute();
if ($existing->get_result()->fetch_assoc()) {
    // Duplicate protection: never create a second row for the same school day.
    echo json_encode([
        "ok" => true,
        "logged" => false,
        "message" => "Already logged today.",
    ]);
    exit();
}

try {
    $conn->begin_transaction();
    $insert = $conn->prepare(
        "INSERT INTO attendance
            (STUDENT_ID, STUDENT_NAME, TIME_IN, GRADE_SNAPSHOT, STRAND_SNAPSHOT, SECTION_SNAPSHOT)
         VALUES (?, ?, NOW(), ?, ?, ?)",
    );
    $insert->bind_param(
        "ssiss",
        $student["STUDENT_ID"],
        $student["STUDENT_NAME"],
        $student["GRADE"],
        $student["STRAND"],
        $student["SECTION"],
    );
    $insert->execute();
    $attendanceId = (int) $conn->insert_id;
    logAttendanceScanEvent($conn, [
        "attendance_id" => $attendanceId,
        "student_id" => $student["STUDENT_ID"],
        "event_type" => "time_in",
        "result" => ATTENDANCE_SCAN_RESULT_SUCCESS,
        "scanner_source" => "webcam",
        "scanner_id" => $scannerId,
        "scanner_name" => $scannerName,
        "detail" => "Time In recorded.",
        "grade" => $student["GRADE"],
        "strand" => $student["STRAND"],
        "section" => $student["SECTION"],
    ]);
    $conn->commit();
    echo json_encode([
        "ok" => true,
        "logged" => true,
        "message" => "Attendance logged for " . $student["STUDENT_NAME"],
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    error_log("log_attendance_failed: " . $error->getMessage());
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "message" => "Could not save attendance. Please try again.",
    ]);
}
