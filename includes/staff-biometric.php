<?php
/* Staff biometric enrollment and live scanner support.
 * Face templates are kept in a non-public folder.
 */
function staffBiometricRoot(): string { return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'staff-faces'; }
function staffBiometricIdentity(string $role, int $id): string { return ($role === 'administrator' ? 'admin' : 'teacher') . '_' . $id; }
function ensureStaffBiometricSchema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS staff_biometrics (ROLE ENUM('administrator','teacher') NOT NULL, STAFF_ID INT NOT NULL, FACE_PATH VARCHAR(255) NOT NULL, ENROLLED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (ROLE, STAFF_ID)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $root = staffBiometricRoot();
    if (!is_dir($root)) @mkdir($root, 0700, true);
}
function staffHasBiometric(mysqli $conn, string $role, int $id): bool {
    ensureStaffBiometricSchema($conn);
    $stmt = $conn->prepare('SELECT 1 FROM staff_biometrics WHERE ROLE=? AND STAFF_ID=? LIMIT 1');
    $stmt->bind_param('si', $role, $id); $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}
function staffCameraImage(string $field = 'biometric_frame'): ?string {
    $upload = $_FILES[$field] ?? null;
    if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($upload['tmp_name'] ?? '') && ($upload['size'] ?? 0) >= 1024 && ($upload['size'] ?? 0) <= 2 * 1024 * 1024) {
        $info = @getimagesize($upload['tmp_name']);
        if ($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) return $upload['tmp_name'];
    }
    $frame = $_POST[$field] ?? '';
    if (!is_string($frame) || !preg_match('#^data:image/(?:jpeg|png);base64,([A-Za-z0-9+/=]+)$#', $frame, $match)) return null;
    $bytes = base64_decode($match[1], true);
    if ($bytes === false || strlen($bytes) < 1024 || strlen($bytes) > 2 * 1024 * 1024) return null;
    $file = tempnam(sys_get_temp_dir(), 'staff-face-');
    if ($file === false || file_put_contents($file, $bytes, LOCK_EX) === false) { if ($file) @unlink($file); return null; }
    $info = @getimagesize($file);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) { @unlink($file); return null; }
    return $file;
}
function recognizeStaffWithService(string $imagePath): ?array {
    $payload = json_encode(['image_path' => $imagePath, 'directory' => staffBiometricRoot()]);
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload, 'timeout' => 3, 'ignore_errors' => true]]);
    $raw = @file_get_contents('http://127.0.0.1:8773/recognize', false, $context);
    $result = json_decode($raw ?: '', true);
    return is_array($result) ? $result : null;
}
function recognizeStaffLive(string $imagePath): ?array {
    $result = recognizeStaffWithService($imagePath);
    if ($result !== null) return $result;
    if ((microtime(true) - (float)($_SESSION['staff_face_service_attempted_at'] ?? 0)) > 5) {
        $_SESSION['staff_face_service_attempted_at'] = microtime(true);
        $python = 'C:\\Users\\jaxim\\AppData\\Local\\Python\\pythoncore-3.14-64\\python.exe';
        $runner = is_file($python) ? escapeshellarg($python) : 'py -3';
        $service = escapeshellarg(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'recognize_staff_face_service.py');
        if (PHP_OS_FAMILY === 'Windows') @pclose(@popen('start "" /B ' . $runner . ' ' . $service . ' >NUL 2>&1', 'r'));
        usleep(1500000);
        $result = recognizeStaffWithService($imagePath);
    }
    return $result;
}
function runStaffFaceRecognition(string $imagePath, string $expectedIdentity): ?array {
    $python = 'C:\\Users\\jaxim\\AppData\\Local\\Python\\pythoncore-3.14-64\\python.exe';
    $runner = is_file($python) ? escapeshellarg($python) : 'py -3';
    $script = escapeshellarg(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'recognize_staff_face.py');
    $raw = shell_exec($runner . ' ' . $script . ' ' . escapeshellarg($imagePath) . ' ' . escapeshellarg(staffBiometricRoot()) . ' ' . escapeshellarg($expectedIdentity) . ' 2>NUL');
    $result = json_decode($raw ?? '', true);
    return is_array($result) ? $result : null;
}
function verifyStaffBiometric(mysqli $conn, string $role, int $id, &$error): bool {
    if (!staffHasBiometric($conn, $role, $id)) return true; // supports a staged enrollment rollout
    $frame = staffCameraImage();
    if ($frame === null) { $error = 'Camera verification is required for this account.'; return false; }
    $result = runStaffFaceRecognition($frame, staffBiometricIdentity($role, $id)); @unlink($frame);
    if (($result['verified'] ?? false) !== true) { $error = $result['message'] ?? 'Face verification failed. Look directly at the camera and try again.'; return false; }
    return true;
}
function enrollStaffBiometric(mysqli $conn, string $role, int $id, &$error): bool {
    ensureStaffBiometricSchema($conn);
    $frame = staffCameraImage();
    if ($frame === null) { $error = 'Capture a clear camera image before saving.'; return false; }
    $result = runStaffFaceRecognition($frame, '');
    if (($result['face_count'] ?? 0) !== 1) { @unlink($frame); $error = 'Exactly one clear face must be visible for enrollment.'; return false; }
    $identity = staffBiometricIdentity($role, $id);
    $target = staffBiometricRoot() . DIRECTORY_SEPARATOR . $identity . '.jpg';
    // copy() replaces an existing enrollment on Windows, whereas rename()
    // cannot replace an existing destination there.
    if (!@copy($frame, $target)) { @unlink($frame); $error = 'Could not securely save the face enrollment.'; return false; }
    @unlink($frame);
    $stmt = $conn->prepare('INSERT INTO staff_biometrics (ROLE,STAFF_ID,FACE_PATH) VALUES (?,?,?) ON DUPLICATE KEY UPDATE FACE_PATH=VALUES(FACE_PATH), ENROLLED_AT=CURRENT_TIMESTAMP');
    $stmt->bind_param('sis', $role, $id, $target);
    if (!$stmt->execute()) { @unlink($target); $error = 'Could not record biometric enrollment.'; return false; }
    return true;
}
