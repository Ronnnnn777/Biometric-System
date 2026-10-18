<?php
session_start();
require '../config/db_conn.php';
require '../includes/default-admin.php';
require '../includes/staff-biometric.php';
require '../includes/teacher-profile-schema.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { http_response_code(400); echo json_encode(['ok'=>false,'message'=>'Invalid scanner request.']); exit; }
ensureDefaultAdministrator($conn); ensureStaffBiometricSchema($conn); ensureTeacherProfileColumns($conn);
$frame = staffCameraImage();
if ($frame === null) { http_response_code(400); echo json_encode(['ok'=>false,'message'=>'Invalid camera frame.']); exit; }
$result = recognizeStaffLive($frame); @unlink($frame);
$result = is_array($result) ? $result : ['matches'=>[],'faces'=>[],'message'=>'Starting face recognition service…'];
$faces = $result['faces'] ?? [];
$matches = $result['matches'] ?? [];
foreach ($matches as $match) {
    if (!preg_match('/^(admin|teacher)_(\d+)$/', (string)($match['identity'] ?? ''), $parts)) continue;
    $role = $parts[1] === 'admin' ? 'administrator' : 'teacher'; $id = (int)$parts[2];
    $enrollment = $conn->prepare('SELECT 1 FROM staff_biometrics WHERE ROLE=? AND STAFF_ID=? LIMIT 1');
    $enrollment->bind_param('si',$role,$id); $enrollment->execute();
    if (!$enrollment->get_result()->fetch_row()) continue;
    // Keep the scanner's consecutive-frame guard for borderline matches.
    $key=$role.'|'.$id; $now=microtime(true); $confirmations=$_SESSION['staff_face_login_confirmations']??[];
    foreach($confirmations as $oldKey=>$candidate) if($now-($candidate['seen_at']??0)>6) unset($confirmations[$oldKey]);
    $candidate=$confirmations[$key]??['count'=>0,'seen_at'=>0];
    $candidate['count']=$now-($candidate['seen_at']??0)<=5?(int)$candidate['count']+1:1; $candidate['seen_at']=$now; $confirmations[$key]=$candidate; $_SESSION['staff_face_login_confirmations']=$confirmations;
    $distance=(float)($match['distance']??1); $needed=$distance<=0.38?1:2;
    if($candidate['count']<$needed){echo json_encode(['ok'=>false,'faces'=>$faces,'message'=>'Face found. Confirming identity…','frame_width'=>$result['frame_width']??0,'frame_height'=>$result['frame_height']??0]);exit;}
    if ($role === 'administrator') {
        $stmt=$conn->prepare('SELECT ADMIN_USER FROM admins WHERE ADMIN_ID=? LIMIT 1');$stmt->bind_param('i',$id);$stmt->execute();$account=$stmt->get_result()->fetch_assoc();
        if(!$account) continue; session_regenerate_id(true); $_SESSION['is-authenticated']=true; $_SESSION['user-role']='administrator'; $_SESSION['admin-user']=$account['ADMIN_USER'];
        echo json_encode(['ok'=>true,'redirect'=>'home.php','faces'=>$faces]); exit;
    }
    $stmt=$conn->prepare("SELECT TEACHER_ID,TEACHER_NAME,SECTION FROM teachers WHERE TEACHER_ID=? AND COALESCE(TEACHER_STATUS,'Active')='Active' LIMIT 1");$stmt->bind_param('i',$id);$stmt->execute();$account=$stmt->get_result()->fetch_assoc();
    if(!$account) continue; session_regenerate_id(true); $_SESSION['is-authenticated']=true; $_SESSION['user-role']='teacher'; $_SESSION['teacher-id']=$account['TEACHER_ID']; $_SESSION['teacher-name']=$account['TEACHER_NAME']; $_SESSION['teacher-section']=$account['SECTION'];
    echo json_encode(['ok'=>true,'redirect'=>'../teacher/students.php','faces'=>$faces]); exit;
}
echo json_encode(['ok'=>false,'faces'=>$faces,'message'=>$result['message']??'Face not recognized.','frame_width'=>$result['frame_width']??0,'frame_height'=>$result['frame_height']??0]);
