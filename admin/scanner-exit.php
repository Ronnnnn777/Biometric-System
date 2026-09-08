<?php
session_start();
require 'page-guard.php';
require '../config/db_conn.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    header('Location: scan-attendance.php?exit=invalid-request', true, 303);
    exit;
}

$user=$_SESSION['admin-user'] ?? 'admin@gmail.com';
$stmt=$conn->prepare('SELECT ADMIN_PASS FROM admins WHERE ADMIN_USER=?');
$stmt->bind_param('s',$user);
$stmt->execute();
$row=$stmt->get_result()->fetch_assoc();

if (!$row || !password_verify($_POST['password'] ?? '',$row['ADMIN_PASS'])) {
    // The full-screen scanner has its own error banner.  Do not leave a
    // dashboard toast behind for a later successful exit.
    unset($_SESSION['toast']);
    header('Location: scan-attendance.php?exit=invalid-password', true, 303);
    exit;
}

header('Location: home.php', true, 303);
exit;
