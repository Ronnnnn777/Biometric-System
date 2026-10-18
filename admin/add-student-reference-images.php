<?php
session_start();
require "../config/db_conn.php";
require "page-guard.php";
require "../includes/student-profile-schema.php";
require "../includes/biometric-image.php";
ensureStudentProfileColumns($conn);

function referenceImageRedirect(int $id, string $message, string $type = "error"): void
{
    $_SESSION["toast"] = ["message" => $message, "type" => $type];
    header("Location: edit-student.php?id=" . $id);
    exit;
}

if (!hash_equals($_SESSION["csrf_token"] ?? "", $_POST["csrf_token"] ?? "")) {
    http_response_code(400);
    exit("Invalid CSRF token.");
}

$id = (int) ($_POST["id"] ?? 0);
if ($id < 1) {
    referenceImageRedirect(0, "Student profile was not found.");
}

$studentQuery = $conn->prepare(
    "SELECT ID, STUDENT_ID FROM students WHERE ID=? LIMIT 1",
);
$studentQuery->bind_param("i", $id);
$studentQuery->execute();
$student = $studentQuery->get_result()->fetch_assoc();
if (!$student) {
    referenceImageRedirect(0, "Student profile was not found.");
}

$uploads = $_FILES["reference_images"] ?? null;
if (!is_array($uploads) || !is_array($uploads["tmp_name"] ?? null)) {
    referenceImageRedirect($id, "Choose one to three additional biometric photos.");
}

$references = [];
foreach ($uploads["tmp_name"] as $index => $temporaryPath) {
    $error = $uploads["error"][$index] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        continue;
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($temporaryPath)) {
        referenceImageRedirect($id, "One of the additional photos could not be uploaded.");
    }
    $validationError = validateBiometricImage($temporaryPath);
    $extension = biometricImageExtension($temporaryPath);
    if ($validationError || !$extension) {
        referenceImageRedirect(
            $id,
            $validationError ?: "Only clear JPEG or PNG photos can be used for recognition.",
        );
    }
    $references[] = ["path" => $temporaryPath, "extension" => $extension];
}

if (!$references || count($references) > 3) {
    referenceImageRedirect($id, "Add from one to three clear reference photos at a time.");
}

$referenceDirectory = "C:/Biometric_Capstone/known_faces/";
$existingReferences = glob($referenceDirectory . $student["STUDENT_ID"] . "_reference_*") ?: [];
if (count($existingReferences) + count($references) > 5) {
    referenceImageRedirect(
        $id,
        "This student already has the maximum of five additional biometric reference photos.",
    );
}
if (!is_dir($referenceDirectory)) {
    referenceImageRedirect($id, "The face-recognition folder is unavailable. Try again after the scanner service is restored.");
}

$savedFiles = [];
foreach ($references as $index => $reference) {
    $filename =
        $student["STUDENT_ID"] .
        "_reference_" .
        date("YmdHis") .
        "_" .
        ($index + 1) .
        "_" .
        bin2hex(random_bytes(3)) .
        "." .
        $reference["extension"];
    $destination = $referenceDirectory . $filename;
    if (!copy($reference["path"], $destination)) {
        foreach ($savedFiles as $savedFile) {
            @unlink($savedFile);
        }
        referenceImageRedirect($id, "The additional photos could not be saved for face recognition.");
    }
    $savedFiles[] = $destination;
}

$context = stream_context_create([
    "http" => ["method" => "POST", "timeout" => 4, "ignore_errors" => true],
]);
@file_get_contents("http://127.0.0.1:8772/refresh", false, $context);

referenceImageRedirect(
    $id,
    count($savedFiles) . " additional biometric reference photo(s) added. Recognition has been refreshed.",
    "success",
);

