<?php
// Multipart transport probe, available only in the isolated test server.
if (PHP_SAPI === 'cli-server' && getenv('FACE_CAPTURE_TEST') === '1') {
    require __DIR__ . '/../admin/page-guard.php';
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { http_response_code(400); exit; }
    $received = [];
    foreach (['front'=>'student_image', 'left'=>'face_left', 'right'=>'face_right'] as $pose=>$field) {
        $upload = $_FILES[$field] ?? null;
        if (!$upload) continue;
        if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) { http_response_code(400); exit; }
        $received[$pose] = ['field'=>$field, 'name'=>$upload['name'], 'size'=>$upload['size'], 'sha256'=>hash_file('sha256', $upload['tmp_name'])];
    }
    echo json_encode(['received'=>(object)$received, 'remove_right'=>($_POST['remove_face_right'] ?? '') === '1']);
    exit;
}
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/db_conn.php';
require __DIR__ . '/../includes/student-face-references.php';
$directory = $argv[1];
ini_set('session.save_path', $directory);
$sessions=[];
$tokens=[];
foreach (['administrator','teacher','mobile_scanner'] as $role) {
    session_id(bin2hex(random_bytes(16)));
    session_start();
    $_SESSION=['is-authenticated'=>true,'user-role'=>$role,'teacher-id'=>0,'csrf_token'=>bin2hex(random_bytes(16))];
    $sessions[$role]=session_id();
    $tokens[$role]=$_SESSION['csrf_token'];
    session_write_close();
}
$image=null;
$studentId = null;
foreach ($conn->query("SELECT r.STUDENT_ID, r.IMAGE_PATH FROM student_face_references r JOIN students s ON s.ID=r.STUDENT_ID WHERE r.POSE='front' AND s.ENROLLMENT_STATUS='Enrolled'") as $row) {
    if (str_starts_with($row['IMAGE_PATH'],'uploads/students/') && recognitionReferencePath($row['IMAGE_PATH'])) {
        $image='admin/student-face-image.php?legacy='.rawurlencode(basename($row['IMAGE_PATH'])); $studentId=(int)$row['STUDENT_ID']; break;
    }
}
echo json_encode(['sessions'=>$sessions,'csrf_tokens'=>$tokens,'image'=>$image,'student_id'=>$studentId]);
