<?php
require 'page-guard.php';
require_once '../config/db_conn.php';
require '../includes/staff-biometric.php';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { http_response_code(400); exit('Invalid request.'); }

$teacherId = (int) ($_SESSION['teacher-id'] ?? 0);
$currentPassword = $_POST['current_password'] ?? '';
$stmt = $conn->prepare('SELECT TEACHER_PASS FROM teachers WHERE TEACHER_ID=? LIMIT 1');
$stmt->bind_param('i', $teacherId);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$action = $_POST['action'] ?? '';
if (!$teacher || !password_verify($currentPassword, $teacher['TEACHER_PASS'])) {
    $_SESSION['toast'] = ['message' => 'Enter your current teacher password to continue.', 'type' => 'error'];
} elseif ($action === 'password') {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    if (strlen($newPassword) < 12 || $newPassword !== $confirmPassword || password_verify($newPassword, $teacher['TEACHER_PASS'])) {
        $_SESSION['toast'] = ['message' => 'Use matching new passwords with at least 12 characters, different from your current password.', 'type' => 'error'];
    } else {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $update = $conn->prepare('UPDATE teachers SET TEACHER_PASS=?, MUST_CHANGE_PASSWORD=0 WHERE TEACHER_ID=?');
        $update->bind_param('si', $hash, $teacherId);
        $update->execute();
        $_SESSION['toast'] = ['message' => 'Your teacher password was updated successfully.', 'type' => 'success'];
    }
} elseif ($action === 'biometric' && enrollStaffBiometric($conn, 'teacher', $teacherId, $biometricError)) {
    $_SESSION['toast'] = ['message' => 'Biometric sign-in saved. Your password and camera verification are now required.', 'type' => 'success'];
} elseif ($action === 'biometric') {
    $_SESSION['toast'] = ['message' => $biometricError, 'type' => 'error'];
} else {
    $_SESSION['toast'] = ['message' => 'Invalid settings request.', 'type' => 'error'];
}
header('Location: settings.php');
exit;
