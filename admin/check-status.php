<?php
require "../config/db_conn.php";

$studentId = trim($_GET['student_id'] ?? '');

if ($studentId === '') {
    http_response_code(400);
    echo "LRN is required.";
    exit;
}

// Attendance stores the timestamp in TIME_IN; it has no separate `date` column.
$stmt = $conn->prepare("SELECT 1 FROM attendance WHERE STUDENT_ID = ? AND DATE(TIME_IN) = CURDATE() LIMIT 1");
$stmt->bind_param("s", $studentId);
$stmt->execute();

echo $stmt->get_result()->num_rows > 0 ? "exists" : "absent";
