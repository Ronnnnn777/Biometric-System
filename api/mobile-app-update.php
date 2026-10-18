<?php
require __DIR__.'/mobile-session-config.php';
startMobileApiSession();
header('Content-Type: application/json');
if(empty($_SESSION['is-authenticated'])||($_SESSION['user-role']??'')!=='mobile_scanner'){http_response_code(401);echo json_encode(['ok'=>false,'message'=>'Mobile scanner session expired.']);exit;}
$buildDirectory=dirname(__DIR__).'/android-scanner/app/build/outputs/apk/debug';
$metadataPath=$buildDirectory.'/output-metadata.json';$apkPath=$buildDirectory.'/app-debug.apk';
if(!is_file($metadataPath)||!is_file($apkPath)){http_response_code(404);echo json_encode(['ok'=>false,'message'=>'No Android update is built yet.']);exit;}
$metadata=json_decode((string)file_get_contents($metadataPath),true);$element=$metadata['elements'][0]??[];
$versionCode=(int)($element['versionCode']??0);$versionName=(string)($element['versionName']??'');
if($versionCode<1||$versionName===''){http_response_code(500);echo json_encode(['ok'=>false,'message'=>'Android update metadata is invalid.']);exit;}
echo json_encode(['ok'=>true,'version_code'=>$versionCode,'version_name'=>$versionName,'apk_path'=>'android-scanner/app/build/outputs/apk/debug/app-debug.apk']);
