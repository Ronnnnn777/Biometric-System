<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (empty($_SESSION['is-authenticated']) || ($_SESSION['user-role'] ?? '') !== 'administrator') {
    header('Location: ../', true, 302);
    exit;
}


