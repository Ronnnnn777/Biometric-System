<?php
require 'page-guard.php';
require '../includes/csrf-token.php';

$grade = (int) ($_POST['grade'] ?? 0);
$section = trim($_POST['section'] ?? '');
$studentId = (int) ($_POST['student_id'] ?? 0);
$redirect = 'students.php?grade=' . $grade . '&section=' . urlencode($section);

$_SESSION['toast'] = [
    'message' => 'Present attendance is recorded only through the scanner.',
    'type' => 'error',
];
header('Location: ' . $redirect);
exit;
