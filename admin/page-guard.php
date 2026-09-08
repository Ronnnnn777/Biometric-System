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
    header('Location: login.php', true, 302);
    exit;
}
