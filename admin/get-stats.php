<?php
    require 'page-guard.php';
    // Connect to your existing database config
    require "../config/db_conn.php";
    header('Content-Type: application/json');

    // 1. Get Total Students count
    $total = $conn->query("SELECT COUNT(*) AS total FROM students")->fetch_assoc()["total"];

    // 2. Get Today's Attendance count (Unique students only)
    $today = date('Y-m-d');
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT STUDENT_ID) AS total_today FROM attendance WHERE DATE(TIME_IN) = ?");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $today_count = $stmt->get_result()->fetch_assoc()["total_today"];

    // Browser cameras are selected locally by the administrator, so there is
    // no ESP32 heartbeat table to query.
    $camera = 0;

    // Send the data back to your JavaScript as a JSON object.
    echo json_encode([
        "totalStudents" => $total,
        "attendanceToday" => $today_count,
        "activeCameras" => $camera,
        "statusText" => "BROWSER CAMERA",
        "statusColor" => "#64748b"
    ]);


    
?>

