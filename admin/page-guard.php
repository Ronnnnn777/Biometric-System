<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent browser Back from restoring protected pages after logout.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');

if (empty($_SESSION['is-authenticated']) || ($_SESSION['user-role'] ?? '') !== 'administrator') {
    // Clear the session through the shared logout controller instead of sending
    // a cross-portal request to the admin login page.
    header('Location: ../common/logout.php', true, 302);
    exit;
}

// Revalidate the administrator account on every protected request, mirroring
// teacher/page-guard.php. A removed or otherwise invalid account must not keep
// an authenticated session alive.
require_once __DIR__ . '/../config/db_conn.php';
$adminUserId = (int) ($_SESSION['user-id'] ?? 0);
$adminUser = (string) ($_SESSION['admin-user'] ?? '');
if ($adminUserId > 0) {
    $adminCheck = $conn->prepare('SELECT ADMIN_ID FROM admins WHERE ADMIN_ID=? LIMIT 1');
    $adminCheck->bind_param('i', $adminUserId);
} else {
    $adminCheck = $conn->prepare('SELECT ADMIN_ID FROM admins WHERE ADMIN_USER=? LIMIT 1');
    $adminCheck->bind_param('s', $adminUser);
}
$adminCheck->execute();
$adminAccount = $adminCheck->get_result()->fetch_assoc();
$adminCheck->close();
if (!$adminAccount) {
    $_SESSION = [];
    session_destroy();
    header('Location: ../common/logout.php', true, 302);
    exit;
}
