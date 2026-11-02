<?php
require __DIR__.'/mobile-session-config.php';
startMobileApiSession();
header('Content-Type: application/json');
if (empty($_SESSION['is-authenticated'])||($_SESSION['user-role']??'')!=='mobile_scanner') { http_response_code(401); echo json_encode(['match'=>false,'message'=>'Mobile scanner session expired. Sign in again.']); exit; }

// Scanner identity is bound to the authenticated session at login. Re-verify it
// on every scan so a scanner that was disabled, removed, or whose session no
// longer carries a valid identity cannot keep submitting requests.
require __DIR__.'/../config/db_conn.php';
require __DIR__.'/../includes/mobile-scanner-settings.php';
$scannerId = (string) ($_SESSION['scanner_id'] ?? '');
if ($scannerId === '' || !mobileScannerIsAuthorized($conn, (string) ($_SESSION['scanner_name'] ?? ''), $scannerId)) {
    http_response_code(401);
    echo json_encode(['match'=>false,'message'=>'This scanner is no longer authorized. Sign in again.']);
    exit;
}

define('MOBILE_API_AUTHENTICATED', true);
require __DIR__.'/../admin/scan-match.php';
