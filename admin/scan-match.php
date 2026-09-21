<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/student-profile-schema.php';
require '../includes/attendance-schema.php';
ensureStudentProfileColumns($conn);
ensureAttendanceTimeOutColumn($conn);
header('Content-Type: application/json');
$imagePath='';$removeImage=false;$upload=$_FILES['frame']??null;
// A JPEG upload avoids base64 expansion and a decode/write cycle on every
// frame, which is especially noticeable with higher-resolution USB cameras.
if(is_array($upload)&&($upload['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK&&is_uploaded_file($upload['tmp_name']??'')&&($upload['size']??0)>0&&($upload['size']??0)<=2*1024*1024){$imageInfo=@getimagesize($upload['tmp_name']);if($imageInfo&&in_array($imageInfo[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG],true))$imagePath=$upload['tmp_name'];}
if($imagePath===''){$frame=$_POST['frame']??'';if(!preg_match('#^data:image/(?:jpeg|png);base64,(.+)$#',$frame,$matches)){http_response_code(400);echo json_encode(['match'=>false,'faces'=>[],'matches'=>[],'message'=>'Invalid camera frame.']);exit;}$temporaryFile=tempnam(sys_get_temp_dir(),'biometric-scan-');if($temporaryFile===false){http_response_code(500);echo json_encode(['match'=>false,'faces'=>[],'matches'=>[],'message'=>'Could not create a temporary camera frame.']);exit;}$imagePath=$temporaryFile.'.jpg';@rename($temporaryFile,$imagePath);$imageData=base64_decode($matches[1],true);if($imageData===false||file_put_contents($imagePath,$imageData,LOCK_EX)===false){@unlink($imagePath);http_response_code(500);echo json_encode(['match'=>false,'faces'=>[],'matches'=>[],'message'=>'Could not save the camera frame for recognition.']);exit;}$removeImage=true;}
function recognizeWithService(string $imagePath): ?array { $payload=json_encode(['image_path'=>$imagePath]); $context=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\n",'content'=>$payload,'timeout'=>3,'ignore_errors'=>true]]); $raw=@file_get_contents('http://127.0.0.1:8772/recognize',false,$context); $result=json_decode($raw ?: '',true); return is_array($result)?$result:null; }
$pythonPath='C:\\Users\\jaxim\\AppData\\Local\\Python\\pythoncore-3.14-64\\python.exe';
$pythonCommand=is_file($pythonPath) ? escapeshellarg($pythonPath) : 'py -3';
$result=recognizeWithService($imagePath);
// Keep recognition in one long-running Python process.  Re-loading every
// enrolled face for every camera frame is the main source of scan latency.
if ($result===null && (microtime(true)-(float)($_SESSION['face_service_attempted_at'] ?? 0)) > 5) {
    $_SESSION['face_service_attempted_at']=microtime(true);
    $service=escapeshellarg(__DIR__.DIRECTORY_SEPARATOR.'recognize_face_service.py');
    if (PHP_OS_FAMILY==='Windows') @pclose(@popen('start "" /B '.$pythonCommand.' '.$service.' >NUL 2>&1','r'));
    // A cold Python service takes longer than a fraction of a second to load
    // its face-recognition libraries and bind to the local port.
    usleep(1500000);
    $result=recognizeWithService($imagePath);
}
if ($result===null) { $script=escapeshellarg(__DIR__.DIRECTORY_SEPARATOR.'recognize_face.py'); $raw=shell_exec($pythonCommand.' '.$script.' '.escapeshellarg($imagePath).' 2>NUL'); $result=json_decode($raw ?? '',true); }
if($removeImage)@unlink($imagePath);
$result=is_array($result)?$result:['match'=>false,'faces'=>[],'matches'=>[],'message'=>'Recognition service is unavailable. Check the local Python recognition service, then restart the scanner.'];
$faces=$result['faces'] ?? [];$detectedFaces=$result['detected_faces'] ?? $faces;$unregisteredFaces=$result['unregistered_faces'] ?? $faces;
$recognized=$result['matches'] ?? [];
// Raw face-detector boxes can occasionally be triggered by a bright window,
// poster, or background texture.  They are kept for unregistered-face review
// but are not sent to the visible corner overlay until identity is confirmed.
if (!$recognized) { unset($_SESSION['auto_scan_departures'],$_SESSION['face_match_confirmations']); echo json_encode(['match'=>false,'faces'=>[],'preview_faces'=>$faces,'detected_faces'=>$detectedFaces,'matches'=>[],'unregistered'=>$unregisteredFaces,'message'=>$result['message'] ?? 'No recognized face.']); exit; }
$scanType=$_POST['scan_type']??'auto';if(!in_array($scanType,['auto','time_in','time_out'],true)){$scanType='auto';}
$studentQuery=$conn->prepare('SELECT STUDENT_ID, STUDENT_NAME, IMAGE_PATH, ENROLLMENT_STATUS FROM students WHERE STUDENT_ID = ? LIMIT 1');$check=$conn->prepare('SELECT ID, TIME_OUT FROM attendance WHERE STUDENT_ID = ? AND DATE(TIME_IN) = ? ORDER BY TIME_IN DESC LIMIT 1');$log=$conn->prepare('INSERT INTO attendance (STUDENT_ID, STUDENT_NAME, TIME_IN) VALUES (?, ?, NOW())');$timeOut=$conn->prepare('UPDATE attendance SET TIME_OUT=NOW() WHERE ID=? AND TIME_OUT IS NULL');$today=date('Y-m-d');$seen=[];$found=[];$recognizedBoxes=[];$now=microtime(true);$confirmations=$_SESSION['face_match_confirmations']??[];foreach($confirmations as $key=>$candidate)if($now-($candidate['seen_at']??0)>6)unset($confirmations[$key]);$autoDepartures=$_SESSION['auto_scan_departures']??[];$presentIds=[];foreach($recognized as $recognizedFace){$recognizedId=(string)($recognizedFace['student_id']??'');if($recognizedId!=='')$presentIds[$recognizedId]=true;}$departedIds=[];foreach($autoDepartures as $studentId=>$_)if(!isset($presentIds[$studentId])){$departedIds[]=$studentId;unset($autoDepartures[$studentId]);}foreach($departedIds as $studentId)unset($confirmations['auto|'.$studentId]);
foreach ($recognized as $recognizedFace) { $id=(string)($recognizedFace['student_id'] ?? '');$box=$recognizedFace['box'] ?? null;$distance=isset($recognizedFace['distance'])?(float)$recognizedFace['distance']:1-(float)($recognizedFace['confidence']??0);if($distance>0.45)continue;if($box)$recognizedBoxes[json_encode($box)]=true;if(!$id || isset($seen[$id])) continue;$seen[$id]=true;if($scanType==='auto'&&isset($autoDepartures[$id]))continue;$key=$scanType.'|'.$id;$candidate=$confirmations[$key]??['count'=>0,'seen_at'=>0];$candidate['count']=$now-($candidate['seen_at']??0)<=5?(int)$candidate['count']+1:1;$candidate['seen_at']=$now;$confirmations[$key]=$candidate;/* Require the same strict identity result in two frames before logging attendance. */if($candidate['count']<2)continue;$studentQuery->bind_param('s',$id);$studentQuery->execute();$student=$studentQuery->get_result()->fetch_assoc();if(!$student)continue;$logged=false;$message='';$action=$scanType;if(($student['ENROLLMENT_STATUS'] ?? 'Enrolled')!=='Enrolled'){$message='Student is '.strtolower($student['ENROLLMENT_STATUS']).' and cannot record attendance.';}else{$check->bind_param('ss',$student['STUDENT_ID'],$today);$check->execute();$record=$check->get_result()->fetch_assoc();if($scanType==='auto'){if(!$record){$log->bind_param('ss',$student['STUDENT_ID'],$student['STUDENT_NAME']);$log->execute();$logged=true;$action='time_in';$message='Time in recorded';}elseif($record['TIME_OUT']===null){$timeOut->bind_param('i',$record['ID']);$timeOut->execute();$logged=$timeOut->affected_rows>0;$action='time_out';$message=$logged?'Time out recorded':'Time out already recorded';}else{$action='complete';$message='Attendance already completed today';}}elseif($scanType==='time_in'){if(!$record){$log->bind_param('ss',$student['STUDENT_ID'],$student['STUDENT_NAME']);$log->execute();$logged=true;$message='Time in recorded';}else{$message='Time in already recorded';}}elseif(!$record){$message='No time-in record today';}elseif($record['TIME_OUT']!==null){$message='Time out already recorded';}else{$timeOut->bind_param('i',$record['ID']);$timeOut->execute();$logged=$timeOut->affected_rows>0;$message=$logged?'Time out recorded':'Time out already recorded';}}if($scanType==='auto')$autoDepartures[$id]=$now;$found[]=['student'=>$student,'logged'=>$logged,'action'=>$action,'message'=>$message,'confidence'=>$recognizedFace['confidence'] ?? null,'box'=>$box]; }
$_SESSION['face_match_confirmations']=$confirmations;$_SESSION['auto_scan_departures']=$autoDepartures;
$unregistered=[];foreach($unregisteredFaces as $face){if(!isset($recognizedBoxes[json_encode($face['box']??[])]))$unregistered[]=$face;}
if (!$found) { echo json_encode(['match'=>false,'faces'=>[],'preview_faces'=>$faces,'detected_faces'=>$detectedFaces,'recognized_faces'=>$recognized,'matches'=>[],'unregistered'=>$unregistered,'message'=>'Face detected. Confirming identity…']); exit; }
$displayFaces=[];foreach($found as $matchedFace){if(isset($matchedFace['box']))$displayFaces[]=['box'=>$matchedFace['box']];}
echo json_encode(['match'=>true,'faces'=>$displayFaces,'preview_faces'=>$faces,'detected_faces'=>$detectedFaces,'recognized_faces'=>$recognized,'matches'=>$found,'unregistered'=>$unregistered]);
