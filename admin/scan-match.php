<?php
require 'page-guard.php';
require '../config/db_conn.php';
header('Content-Type: application/json');
$frame = $_POST['frame'] ?? '';
if (!preg_match('#^data:image/(?:jpeg|png);base64,(.+)$#', $frame, $matches)) { http_response_code(400); echo json_encode(['match'=>false,'faces'=>[],'matches'=>[],'message'=>'Invalid camera frame.']); exit; }
$imagePath = tempnam(sys_get_temp_dir(), 'biometric-scan-') . '.jpg';
file_put_contents($imagePath, base64_decode($matches[1], true));
function recognizeWithService(string $imagePath): ?array { $payload=json_encode(['image_path'=>$imagePath]); $context=stream_context_create(['http'=>['method'=>'POST','header'=>'Content-Type: application/json\r\n','content'=>$payload,'timeout'=>3,'ignore_errors'=>true]]); $raw=@file_get_contents('http://127.0.0.1:8765/recognize',false,$context); $result=json_decode($raw ?: '',true); return is_array($result)?$result:null; }
$result=recognizeWithService($imagePath);
if ($result===null && empty($_SESSION['face_service_started'])) { $_SESSION['face_service_started']=true; $service=escapeshellarg(__DIR__.DIRECTORY_SEPARATOR.'recognize_face_service.py'); if (PHP_OS_FAMILY==='Windows') { @pclose(@popen('start "" /B python '.$service.' >NUL 2>&1','r')); } $result=recognizeWithService($imagePath); }
if ($result===null) { $script=escapeshellarg(__DIR__.DIRECTORY_SEPARATOR.'recognize_face.py'); $raw=shell_exec('python '.$script.' '.escapeshellarg($imagePath).' 2>NUL'); $result=json_decode($raw ?? '',true); }
@unlink($imagePath);
$faces=$result['faces'] ?? [];
$recognized=$result['matches'] ?? [];
if (!$recognized) { echo json_encode(['match'=>false,'faces'=>$faces,'matches'=>[],'message'=>$result['message'] ?? 'No recognized face.']); exit; }
$studentQuery=$conn->prepare('SELECT STUDENT_ID, STUDENT_NAME, IMAGE_PATH FROM students WHERE STUDENT_ID = ? LIMIT 1');$check=$conn->prepare('SELECT ID FROM attendance WHERE STUDENT_ID = ? AND DATE(TIME_IN) = ? LIMIT 1');$log=$conn->prepare('INSERT INTO attendance (STUDENT_ID, STUDENT_NAME, TIME_IN) VALUES (?, ?, NOW())');$today=date('Y-m-d');$seen=[];$found=[];
foreach ($recognized as $recognizedFace) { $id=(string)($recognizedFace['student_id'] ?? ''); if (!$id || isset($seen[$id])) continue; $seen[$id]=true; $studentQuery->bind_param('s',$id);$studentQuery->execute();$student=$studentQuery->get_result()->fetch_assoc();if(!$student)continue;$check->bind_param('ss',$student['STUDENT_ID'],$today);$check->execute();$already=$check->get_result()->num_rows>0;if(!$already){$log->bind_param('ss',$student['STUDENT_ID'],$student['STUDENT_NAME']);$log->execute();}$found[]=['student'=>$student,'logged'=>!$already,'confidence'=>$recognizedFace['confidence'] ?? null]; }
if (!$found) { echo json_encode(['match'=>false,'faces'=>$faces,'matches'=>[],'message'=>'Matched profiles are no longer registered.']); exit; }
echo json_encode(['match'=>true,'faces'=>$faces,'matches'=>$found]);