<?php
require_once __DIR__ . '/biometric-image.php';

/** STUDENT_ID here is the stable students.ID key, not the editable LRN. */
function ensureStudentFaceReferences(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS student_face_references (
        ID INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        STUDENT_ID INT NOT NULL,
        POSE ENUM('front','left','right') NOT NULL,
        IMAGE_PATH VARCHAR(255) NOT NULL,
        CREATED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UPDATED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY student_pose (STUDENT_ID, POSE),
        CONSTRAINT face_reference_student FOREIGN KEY (STUDENT_ID) REFERENCES students(ID) ON DELETE CASCADE,
        CONSTRAINT valid_face_pose CHECK (POSE IN ('front','left','right'))
    ) ENGINE=InnoDB");
    // Metadata only: no copies, overwrites, or deletion of existing photos.
    $conn->query("INSERT INTO student_face_references (STUDENT_ID, POSE, IMAGE_PATH)
        SELECT s.ID, 'front', s.IMAGE_PATH FROM students s
        WHERE s.IMAGE_PATH<>'' AND NOT EXISTS (SELECT 1 FROM student_face_references r WHERE r.STUDENT_ID=s.ID AND r.POSE='front')
        ON DUPLICATE KEY UPDATE ID=student_face_references.ID");
}

function studentFaceReferences(mysqli $conn, int $id): array
{
    $stmt = $conn->prepare('SELECT POSE, IMAGE_PATH FROM student_face_references WHERE STUDENT_ID=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $references = [];
    foreach ($stmt->get_result() as $row) $references[$row['POSE']] = $row['IMAGE_PATH'];
    return $references;
}

/** Existing web photos are preferred; the historical scanner copy is a fallback. */
function recognitionReferencePath(string $path): ?string
{
    if (preg_match('#^admin/student-face-image.php\?file=([a-f0-9]{32}\.(?:jpg|png))$#D', $path, $match)) {
        $private = __DIR__ . '/../storage/student-faces/' . $match[1];
        return is_file($private) ? $private : null;
    }
    if (!preg_match('#^(?:uploads/students|image)/[^/\\\\]+\.(?:jpg|jpeg|png)$#iD', $path)) return null;
    $root = realpath(__DIR__ . '/..');
    $resolved = realpath($root . '/' . $path);
    if ($resolved && str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $root) . '/') && is_file($resolved)) return $resolved;
    $legacy = 'C:/Biometric_Capstone/known_faces/' . basename(str_replace('\\', '/', $path));
    return is_file($legacy) ? $legacy : null;
}

