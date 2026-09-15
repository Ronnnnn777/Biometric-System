<?php
require 'page-guard.php';
require '../config/db_conn.php';
header('Content-Type: application/json');

if(!hash_equals($_SESSION['csrf_token']??'',$_POST['csrf_token']??'')){http_response_code(403);echo json_encode(['saved'=>false,'message'=>'Invalid request.']);exit;}
$frame=$_POST['frame']??'';$box=json_decode($_POST['box']??'',true);
if(!preg_match('#^data:image/(?:jpeg|png);base64,([A-Za-z0-9+/=]+)$#',$frame,$match)||!is_array($box)){http_response_code(400);echo json_encode(['saved'=>false,'message'=>'Invalid face capture.']);exit;}
$coordinates=['top','right','bottom','left'];foreach($coordinates as $coordinate){if(!isset($box[$coordinate])||!is_numeric($box[$coordinate])){http_response_code(400);echo json_encode(['saved'=>false,'message'=>'Invalid face location.']);exit;}}
$conn->query('CREATE TABLE IF NOT EXISTS unregistered_entries (ENTRY_ID INT AUTO_INCREMENT PRIMARY KEY, DETECTED_AT DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, IMAGE_PATH VARCHAR(255) NOT NULL)');
$imageData=base64_decode($match[1],true);if($imageData===false||strlen($imageData)>2*1024*1024){http_response_code(400);echo json_encode(['saved'=>false,'message'=>'Invalid image data.']);exit;}
$directory='../uploads/unregistered/';if(!is_dir($directory)&&!mkdir($directory,0755,true)){http_response_code(500);echo json_encode(['saved'=>false,'message'=>'Unable to save the capture.']);exit;}
$filename='unknown-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.jpg';$target=$directory.$filename;$saved=false;
if(function_exists('imagecreatefromstring')){$source=@imagecreatefromstring($imageData);if($source){$width=imagesx($source);$height=imagesy($source);$left=max(0,min($width-1,(int)$box['left']));$top=max(0,min($height-1,(int)$box['top']));$right=max($left+1,min($width,(int)$box['right']));$bottom=max($top+1,min($height,(int)$box['bottom']));$crop=imagecrop($source,['x'=>$left,'y'=>$top,'width'=>$right-$left,'height'=>$bottom-$top]);if($crop){$saved=imagejpeg($crop,$target,88);imagedestroy($crop);}imagedestroy($source);}}
if(!$saved)$saved=file_put_contents($target,$imageData)!==false;
if(!$saved){http_response_code(500);echo json_encode(['saved'=>false,'message'=>'Unable to save the capture.']);exit;}
$path='uploads/unregistered/'.$filename;$insert=$conn->prepare('INSERT INTO unregistered_entries (IMAGE_PATH) VALUES (?)');$insert->bind_param('s',$path);$insert->execute();echo json_encode(['saved'=>true,'entry_id'=>$insert->insert_id,'image_path'=>$path,'detected_at_ms'=>time()*1000]);
