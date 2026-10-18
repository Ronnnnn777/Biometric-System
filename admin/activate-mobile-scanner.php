<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/csrf-token.php';
require '../includes/mobile-scanner-settings.php';

ensureMobileScannerSettingsSchema($conn);

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $_SESSION['toast'] = ['message' => 'The activation request expired. Please try again.', 'type' => 'error'];
    header('Location: home.php');
    exit;
}

$scannerId = trim((string) ($_POST['scanner_id'] ?? ''));
$activate = $conn->prepare('UPDATE authorized_mobile_scanners SET IS_ENABLED=1, DISABLED_AT=NULL WHERE SCANNER_ID=? AND IS_ENABLED=0');
$activate->bind_param('s', $scannerId);
$activate->execute();

$_SESSION['toast'] = [
    'message' => $activate->affected_rows ? 'Mobile scanner activated.' : 'Mobile scanner was already active or was not found.',
    'type' => $activate->affected_rows ? 'success' : 'error'
];
header('Location: home.php');
exit;
