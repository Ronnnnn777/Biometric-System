<?php
require __DIR__.'/mobile-session-config.php';
startMobileApiSession();
header('Content-Type: application/json');
if (empty($_SESSION['is-authenticated'])||($_SESSION['user-role']??'')!=='mobile_scanner') { http_response_code(401); echo json_encode(['ok'=>false,'message'=>'Sign in to the mobile scanner.']); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
echo json_encode(['ok'=>true, 'csrf_token'=>$_SESSION['csrf_token']]);

