<?php
session_start();
require "../config/db_conn.php";
require "page-guard.php";
require '../includes/student-profile-schema.php';
require '../includes/student-placement-options.php';
ensureStudentProfileColumns($conn);

if(!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']){
    die("Invalid CSRF token.");
}

$student_id = trim($_POST["student_id"]);
$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$student_name = trim($first_name . ' ' . $last_name);
$section = trim($_POST["student_section"] ?? '');
$grade = (int)($_POST['grade'] ?? 0);
$age = (int)($_POST['age'] ?? 0);
$strand = trim($_POST['strand'] ?? '');
$address = trim($_POST['address'] ?? '');

if(empty($student_id) || empty($first_name) || empty($last_name) || !isValidStudentAge($age) || $address==='' || strlen($address)>255 || !isValidStudentPlacement($grade, $strand, $section)){
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

// 2. IMAGE CONFIGURATION
$targetDir = "../uploads/students/";
$pythonDir = "C:/Biometric_Capstone/known_faces/"; 
$filename = "";

if (!empty($_POST['captured_image'])) {
    $data = $_POST['captured_image'];
    $data = str_replace('data:image/jpeg;base64,', '', $data);
    $data = str_replace(' ', '+', $data);
    $filename = $student_id . "_" . str_replace(' ', '_', $student_name) . ".jpg";
    file_put_contents($targetDir . $filename, base64_decode($data));
} 
elseif (isset($_FILES["student_image"]) && $_FILES["student_image"]["error"] === UPLOAD_ERR_OK) {
    $file = $_FILES["student_image"];
    $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
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
        copy($targetPath, $pythonPath);
        $db_path = "uploads/students/" . $filename;

        $sql = "INSERT INTO students (STUDENT_ID, STUDENT_NAME, FIRST_NAME, LAST_NAME, GRADE, AGE, STRAND, ADDRESS, SECTION, IMAGE_PATH) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $insert = $conn->prepare($sql);
        $insert->bind_param("ssssiissss", $student_id, $student_name, $first_name, $last_name, $grade, $age, $strand, $address, $section, $db_path);

        if($insert->execute()){
            $_SESSION["toast"] = ["message" => "Success: Student registered!", "type" => "success"];
            header("Location: add-student.php");
            exit;
        } else {
            die("Database error: " . $insert->error);
        }
    }
}
?>
