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

    // 3. Hardware Heartbeat (Checks if camera sent a signal in the last 2 minutes)
    // This matches your Capstone logic for monitoring the ESP32-CAM node
    $camera = $conn->query("SELECT COUNT(*) AS active_count FROM camera_status WHERE status = 'Active' AND last_seen > NOW() - INTERVAL 2 MINUTE")->fetch_assoc()["active_count"];

    // 4. Send the data back to your JavaScript as a JSON object
    echo json_encode([
        "totalStudents" => $total,
        "attendanceToday" => $today_count,
        "activeCameras" => $camera,
        "statusText" => ($camera > 0 ? "CONNECTED" : "DISCONNECTED"),
        "statusColor" => ($camera > 0 ? "#10b981" : "#ef4444")
    ]);


    
?>

