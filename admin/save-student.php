<?php
session_start();
require "../config/db_conn.php";
require "page-guard.php";
require '../includes/student-profile-schema.php';
require '../includes/student-placement-options.php';
require '../includes/section-catalog-schema.php';
require '../includes/biometric-image.php';
ensureStudentProfileColumns($conn);
ensureSectionCatalogColumns($conn);
seedDefaultTeacherSections($conn);

if(!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']){
    die("Invalid CSRF token.");
}

$student_id = trim($_POST["student_id"]);
$first_name = trim($_POST['first_name'] ?? '');
$middle_name = trim($_POST['middle_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$student_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
$section = trim($_POST["student_section"] ?? '');
$grade = (int)($_POST['grade'] ?? 0);
$age = (int)($_POST['age'] ?? 0);
$strand = trim($_POST['strand'] ?? '');
$address = trim($_POST['address'] ?? '');

$validPlacement = isValidStudentPlacement($grade, $strand, $section) || isApprovedStudentSection($conn, $grade, $strand, $section);
if(empty($student_id) || empty($first_name) || empty($last_name) || !isValidStudentAge($age) || $address==='' || strlen($address)>255 || !$validPlacement){
    $_SESSION["toast"] = ["message" => "All fields are required.", "type" => "error"];
    header("Location: add-student.php");
    exit; 
}

// 1. STRICT DUPLICATE CHECK
$checkSql = "SELECT STUDENT_ID FROM students WHERE STUDENT_ID = ?";
$checkStmt = $conn->prepare($checkSql);
$checkStmt->bind_param("s", $student_id);
$checkStmt->execute();
if($checkStmt->get_result()->num_rows > 0) {
    header("Location: add-student.php?msg=duplicate&id=" . urlencode($student_id));
    exit; // Stops the script from inserting a duplicate
}

if (!studentSectionHasCapacity($conn, $grade, $strand, $section)) {
    $_SESSION['toast'] = ['message' => studentSectionCapacityMessage($grade, $section), 'type' => 'error'];
    header('Location: add-student.php');
    exit;
}

// 2. IMAGE CONFIGURATION
$targetDir = "../uploads/students/";
$pythonDir = "C:/Biometric_Capstone/known_faces/"; 
$filename = "";

function refreshStudentRecognitionService(): void {
    $context=stream_context_create(['http'=>['method'=>'POST','timeout'=>2,'ignore_errors'=>true]]);
    @file_get_contents('http://127.0.0.1:8772/refresh',false,$context);
}

if (!empty($_POST['captured_image'])) {
    $data = $_POST['captured_image'];
    $data = str_replace('data:image/jpeg;base64,', '', $data);
    $data = str_replace(' ', '+', $data);
    $filename = $student_id . "_" . str_replace(' ', '_', $student_name) . ".jpg";
    file_put_contents($targetDir . $filename, base64_decode($data));
} 
elseif (isset($_FILES["student_image"]) && $_FILES["student_image"]["error"] === UPLOAD_ERR_OK) {
    $file = $_FILES["student_image"];
    $validationError=validateBiometricImage($file['tmp_name']);
    $ext = biometricImageExtension($file['tmp_name']);
    if($validationError || !$ext) {
        $_SESSION["toast"] = ["message" => $validationError ?: "Only JPEG and PNG images are accepted for biometric registration.", "type" => "error"];
        header("Location: add-student.php");
        exit;
    }
    $filename = $student_id . "_" . str_replace(' ', '_', $student_name) . "." . $ext;
    move_uploaded_file($file["tmp_name"], $targetDir . $filename);
} 
else {
    $_SESSION["toast"] = ["message" => "Please provide a student image.", "type" => "error"];
    header("Location: add-student.php");
    exit;
}

// 3. DATABASE INSERT & SYNC
if($filename) {
    $targetPath = $targetDir . $filename;
    $pythonPath = $pythonDir . $filename;

    if(file_exists($targetPath)) {
        $validationError=validateBiometricImage($targetPath);
        if($validationError) {
            @unlink($targetPath);
            $_SESSION["toast"] = ["message" => $validationError, "type" => "error"];
            header("Location: add-student.php");
            exit;
        }
        if(!is_dir($pythonDir) || !copy($targetPath, $pythonPath)) {
            @unlink($targetPath);
            $_SESSION["toast"] = ["message" => "The student photo could not be added to face recognition. Please register the student again.", "type" => "error"];
            header("Location: add-student.php");
            exit;
        }
        $db_path = "uploads/students/" . $filename;

        $sql = "INSERT INTO students (STUDENT_ID, STUDENT_NAME, FIRST_NAME, MIDDLE_NAME, LAST_NAME, GRADE, AGE, STRAND, ADDRESS, SECTION, IMAGE_PATH) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $insert = $conn->prepare($sql);
        $insert->bind_param("sssssiissss", $student_id, $student_name, $first_name, $middle_name, $last_name, $grade, $age, $strand, $address, $section, $db_path);

        if($insert->execute()){
            refreshStudentRecognitionService();
            $_SESSION["toast"] = ["message" => "Success: Student registered!", "type" => "success"];
            header("Location: add-student.php");
            exit;
        } else {
            die("Database error: " . $insert->error);
        }
    }
}
?>