function recognitionStudentReferences(mysqli $conn): array
{
    $singleReference = getenv('STUDENT_FACE_REFERENCE_MODE') === 'single';
    $poseFilter = $singleReference ? " AND r.POSE='front'" : '';
    $rows = $conn->query("SELECT s.STUDENT_ID, r.POSE, r.IMAGE_PATH FROM students s
        JOIN student_face_references r ON r.STUDENT_ID=s.ID
        WHERE COALESCE(s.ENROLLMENT_STATUS,'Enrolled')='Enrolled' $poseFilter ORDER BY s.ID, r.POSE");
    $references = [];
    foreach ($rows as $row) {
        $path = recognitionReferencePath($row['IMAGE_PATH']);
        if ($path) $references[] = ['student_id' => $row['STUDENT_ID'], 'pose' => $row['POSE'], 'path' => $path];
    }
    return $references;
}

/** Validate the whole batch before saving anything. Captured front photos use the same checks. */
function prepareStudentFaceUploads(bool $requireFront = false, ?string $existingFront = null, array $existingReferences = []): array
{
    $prepared = [];
    try {
        foreach (['front', 'left', 'right'] as $pose) {
            if (!empty($_POST['remove_face_' . $pose]) && $pose !== 'front') {
                if (($_FILES['face_' . $pose]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) throw new RuntimeException('Choose either replacement or removal for the ' . $pose . ' view.');
                $prepared[$pose] = ['remove' => true];
                continue;
            }
            $upload = $_FILES[$pose === 'front' ? 'student_image' : 'face_' . $pose] ?? null;
            $temporary = null;
            if ($pose === 'front' && !empty($_POST['captured_image'])) {
                $data = $_POST['captured_image'];
                if (!is_string($data) || strlen($data) > 7 * 1024 * 1024 || !preg_match('#^data:image/jpeg;base64,(.+)$#', $data, $match)) throw new RuntimeException('Invalid captured front photo.');
                $bytes = base64_decode($match[1], true);
                if ($bytes === false) throw new RuntimeException('Invalid captured front photo.');
                $temporary = tempnam(sys_get_temp_dir(), 'student-face-');
                if (!$temporary) throw new RuntimeException('Could not prepare captured photo.');
                $prepared[$pose] = ['source' => $temporary, 'temporary' => true];
                if (file_put_contents($temporary, $bytes) === false) throw new RuntimeException('Could not save captured photo.');
                $source = $temporary;
            } else {
                if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) throw new RuntimeException("The $pose photo could not be uploaded (maximum 5 MB).");
                $source = $upload['tmp_name'];
                $prepared[$pose] = ['source' => $source, 'temporary' => false];
            }
            $frontPath = $prepared['front']['source'] ?? $existingFront;
            if ($pose !== 'front' && !$frontPath) throw new RuntimeException('Provide a valid front view before adding optional angles.');
            $error = validateBiometricImage($source, $pose, $pose === 'front' ? null : $frontPath);
            if ($error) throw new RuntimeException(ucfirst($pose) . ': ' . $error);
            $prepared[$pose]['extension'] = biometricImageExtension($source);
        }
        if ($requireFront && !isset($prepared['front'])) throw new RuntimeException('Please provide a front view photo.');
        if (isset($prepared['front'])) {
            foreach (['left', 'right'] as $pose) {
                if (isset($prepared[$pose]) || !isset($existingReferences[$pose])) continue;
                $source = recognitionReferencePath($existingReferences[$pose]);
                $error = $source ? validateBiometricImage($source, $pose, $prepared['front']['source']) : 'The existing optional reference is missing.';
                if ($error) throw new RuntimeException(ucfirst($pose) . ': ' . $error . ' Replace or remove this view when changing the front photo.');
            }
        }
        return $prepared;
    } catch (Throwable $error) {
        cleanupPreparedStudentFaces($prepared);
        throw $error;
    }
}

function cleanupPreparedStudentFaces(array $prepared): void
{
    foreach ($prepared as $photo) if (!empty($photo['temporary'])) @unlink($photo['source']);
}

/** Call inside the student transaction. Rollback removes only files created in this request. */
function saveStudentFaceUploads(mysqli $conn, int $id, array $prepared, array &$createdFiles): void
{
    if (!$prepared) return;
    $directory = __DIR__ . '/../storage/student-faces/';
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('Student photo folder is unavailable.');
    // Apache protection is installed before the first sensitive file is written.
    if (file_put_contents($directory . '.htaccess', "Require all denied\n") === false) throw new RuntimeException('Could not protect student photo storage.');
    foreach ($prepared as $pose => $photo) {
        if (!empty($photo['remove'])) {
            $stmt = $conn->prepare("DELETE FROM student_face_references WHERE STUDENT_ID=? AND POSE=? AND POSE<>'front'");
            $stmt->bind_param('is', $id, $pose);
            $stmt->execute();
            continue;
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $photo['extension'];
        $path = 'admin/student-face-image.php?file=' . $filename;
        $absolute = $directory . $filename;
        $createdFiles[] = $absolute;
        if (!copy($photo['source'], $absolute)) throw new RuntimeException('Could not save the student photo.');
        $stmt = $conn->prepare('INSERT INTO student_face_references (STUDENT_ID, POSE, IMAGE_PATH) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE IMAGE_PATH=VALUES(IMAGE_PATH)');
        $stmt->bind_param('iss', $id, $pose, $path);
        $stmt->execute();
        if ($pose === 'front') {
            $stmt = $conn->prepare('UPDATE students SET IMAGE_PATH=? WHERE ID=?');
            $stmt->bind_param('si', $path, $id);
            $stmt->execute();
        }
    }
    // Optional references may be removed only while a usable reference remains.
    $usable = false;
    foreach (studentFaceReferences($conn, $id) as $path) if (recognitionReferencePath($path)) $usable = true;
    if (!$usable) throw new RuntimeException('Keep at least one valid facial reference before removing photos. Upload a clear front view.');
}

function warmStudentFaceCache(mysqli $conn): bool
{
    $references = recognitionStudentReferences($conn);
    $payload = json_encode(['references' => $references]);
    $context = stream_context_create(['http' => ['method'=>'POST', 'header'=>"Content-Type: application/json\r\n", 'content'=>$payload, 'timeout'=>15, 'ignore_errors'=>true]]);
    $result = json_decode(@file_get_contents('http://127.0.0.1:8774/refresh', false, $context) ?: '', true);
    if (!empty($result['ok']) && ($result['reference_protocol'] ?? 0) === 1) {
        // ONNX is optional and runs beside the reference-protocol engine.
        // Its refresh failure never prevents a student profile from saving.
        $onnxContext = stream_context_create(['http' => ['method'=>'POST', 'header'=>"Content-Type: application/json\r\n", 'content'=>$payload, 'timeout'=>15, 'ignore_errors'=>true]]);
        @file_get_contents('http://127.0.0.1:8775/refresh', false, $onnxContext);
        return true;
    }
    $file = tempnam(sys_get_temp_dir(), 'face-warm-');
    if (!$file) return false;
    try {
        if (file_put_contents($file, $payload) === false) return false;
        $raw = shell_exec(biometricPythonCommand() . ' ' . escapeshellarg(__DIR__ . '/../admin/recognize_face.py') . ' --warm ' . escapeshellarg($file) . ' 2>NUL');
        return !empty(json_decode($raw ?: '', true)['ok']);
    } finally { @unlink($file); }
}
