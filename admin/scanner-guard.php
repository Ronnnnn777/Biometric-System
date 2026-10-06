<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (empty($_SESSION['is-authenticated']) || !in_array($_SESSION['user-role'] ?? '', ['administrator', 'camera'], true)) {
    header('Location: ../', true, 302);
    exit;
}

$isCameraPortal = ($_SESSION['user-role'] ?? '') === 'camera';
