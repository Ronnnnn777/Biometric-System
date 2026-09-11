<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/attendance-schema.php';
ensureAttendanceTimeOutColumn($conn);
header('Content-Type: application/json');
$frame = $_POST['frame'] ?? '';
if (!preg_match('#^data:image/(?:jpeg|png);base64,(.+)$#', $frame, $matches)) { http_response_code(400); echo json_encode(['match'=>false,'faces'=>[],'matches'=>[],'message'=>'Invalid camera frame.']); exit; }
$imagePath = tempnam(sys_get_temp_dir(), 'biometric-scan-') . '.jpg';
file_put_contents($imagePath, base64_decode($matches[1], true));
function recognizeWithService(string $imagePath): ?array { $payload=json_encode(['image_path'=>$imagePath]); $context=stream_context_create(['http'=>['method'=>'POST','header'=>'Content-Type: application/json\r\n','content'=>$payload,'timeout'=>3,'ignore_errors'=>true]]); $raw=@file_get_contents('http://127.0.0.1:8766/recognize',false,$context); $result=json_decode($raw ?: '',true); return is_array($result)?$result:null; }
$pythonPath='C:\\Users\\jaxim\\AppData\\Local\\Python\\pythoncore-3.14-64\\python.exe';
$pythonCommand=is_file($pythonPath) ? escapeshellarg($pythonPath) : 'py -3';
$result=recognizeWithService($imagePath);
// Keep recognition in one long-running Python process.  Re-loading every
// enrolled face for every camera frame is the main source of scan latency.
if ($result===null && (microtime(true)-(float)($_SESSION['face_service_attempted_at'] ?? 0)) > 5) {
    $_SESSION['face_service_attempted_at']=microtime(true);
    $service=escapeshellarg(__DIR__.DIRECTORY_SEPARATOR.'recognize_face_service.py');
    if (PHP_OS_FAMILY==='Windows') @pclose(@popen('start "" /B '.$pythonCommand.' '.$service.' >NUL 2>&1','r'));
    usleep(400000);
    $result=recognizeWithService($imagePath);
}
if ($result===null) { $script=escapeshellarg(__DIR__.DIRECTORY_SEPARATOR.'recognize_face.py'); $raw=shell_exec($pythonCommand.' '.$script.' '.escapeshellarg($imagePath).' 2>NUL'); $result=json_decode($raw ?? '',true); }
@unlink($imagePath);
$faces=$result['faces'] ?? [];
$recognized=$result['matches'] ?? [];
// Raw face-detector boxes can occasionally be triggered by a bright window,
// poster, or background texture.  They are kept for unregistered-face review
// but are not sent to the visible corner overlay until identity is confirmed.
if (!$recognized) { echo json_encode(['match'=>false,'faces'=>[],'matches'=>[],'unregistered'=>$faces,'message'=>$result['message'] ?? 'No recognized face.']); exit; }
$scanType=$_POST['scan_type']??'time_in';if(!in_array($scanType,['time_in','time_out'],true)){$scanType='time_in';}
$studentQuery=$conn->prepare('SELECT STUDENT_ID, STUDENT_NAME, IMAGE_PATH FROM students WHERE STUDENT_ID = ? LIMIT 1');$check=$conn->prepare('SELECT ID, TIME_OUT FROM attendance WHERE STUDENT_ID = ? AND DATE(TIME_IN) = ? ORDER BY TIME_IN DESC LIMIT 1');$log=$conn->prepare('INSERT INTO attendance (STUDENT_ID, STUDENT_NAME, TIME_IN) VALUES (?, ?, NOW())');$timeOut=$conn->prepare('UPDATE attendance SET TIME_OUT=NOW() WHERE ID=? AND TIME_OUT IS NULL');$today=date('Y-m-d');$seen=[];$found=[];
foreach ($recognized as $recognizedFace) { $id=(string)($recognizedFace['student_id'] ?? ''); if (!$id || isset($seen[$id])) continue; $seen[$id]=true;$studentQuery->bind_param('s',$id);$studentQuery->execute();$student=$studentQuery->get_result()->fetch_assoc();if(!$student)continue;$check->bind_param('ss',$student['STUDENT_ID'],$today);$check->execute();$record=$check->get_result()->fetch_assoc();$logged=false;$message='';if($scanType==='time_in'){if(!$record){$log->bind_param('ss',$student['STUDENT_ID'],$student['STUDENT_NAME']);$log->execute();$logged=true;$message='Time in recorded';}else{$message='Time in already recorded';}}elseif(!$record){$message='No time-in record today';}elseif($record['TIME_OUT']!==null){$message='Time out already recorded';}else{$timeOut->bind_param('i',$record['ID']);$timeOut->execute();$logged=$timeOut->affected_rows>0;$message=$logged?'Time out recorded':'Time out already recorded';}$found[]=['student'=>$student,'logged'=>$logged,'action'=>$scanType,'message'=>$message,'confidence'=>$recognizedFace['confidence'] ?? null,'box'=>$recognizedFace['box'] ?? null]; }
$recognizedBoxes=[];foreach($found as $matchedFace){if(isset($matchedFace['box']))$recognizedBoxes[json_encode($matchedFace['box'])]=true;}
$unregistered=[];foreach($faces as $face){if(!isset($recognizedBoxes[json_encode($face['box']??[])]))$unregistered[]=$face;}
if (!$found) { echo json_encode(['match'=>false,'faces'=>[],'matches'=>[],'unregistered'=>$unregistered,'message'=>'Matched profiles are no longer registered.']); exit; }
$displayFaces=[];foreach($found as $matchedFace){if(isset($matchedFace['box']))$displayFaces[]=['box'=>$matchedFace['box']];}
echo json_encode(['match'=>true,'faces'=>$displayFaces,'matches'=>$found,'unregistered'=>$unregistered]);
