<?php
if (!defined("MOBILE_API_AUTHENTICATED")) {
    require __DIR__ . "/scanner-guard.php";
}
require_once __DIR__ . "/../config/db_conn.php";
require_once __DIR__ . "/../includes/student-profile-schema.php";
require_once __DIR__ . "/../includes/attendance-schema.php";
ensureStudentProfileColumns($conn);
require_once __DIR__ . '/../includes/student-face-references.php';
$studentFaceReferences = recognitionStudentReferences($conn);
ensureAttendanceTimeOutColumn($conn);
ensureAttendanceSnapshotColumns($conn);
require_once __DIR__ . '/../includes/attendance-scan-events-schema.php';
ensureAttendanceScanEventsSchema($conn);
// Opportunistic, bounded retention for unsuccessful attempts only.
if (function_exists('random_int') && random_int(1, 500) === 1) {
    pruneAttendanceScanEvents($conn);
}
// Scanner attribution comes from the authenticated server-side session, never
// from a value supplied in the request body.
$scanSource = defined('MOBILE_API_AUTHENTICATED') ? 'mobile' : 'webcam';
$scannerId = defined('MOBILE_API_AUTHENTICATED') ? (string) ($_SESSION['scanner_id'] ?? '') : '';
$scannerName = defined('MOBILE_API_AUTHENTICATED') ? (string) ($_SESSION['scanner_name'] ?? '') : 'Webcam';
header("Content-Type: application/json");
$imagePath = "";
$removeImage = false;
$upload = $_FILES["frame"] ?? null;
// A JPEG upload avoids base64 expansion and a decode/write cycle on every
// frame, which is especially noticeable with higher-resolution USB cameras.
if (
    is_array($upload) &&
    ($upload["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK &&
    is_uploaded_file($upload["tmp_name"] ?? "") &&
    ($upload["size"] ?? 0) > 0 &&
    ($upload["size"] ?? 0) <= 2 * 1024 * 1024
) {
    $imageInfo = @getimagesize($upload["tmp_name"]);
    if (
        $imageInfo &&
        in_array($imageInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
    ) {
        $imagePath = $upload["tmp_name"];
    }
}
if ($imagePath === "") {
    $frame = $_POST["frame"] ?? "";
    if (
        !preg_match('#^data:image/(?:jpeg|png);base64,(.+)$#', $frame, $matches)
    ) {
        http_response_code(400);
        echo json_encode([
            "match" => false,
            "faces" => [],
            "matches" => [],
            "message" => "Invalid camera frame.",
        ]);
        exit();
    }
    $temporaryFile = tempnam(sys_get_temp_dir(), "biometric-scan-");
    if ($temporaryFile === false) {
        http_response_code(500);
        echo json_encode([
            "match" => false,
            "faces" => [],
            "matches" => [],
            "message" => "Could not create a temporary camera frame.",
        ]);
        exit();
    }
    $imagePath = $temporaryFile . ".jpg";
    @rename($temporaryFile, $imagePath);
    $imageData = base64_decode($matches[1], true);
    if (
        $imageData === false ||
        file_put_contents($imagePath, $imageData, LOCK_EX) === false
    ) {
        @unlink($imagePath);
        http_response_code(500);
        echo json_encode([
            "match" => false,
            "faces" => [],
            "matches" => [],
            "message" => "Could not save the camera frame for recognition.",
        ]);
        exit();
    }
    $removeImage = true;
}
function recognizeWithService(string $imagePath): ?array
{
    global $studentFaceReferences;
    $payload = json_encode(['image_path' => $imagePath, 'references' => $studentFaceReferences]);
    $context = stream_context_create([
        "http" => [
            "method" => "POST",
            "header" => "Content-Type: application/json\r\n",
            "content" => $payload,
            "timeout" => 8,
            "ignore_errors" => true,
        ],
    ]);
    $raw = @file_get_contents(
        "http://127.0.0.1:8774/recognize",
        false,
        $context,
    );
    $result = json_decode($raw ?: "", true);
    if (!is_array($result) || ($result['reference_protocol'] ?? 0) !== 1 || !empty($result['service_error']) || ($result['message'] ?? '') === 'Recognition service error.') {
        return null;
    }
    return $result;
}
function recognizeWithOnnxService(string $imagePath): ?array
{
    global $studentFaceReferences;
    $payload = json_encode(['image_path' => $imagePath, 'references' => $studentFaceReferences]);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 4,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents('http://127.0.0.1:8775/recognize', false, $context);
    $result = json_decode($raw ?: '', true);
    return is_array($result) && empty($result['service_error']) ? $result : null;
}
$pythonCommand = biometricPythonCommand();
$onnxPythonPath = __DIR__ . DIRECTORY_SEPARATOR . '.insightface-venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
$onnxResult = recognizeWithOnnxService($imagePath);
if ($onnxResult === null && is_file($onnxPythonPath) && microtime(true) - (float)($_SESSION['onnx_face_service_attempted_at'] ?? 0) > 5) {
    $_SESSION['onnx_face_service_attempted_at'] = microtime(true);
    $onnxService = escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'recognize_face_onnx_service.py');
    if (PHP_OS_FAMILY === 'Windows') @pclose(@popen('start "" /B ' . escapeshellarg($onnxPythonPath) . ' ' . $onnxService . ' >NUL 2>&1', 'r'));
}
$legacyResult = recognizeWithService($imagePath);
$result = is_array($onnxResult) && !empty($onnxResult['matches']) ? $onnxResult : $legacyResult;
// Keep recognition in one long-running Python process.  Re-loading every
// enrolled face for every camera frame is the main source of scan latency.
if (
    $result === null &&
    microtime(true) - (float) ($_SESSION["face_service_attempted_at"] ?? 0) > 5
) {
    $_SESSION["face_service_attempted_at"] = microtime(true);
    $service = escapeshellarg(
        __DIR__ . DIRECTORY_SEPARATOR . "recognize_face_service.py",
    );
    if (PHP_OS_FAMILY === "Windows") {
        @pclose(
            @popen(
                'start "" /B ' . $pythonCommand . " " . $service . " >NUL 2>&1",
                "r",
            ),
        );
    }
    // A cold Python service takes longer than a fraction of a second to load
    // its face-recognition libraries and bind to the local port.
    usleep(1500000);
    $result = recognizeWithService($imagePath);
}
if ($result === null) {
    $script = escapeshellarg(
        __DIR__ . DIRECTORY_SEPARATOR . "recognize_face.py",
    );
    $referenceFile = tempnam(sys_get_temp_dir(), 'face-references-');
    if ($referenceFile === false || file_put_contents($referenceFile, json_encode($studentFaceReferences)) === false) {
        throw new RuntimeException('Could not prepare recognition references.');
    }
    $raw = shell_exec(
        $pythonCommand .
            " " .
            $script .
            " " .
            escapeshellarg($imagePath) . ' ' . escapeshellarg($referenceFile) .
            " 2>NUL",
    );
    @unlink($referenceFile);
    $result = json_decode($raw ?? "", true);
}
if ($removeImage) {
    @unlink($imagePath);
}
$result = is_array($result)
    ? $result
    : [
        "match" => false,
        "faces" => [],
        "matches" => [],
        "message" =>
            "Recognition service is unavailable. Check the local Python recognition service, then restart the scanner.",
    ];
$faces = $result["faces"] ?? [];
$detectedFaces = $result["detected_faces"] ?? $faces;
// A strict unknown face is clear, frontal, and sharp enough for the recognizer
// to safely classify as unknown. It is used only for the on-screen warning.
$strictUnknownFaces = $result["unregistered_faces"] ?? [];
// Unknown face snapshots and database entries are intentionally disabled.
$unregisteredFaces = [];
$recognized = $result["matches"] ?? [];
// The face engine reads its local enrollment-photo folder, which deliberately
// retains archived profiles for record keeping. Before a recognition result is
// allowed to reach a scanner, keep only students who are currently Enrolled.
// This prevents an archived, transferred, dropped, or graduated student from
// showing a name/photo or receiving a success result in the mobile app.
$enrollmentCheck = $conn->prepare(
    "SELECT 1 FROM students WHERE STUDENT_ID=? AND ENROLLMENT_STATUS='Enrolled' LIMIT 1",
);
$activeRecognized = [];
foreach ($recognized as $recognizedFace) {
    $recognizedId = trim((string) ($recognizedFace["student_id"] ?? ""));
    if ($recognizedId === "") {
        continue;
    }
    $enrollmentCheck->bind_param("s", $recognizedId);
    $enrollmentCheck->execute();
    if ($enrollmentCheck->get_result()->num_rows === 1) {
        $activeRecognized[] = $recognizedFace;
    }
}
$recognized = $activeRecognized;
// Raw face-detector boxes can occasionally be triggered by a bright window,
// poster, or background texture.  They are kept for unregistered-face review
// but are not sent to the visible corner overlay until identity is confirmed.
if (!$recognized) {
    unset($_SESSION["face_match_confirmations"]);
    // Log at most one unidentified-face attempt per scanner every 10 seconds so
    // a continuous stream of failed frames cannot flood the event table. The
    // row intentionally carries NO student id (identity was never established).
    $lastUnrecognized = (float) ($_SESSION["scan_unrecognized_logged_at"] ?? 0);
    if (!empty($strictUnknownFaces) && microtime(true) - $lastUnrecognized > 10) {
        $_SESSION["scan_unrecognized_logged_at"] = microtime(true);
        logAttendanceScanEvent($conn, [
            "student_id" => null,
            "event_type" => "unrecognized",
            "result" => "unrecognized",
            "scanner_source" => $scanSource,
            "scanner_id" => $scannerId,
            "scanner_name" => $scannerName,
            "detail" => "Face not recognized.",
        ]);
    }
    echo json_encode([
        "match" => false,
        "faces" => [],
        "preview_faces" => $faces,
        "detected_faces" => $detectedFaces,
        "matches" => [],
        "unregistered" => $unregisteredFaces,
        "unrecognized" => !empty($strictUnknownFaces),
        "message" => "Face not recognized.",
    ]);
    exit();
}
$scanType = $_POST["scan_type"] ?? "auto";
if (!in_array($scanType, ["auto", "time_in", "time_out"], true)) {
    $scanType = "auto";
}
$studentQuery = $conn->prepare(
    "SELECT STUDENT_ID, STUDENT_NAME, IMAGE_PATH, ENROLLMENT_STATUS, GRADE, STRAND, SECTION FROM students WHERE STUDENT_ID = ? LIMIT 1",
);
$check = $conn->prepare(
    "SELECT ID, TIME_IN, LUNCH_OUT, AFTERNOON_TIME_IN, TIME_OUT, ATTENDANCE_REMARK FROM attendance WHERE STUDENT_ID = ? AND DATE(TIME_IN) = ? ORDER BY TIME_IN DESC LIMIT 1",
);
$log = $conn->prepare(
    "INSERT INTO attendance (STUDENT_ID, STUDENT_NAME, TIME_IN, GRADE_SNAPSHOT, STRAND_SNAPSHOT, SECTION_SNAPSHOT) VALUES (?, ?, NOW(), ?, ?, ?)",
);
$lunchOut = $conn->prepare(
    "UPDATE attendance SET LUNCH_OUT=NOW() WHERE ID=? AND LUNCH_OUT IS NULL",
);
$afternoonTimeIn = $conn->prepare(
    "UPDATE attendance SET AFTERNOON_TIME_IN=NOW() WHERE ID=? AND AFTERNOON_TIME_IN IS NULL",
);
$timeOut = $conn->prepare(
    "UPDATE attendance SET TIME_OUT=NOW() WHERE ID=? AND TIME_OUT IS NULL",
);
$clearManualAbsent = $conn->prepare(
    "DELETE FROM attendance WHERE ID=? AND ATTENDANCE_REMARK='Absent' AND IS_AUTO_ABSENT=0",
);
$today = date("Y-m-d");
$wholeDayAttendance = attendanceUsesWholeDay($conn);
$seen = [];
$found = [];
$recognizedBoxes = [];
$now = microtime(true);
$confirmations = $_SESSION["face_match_confirmations"] ?? [];
foreach ($confirmations as $key => $candidate) {
    if ($now - ($candidate["seen_at"] ?? 0) > 6) {
        unset($confirmations[$key]);
    }
}
foreach ($recognized as $recognizedFace) {
    $id = (string) ($recognizedFace["student_id"] ?? "");
    $box = $recognizedFace["box"] ?? null;
    $distance = isset($recognizedFace["distance"])
        ? (float) $recognizedFace["distance"]
        : 1 - (float) ($recognizedFace["confidence"] ?? 0);
    if ($distance > 0.60) {
        continue;
    }
    if ($box) {
        $recognizedBoxes[json_encode($box)] = true;
    }
    if (!$id || isset($seen[$id])) {
        continue;
    }
    $seen[$id] = true;
    $key = $scanType . "|" . $id;
    $candidate = $confirmations[$key] ?? ["count" => 0, "seen_at" => 0];
    $candidate["count"] =
        $now - ($candidate["seen_at"] ?? 0) <= 5
            ? (int) $candidate["count"] + 1
            : 1;
    $candidate["seen_at"] = $now;
    $confirmations[$key] = $candidate;
    // A very close match is safe to record immediately. Borderline matches
    // still need the same identity in two consecutive camera frames.
    $requiredConfirmations = $distance <= 0.43 ? 1 : 2;
    if ($candidate["count"] < $requiredConfirmations) {
        continue;
    }

    $studentQuery->bind_param("s", $id);
    $studentQuery->execute();
    $student = $studentQuery->get_result()->fetch_assoc();
    if (!$student) {
        continue;
    }

    $logged = false;
    $message = "";
    $action = $scanType;
    $attendanceId = null;
    if (($student["ENROLLMENT_STATUS"] ?? "Enrolled") !== "Enrolled") {
        $message =
            "Student is " .
            strtolower($student["ENROLLMENT_STATUS"]) .
            " and cannot record attendance.";
        // Non-critical: a rejected attempt carries no attendance write.
        logAttendanceScanEvent($conn, [
            "student_id" => $student["STUDENT_ID"],
            "event_type" => "ignored",
            "result" => "rejected",
            "scanner_source" => $scanSource,
            "scanner_id" => $scannerId,
            "scanner_name" => $scannerName,
            "detail" => $message,
            "grade" => $student["GRADE"],
            "strand" => $student["STRAND"],
            "section" => $student["SECTION"],
            "dedupe_seconds" => 30,
        ]);
    } else {
        // The attendance write and its success event are committed together so
        // the log can never claim a scan that was not recorded (and a recorded
        // scan can never go unlogged).
        try {
            $conn->begin_transaction();
            $check->bind_param("ss", $student["STUDENT_ID"], $today);
            $check->execute();
            $record = $check->get_result()->fetch_assoc();
            if ($record && ($record["ATTENDANCE_REMARK"] ?? "") === "Absent") {
                $clearManualAbsent->bind_param("i", $record["ID"]);
                $clearManualAbsent->execute();
                $record = null;
            }
            $cooldown =
                $record && $record["TIME_OUT"] === null
                    ? attendanceTimeOutCooldownRemaining(
                        $record["AFTERNOON_TIME_IN"] ?? $record["TIME_IN"],
                    )
                    : 0;

            if ($scanType === "auto") {
                $next = attendanceNextAutomaticAction(
                    $record,
                    null,
                    $wholeDayAttendance,
                );
                $action = $next["action"];
                $message = $next["message"];
                if ($action === "time_in") {
                    $log->bind_param(
                        "ssiss",
                        $student["STUDENT_ID"],
                        $student["STUDENT_NAME"],
                        $student["GRADE"],
                        $student["STRAND"],
                        $student["SECTION"],
                    );
                    $log->execute();
                    $logged = true;
                    $attendanceId = (int) $conn->insert_id;
                } elseif ($action === "lunch_out") {
                    $lunchOut->bind_param("i", $record["ID"]);
                    $lunchOut->execute();
                    $logged = $lunchOut->affected_rows > 0;
                    $attendanceId = (int) $record["ID"];
                    if (!$logged) {
                        $message = "Lunch Out already recorded.";
                    }
                } elseif ($action === "afternoon_time_in") {
                    $afternoonTimeIn->bind_param("i", $record["ID"]);
                    $afternoonTimeIn->execute();
                    $logged = $afternoonTimeIn->affected_rows > 0;
                    $attendanceId = (int) $record["ID"];
                    if (!$logged) {
                        $message = "Afternoon Time In already recorded.";
                    }
                } elseif ($action === "time_out") {
                    $timeOut->bind_param("i", $record["ID"]);
                    $timeOut->execute();
                    $logged = $timeOut->affected_rows > 0;
                    $attendanceId = (int) $record["ID"];
                    if (!$logged) {
                        $message = "Time Out already recorded.";
                    }
                }
            } elseif ($scanType === "time_in") {
                if (!$record) {
                    $log->bind_param(
                        "ssiss",
                        $student["STUDENT_ID"],
                        $student["STUDENT_NAME"],
                        $student["GRADE"],
                        $student["STRAND"],
                        $student["SECTION"],
                    );
                    $log->execute();
                    $logged = true;
                    $attendanceId = (int) $conn->insert_id;
                    $message = "Time in recorded";
                } else {
                    $message = "Time in already recorded";
                }
            } elseif (!$record) {
                $message = "No time-in record today";
            } elseif ($record["TIME_OUT"] !== null) {
                $message = "Time out already recorded";
            } elseif ($cooldown > 0) {
                $action = "cooldown";
                $message = attendanceTimeOutCooldownMessage($cooldown);
            } else {
                $timeOut->bind_param("i", $record["ID"]);
                $timeOut->execute();
                $logged = $timeOut->affected_rows > 0;
                $attendanceId = (int) $record["ID"];
                $message = $logged
                    ? "Time out recorded"
                    : "Time out already recorded";
            }

            $result = $logged
                ? ATTENDANCE_SCAN_RESULT_SUCCESS
                : ($action === "cooldown"
                    ? "cooldown"
                    : (in_array(
                        $action,
                        ["morning_protected", "waiting_afternoon", "complete"],
                        true,
                    )
                        ? "ignored"
                        : "duplicate"));
            $eventType =
                $logged &&
                in_array(
                    $action,
                    ["time_in", "lunch_out", "afternoon_time_in", "time_out"],
                    true,
                )
                    ? $action
                    : "ignored";
            logAttendanceScanEvent($conn, [
                "attendance_id" => $logged ? $attendanceId : null,
                "student_id" => $student["STUDENT_ID"],
                "event_type" => $eventType,
                "result" => $result,
                "scanner_source" => $scanSource,
                "scanner_id" => $scannerId,
                "scanner_name" => $scannerName,
                "detail" => $message,
                "grade" => $student["GRADE"],
                "strand" => $student["STRAND"],
                "section" => $student["SECTION"],
                "dedupe_seconds" => $logged ? 0 : 20,
            ]);
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            error_log("scan_match_write_failed: " . $error->getMessage());
            $logged = false;
            $attendanceId = null;
            $message = "Attendance could not be saved. Please scan again.";
        }
    }

    $found[] = [
        "student" => $student,
        "logged" => $logged,
        "action" => $action,
        "message" => $message,
        "confidence" => $recognizedFace["confidence"] ?? null,
        "box" => $box,
    ];
}
$_SESSION["face_match_confirmations"] = $confirmations;
$unregistered = [];
foreach ($unregisteredFaces as $face) {
    if (!isset($recognizedBoxes[json_encode($face["box"] ?? [])])) {
        $unregistered[] = $face;
    }
}
if (!$found) {
    echo json_encode([
        "match" => false,
        "faces" => [],
        "preview_faces" => $faces,
        "detected_faces" => $detectedFaces,
        "recognized_faces" => $recognized,
        "matches" => [],
        "unregistered" => $unregistered,
        "message" => "Face detected. Confirming identity…",
    ]);
    exit();
}
$displayFaces = [];
foreach ($found as $matchedFace) {
    if (isset($matchedFace["box"])) {
        $displayFaces[] = ["box" => $matchedFace["box"]];
    }
}
echo json_encode([
    "match" => true,
    "faces" => $displayFaces,
    "preview_faces" => $faces,
    "detected_faces" => $detectedFaces,
    "recognized_faces" => $recognized,
    "matches" => $found,
    "unregistered" => $unregistered,
]);
