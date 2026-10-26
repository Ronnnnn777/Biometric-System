<?php
    require 'page-guard.php';
    // Connect to your existing database config
    require "../config/db_conn.php";
    require '../includes/attendance-schema.php';
    require '../includes/mobile-scanner-settings.php';
    ensureAttendanceTimeOutColumn($conn);
    header('Content-Type: application/json');

    // 1. Get Total Students count
    $total = $conn->query("SELECT COUNT(*) AS total FROM students")->fetch_assoc()["total"];

    // 2. Get Today's Attendance count (Unique students only)
    $today = date('Y-m-d');
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT STUDENT_ID) AS total_today FROM attendance WHERE DATE(TIME_IN) = ? AND COALESCE(ATTENDANCE_REMARK,'')<>'Absent'");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $today_count = $stmt->get_result()->fetch_assoc()["total_today"];

    // 3. Count students whose attendance has been completed today.
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT STUDENT_ID) AS total_time_out FROM attendance WHERE DATE(TIME_IN) = ? AND TIME_OUT IS NOT NULL AND COALESCE(ATTENDANCE_REMARK,'')<>'Absent'");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $time_out_count = $stmt->get_result()->fetch_assoc()["total_time_out"];

    // Browser cameras are selected locally by the administrator, so there is
    // no ESP32 heartbeat table to query.
    $camera = 0;
    $authorizedMobileScanners = count(array_filter(
        authorizedMobileScanners($conn),
        fn($scanner) => (int) ($scanner['IS_ENABLED'] ?? 1) === 1
    ));

    // The dashboard polls this value to notify an administrator about a fresh scan.
    $latestScan = null;
    $latestScanStatement = $conn->prepare("SELECT a.ID, a.TIME_IN, a.LUNCH_OUT, a.AFTERNOON_TIME_IN, a.TIME_OUT, a.ATTENDANCE_UPDATED_AT, s.STUDENT_NAME, s.LAST_NAME, s.FIRST_NAME, s.MIDDLE_NAME, s.IMAGE_PATH FROM attendance a INNER JOIN students s ON TRIM(s.STUDENT_ID)=TRIM(a.STUDENT_ID) WHERE DATE(a.TIME_IN)=? AND COALESCE(a.ATTENDANCE_REMARK,'')<>'Absent' ORDER BY a.ATTENDANCE_UPDATED_AT DESC, a.ID DESC LIMIT 1");
    $latestScanStatement->bind_param('s', $today);
    $latestScanStatement->execute();
    $latestScanRow = $latestScanStatement->get_result()->fetch_assoc();
    if ($latestScanRow) {
        $latestScanName = trim(
            ($latestScanRow['LAST_NAME'] ?? '') . ', ' .
            ($latestScanRow['FIRST_NAME'] ?? '') .
            (!empty($latestScanRow['MIDDLE_NAME']) ? ' ' . $latestScanRow['MIDDLE_NAME'] : ''),
            ', '
        ) ?: ($latestScanRow['STUDENT_NAME'] ?? 'Student');
        $latestScanAction = 'attendance_update';
        $latestScanActionLabel = 'Attendance update';
        if (($latestScanRow['TIME_OUT'] ?? null) === $latestScanRow['ATTENDANCE_UPDATED_AT']) {
            $latestScanAction = 'time_out';
            $latestScanActionLabel = 'Final Time Out';
        } elseif (($latestScanRow['AFTERNOON_TIME_IN'] ?? null) === $latestScanRow['ATTENDANCE_UPDATED_AT']) {
            $latestScanAction = 'afternoon_time_in';
            $latestScanActionLabel = 'Afternoon Time In';
        } elseif (($latestScanRow['LUNCH_OUT'] ?? null) === $latestScanRow['ATTENDANCE_UPDATED_AT']) {
            $latestScanAction = 'lunch_out';
            $latestScanActionLabel = 'Lunch Out';
        } elseif (($latestScanRow['TIME_IN'] ?? null) === $latestScanRow['ATTENDANCE_UPDATED_AT']) {
            $latestScanAction = 'time_in';
            $latestScanActionLabel = 'Time In';
        }
        $latestScan = [
            'id' => (int) $latestScanRow['ID'],
            'updatedAt' => (string) $latestScanRow['ATTENDANCE_UPDATED_AT'],
            'studentName' => $latestScanName,
            'imagePath' => (string) ($latestScanRow['IMAGE_PATH'] ?? ''),
            'action' => $latestScanAction,
            'actionLabel' => $latestScanActionLabel,
        ];
    }

    // Send the data back to your JavaScript as a JSON object.
    echo json_encode([
        "totalStudents" => $total,
        "attendanceToday" => $today_count,
        "timeOutToday" => $time_out_count,
        "activeCameras" => $camera,
        "statusText" => "BROWSER CAMERA",
        "statusColor" => "#64748b",
        "authorizedMobileScanners" => $authorizedMobileScanners,
        "latestScan" => $latestScan
    ]);


    
?>

