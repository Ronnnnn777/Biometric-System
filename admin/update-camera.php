<?php
require "../config/db_conn.php";

// Set timezone to match your local time
date_default_timezone_set('Asia/Manila');

$camera_id = $_POST['camera_id'] ?? 1;
$status = $_POST['status'] ?? 'Active';

// INSERT if it doesn't exist, UPDATE if it does
$query = "INSERT INTO camera_status (id, status, last_seen) 
          VALUES (?, ?, NOW()) 
          ON DUPLICATE KEY UPDATE status = VALUES(status), last_seen = NOW()";

$stmt = $conn->prepare($query);
$stmt->bind_param("is", $camera_id, $status);
$stmt->execute();

echo "Heartbeat Recorded";
?>