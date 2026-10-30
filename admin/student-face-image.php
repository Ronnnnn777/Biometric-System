<?php
session_start();
if (empty($_SESSION['is-authenticated']) || !in_array($_SESSION['user-role'] ?? '', ['administrator', 'teacher', 'mobile_scanner'], true)) {
    http_response_code(403); exit;
}
require __DIR__ . '/../config/db_conn.php';
require_once __DIR__ . '/../includes/student-face-references.php';
$filename = $_GET['file'] ?? '';
if (isset($_GET['legacy'])) {
    $filename = $_GET['legacy'];
    if (!is_string($filename) || !preg_match('#^[^/\\\\]+\.(?:jpe?g|png)$#iD', $filename) || str_contains($filename, '..')) { http_response_code(404); exit; }
    $imagePath = 'uploads/students/' . $filename;
} else {
    if (!is_string($filename) || !preg_match('/^[a-f0-9]{32}\.(jpg|png)$/D', $filename)) { http_response_code(404); exit; }
    $imagePath = 'admin/student-face-image.php?file=' . $filename;
}
$stmt = $conn->prepare('SELECT s.ID, s.GRADE, s.STRAND, s.SECTION, s.ENROLLMENT_STATUS, r.POSE FROM student_face_references r JOIN students s ON s.ID=r.STUDENT_ID WHERE r.IMAGE_PATH=? LIMIT 1');
$stmt->bind_param('s', $imagePath);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
if (!$student) { http_response_code(404); exit; }
if (($_SESSION['user-role'] ?? '') === 'teacher') {
    require_once __DIR__ . '/../includes/teacher-access-schema.php';
    // Teacher access to photos follows the portal's existing roster authorization.
    require_once __DIR__ . '/../includes/teacher-assignment-schema.php';
    $allowed = false;
    foreach (teacherAdvisoryAssignments($conn, (int)($_SESSION['teacher-id'] ?? 0)) as $assignment) {
        if ((int)$assignment['GRADE'] === (int)$student['GRADE'] && $assignment['STRAND'] === $student['STRAND'] && $assignment['SECTION'] === $student['SECTION']) $allowed = true;
    }
    if (!$allowed || $student['ENROLLMENT_STATUS'] !== 'Enrolled' || $student['POSE'] !== 'front') { http_response_code(403); exit; }
}
if (($_SESSION['user-role'] ?? '') === 'mobile_scanner' && ($student['ENROLLMENT_STATUS'] !== 'Enrolled' || $student['POSE'] !== 'front')) { http_response_code(403); exit; }
$path = recognitionReferencePath($imagePath);
if (!$path) { http_response_code(404); exit; }
header('Content-Type: ' . (str_ends_with(strtolower($filename), '.png') ? 'image/png' : 'image/jpeg'));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
