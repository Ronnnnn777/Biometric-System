<?php
require __DIR__.'/mobile-session-config.php';
startMobileApiSession();
if (empty($_SESSION['is-authenticated'])||($_SESSION['user-role']??'')!=='mobile_scanner') { header('Content-Type: application/json'); http_response_code(401); echo json_encode(['match'=>false,'message'=>'Mobile scanner session expired. Sign in again.']); exit; }
define('MOBILE_API_AUTHENTICATED', true);
require __DIR__.'/../admin/scan-match.php';

