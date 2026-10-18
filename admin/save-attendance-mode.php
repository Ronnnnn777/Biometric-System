<?php
session_start();
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/attendance-schema.php';

header('Content-Type: application/json');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['saved' => false, 'message' => 'Invalid request.']);
    exit;
}

$requestedMode = $_POST['attendance_mode'] ?? '';
$halfDay = in_array($requestedMode, ['half', 'whole'], true)
    ? $requestedMode === 'half'
    : isset($_POST['half_day_enabled']);
setWholeDayAttendance($conn, !$halfDay);
$wholeDay = attendanceUsesWholeDay($conn);
if ($wholeDay === $halfDay) {
    http_response_code(500);
    echo json_encode(['saved' => false, 'message' => 'Attendance mode could not be saved.']);
    exit;
}
echo json_encode(['saved' => true, 'half_day' => !$wholeDay, 'whole_day' => $wholeDay]);
?>
