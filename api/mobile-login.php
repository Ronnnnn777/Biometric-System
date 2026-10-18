<?php
require __DIR__.'/mobile-session-config.php';
startMobileApiSession();
require '../config/db_conn.php';
require '../includes/mobile-scanner-settings.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'message'=>'POST is required.']); exit; }
$scannerName=trim((string)($_POST['scanner_name']??''));
$scannerId=trim((string)($_POST['scanner_id']??''));
if($scannerName===''||$scannerId===''||mb_strlen($scannerName)>80||mb_strlen($scannerId)>60){http_response_code(422);echo json_encode(['ok'=>false,'message'=>'This mobile scanner could not provide a valid device identity.']);exit;}

// The Android app supplies its model name and a stable Android device ID. A
// first-time scanner is registered automatically so an administrator no longer
// has to pre-create a name and ID in Settings. Disabled or removed devices
// stay blocked.
ensureMobileScannerSettingsSchema($conn);
$removed=$conn->prepare('SELECT 1 FROM removed_mobile_scanners WHERE SCANNER_ID=? LIMIT 1');
$removed->bind_param('s',$scannerId);
$removed->execute();
if($removed->get_result()->num_rows===1){http_response_code(403);echo json_encode(['ok'=>false,'message'=>'This mobile scanner was removed by an administrator.']);exit;}

$existing=$conn->prepare('SELECT IS_ENABLED FROM authorized_mobile_scanners WHERE SCANNER_ID=? LIMIT 1');
$existing->bind_param('s',$scannerId);
$existing->execute();
$scanner=$existing->get_result()->fetch_assoc();
if($scanner&&!(int)$scanner['IS_ENABLED']){http_response_code(403);echo json_encode(['ok'=>false,'message'=>'This mobile scanner has been disabled by an administrator.']);exit;}
if(!$scanner){
    $register=$conn->prepare('INSERT INTO authorized_mobile_scanners (SCANNER_NAME,SCANNER_ID,IS_ENABLED) VALUES (?,?,1)');
    $register->bind_param('ss',$scannerName,$scannerId);
    $register->execute();
}else{
    $refreshName=$conn->prepare('UPDATE authorized_mobile_scanners SET SCANNER_NAME=? WHERE SCANNER_ID=?');
    $refreshName->bind_param('ss',$scannerName,$scannerId);
    $refreshName->execute();
}
// A removal can occur while a reconnect request is already in progress. Check
// once more after the registration write so that such a request cannot leave a
// removed scanner visible on the dashboard.
$removedAfterRegistration=$conn->prepare('SELECT 1 FROM removed_mobile_scanners WHERE SCANNER_ID=? LIMIT 1');
$removedAfterRegistration->bind_param('s',$scannerId);
$removedAfterRegistration->execute();
if($removedAfterRegistration->get_result()->num_rows===1){
    $cleanup=$conn->prepare('DELETE FROM authorized_mobile_scanners WHERE SCANNER_ID=?');
    $cleanup->bind_param('s',$scannerId);
    $cleanup->execute();
    http_response_code(403);
    echo json_encode(['ok'=>false,'message'=>'This mobile scanner was removed by an administrator.']);
    exit;
}
session_regenerate_id(true);$_SESSION['is-authenticated']=true;$_SESSION['user-role']='mobile_scanner';$_SESSION['csrf_token']=bin2hex(random_bytes(32));
echo json_encode(['ok'=>true,'message'=>'Mobile scanner connected.','scanner_name'=>$scannerName,'scanner_id'=>$scannerId,'csrf_token'=>$_SESSION['csrf_token']]);

