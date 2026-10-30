<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/attendance-schema.php';

ensureAttendanceTimeOutColumn($conn);
header('Content-Type: application/json');

$today = date('Y-m-d');
$statement = $conn->prepare(
    "SELECT a.ID, a.TIME_IN, a.LUNCH_OUT, a.AFTERNOON_TIME_IN, a.TIME_OUT,
            a.ATTENDANCE_UPDATED_AT, s.STUDENT_NAME, s.LAST_NAME, s.FIRST_NAME,
            s.MIDDLE_NAME, s.IMAGE_PATH
     FROM attendance a
     INNER JOIN students s ON TRIM(s.STUDENT_ID)=TRIM(a.STUDENT_ID)
     WHERE DATE(a.TIME_IN)=? AND COALESCE(a.ATTENDANCE_REMARK,'')<>'Absent'
     ORDER BY a.ATTENDANCE_UPDATED_AT DESC, a.ID DESC
     LIMIT 1",
);
$statement->bind_param('s', $today);
$statement->execute();
$row = $statement->get_result()->fetch_assoc();

if (!$row) {
    echo json_encode(['latestScan' => null]);
    exit;
}

$name = trim(
    ($row['LAST_NAME'] ?? '') . ', ' .
    ($row['FIRST_NAME'] ?? '') .
    (!empty($row['MIDDLE_NAME']) ? ' ' . $row['MIDDLE_NAME'] : ''),
    ', ',
) ?: ($row['STUDENT_NAME'] ?? 'Student');

$action = 'attendance_update';
$actionLabel = 'Attendance update';
if (($row['TIME_OUT'] ?? null) === ($row['ATTENDANCE_UPDATED_AT'] ?? null)) {
    $action = 'time_out';
    $actionLabel = 'Final Time Out';
} elseif (($row['AFTERNOON_TIME_IN'] ?? null) === ($row['ATTENDANCE_UPDATED_AT'] ?? null)) {
    $action = 'afternoon_time_in';
    $actionLabel = 'Afternoon Time In';
} elseif (($row['LUNCH_OUT'] ?? null) === ($row['ATTENDANCE_UPDATED_AT'] ?? null)) {
    $action = 'lunch_out';
    $actionLabel = 'Lunch Out';
} elseif (($row['TIME_IN'] ?? null) === ($row['ATTENDANCE_UPDATED_AT'] ?? null)) {
    $action = 'time_in';
    $actionLabel = 'Time In';
}

echo json_encode([
    'latestScan' => [
        'id' => (int) $row['ID'],
        // The actual attendance values avoid missing two updates in one timestamp second.
        'eventKey' => implode('|', [
            (int) $row['ID'],
            (string) ($row['TIME_IN'] ?? ''),
            (string) ($row['LUNCH_OUT'] ?? ''),
            (string) ($row['AFTERNOON_TIME_IN'] ?? ''),
            (string) ($row['TIME_OUT'] ?? ''),
        ]),
        'studentName' => $name,
        'imagePath' => (string) ($row['IMAGE_PATH'] ?? ''),
        'action' => $action,
        'actionLabel' => $actionLabel,
    ],
]);
