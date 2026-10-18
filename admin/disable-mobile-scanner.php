<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/csrf-token.php';
require '../includes/mobile-scanner-settings.php';
ensureMobileScannerSettingsSchema($conn);

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $_SESSION['toast'] = ['message' => 'The disable request expired. Please try again.', 'type' => 'error'];
    header('Location: home.php');
    exit;
}

$scannerId = trim((string) ($_POST['scanner_id'] ?? ''));
$disable = $conn->prepare('UPDATE authorized_mobile_scanners SET IS_ENABLED=0, DISABLED_AT=NOW() WHERE SCANNER_ID=? AND IS_ENABLED=1');
$disable->bind_param('s', $scannerId);
$disable->execute();
$_SESSION['toast'] = ['message' => $disable->affected_rows ? 'Mobile scanner disabled.' : 'Mobile scanner was already disabled or was not found.', 'type' => $disable->affected_rows ? 'success' : 'error'];
header('Location: home.php');
exit;
