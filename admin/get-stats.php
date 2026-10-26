<?php
    require 'page-guard.php';
    // Connect to your existing database config
    require "../config/db_conn.php";
    require '../includes/attendance-schema.php';
    require '../includes/mobile-scanner-settings.php';
    ensureAttendanceTimeOutColumn($conn);
    header('Content-Type: application/json');

    // 1. Get active student count (archived profiles are excluded)
    $total = $conn->query("SELECT COUNT(*) AS total FROM students WHERE COALESCE(NULLIF(ENROLLMENT_STATUS,''),'Enrolled')<>'Archived'")->fetch_assoc()["total"];

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

    // Send the data back to your JavaScript as a JSON object.
    echo json_encode([
        "totalStudents" => $total,
        "attendanceToday" => $today_count,
        "timeOutToday" => $time_out_count,
        "activeCameras" => $camera,
        "statusText" => "BROWSER CAMERA",
        "statusColor" => "#64748b",
        "authorizedMobileScanners" => $authorizedMobileScanners
    ]);


    
?>

