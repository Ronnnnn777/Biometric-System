<?php
require "../config/db_conn.php";
require "../includes/attendance-schema.php";
ensureAttendanceSnapshotColumns($conn);
date_default_timezone_set('Asia/Manila');

// Use trim() to remove any accidental spaces from Python/Filenames
$student_id = isset($_POST['student_id']) ? trim($_POST['student_id']) : null;
$student_name = isset($_POST['student_name']) ? trim($_POST['student_name']) : null;

if ($student_id && $student_name) {
    $today = date('Y-m-d');

    // Clean check for existing attendance
    $check = $conn->prepare("SELECT ID FROM attendance WHERE STUDENT_ID = ? AND DATE(TIME_IN) = ?");
    $check->bind_param("ss", $student_id, $today);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows == 0) {
        // Log the attendance with the student's current section snapshot.
        $snapshot = attendanceSnapshotForStudent($conn, $student_id);
        $stmt = $conn->prepare("INSERT INTO attendance (STUDENT_ID, STUDENT_NAME, TIME_IN, GRADE_SNAPSHOT, STRAND_SNAPSHOT, SECTION_SNAPSHOT) VALUES (?, ?, NOW(), ?, ?, ?)");
        $stmt->bind_param("ssiss", $student_id, $student_name, $snapshot["GRADE"], $snapshot["STRAND"], $snapshot["SECTION"]);
        
        if ($stmt->execute()) {
            echo "Success: Attendance logged for $student_name";
        } else {
            echo "Database Error: " . $stmt->error;
        }
        $stmt->close();
    } else {
        echo "Already logged today: $student_name";
    }
    $check->close();
} else {
    echo "Error: Missing student_id or student_name.";
}
$conn->close();
?>