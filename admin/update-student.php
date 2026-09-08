<?php
session_start();
require "../config/db_conn.php";
require "page-guard.php";
require '../includes/student-profile-schema.php';
ensureStudentProfileColumns($conn);

if(!isset($_POST["csrf_token"]) || $_POST["csrf_token"] !== $_SESSION["csrf_token"]){
    die("Invalid CSRF token.");
}

$id = $_POST["id"]; 
$student_id = trim($_POST["student_id"]);
$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$student_name = trim($first_name . ' ' . $last_name);
$section = $_POST["section"];
$grade = (int)($_POST['grade'] ?? 0);

if(!$id || $student_id==='' || $first_name==='' || $last_name==='' || $section==='' || !in_array($grade,[7,8,9,10,11,12],true)){
    $_SESSION['toast']=['message'=>'All student details are required.','type'=>'error'];
    header("Location: edit-student.php?id=$id");
    exit;
}

// 🛡️ GUARD: Duplicate Student ID check
$check_sql = "SELECT ID FROM students WHERE STUDENT_ID = ? AND ID != ?";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("si", $student_id, $id);
$check_stmt->execute();

if($check_stmt->get_result()->num_rows > 0) {
    // If ID is taken, send back to edit page with error toast
    $_SESSION["toast"] = ["message" => "Error: Student ID $student_id is already taken!", "type" => "error"];
    header("Location: edit-student.php?id=$id");
    exit; 
}

// 1. Get current path for cleanup
$stmt_old = $conn->prepare("SELECT IMAGE_PATH FROM students WHERE ID = ?");
$stmt_old->bind_param("i", $id);
$stmt_old->execute();
$old_res = $stmt_old->get_result()->fetch_assoc();
$old_db_path = $old_res['IMAGE_PATH'] ?? '';

$db_path = $old_db_path; 
$pythonDir = "C:/Biometric_Capstone/known_faces/";

// 2. Process Image Update
if(isset($_FILES['student_image']) && $_FILES['student_image']['error'] == 0) {
    $file = $_FILES['student_image'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $clean_name = str_replace(' ', '_', $student_name);
    $new_filename = $student_id . "_" . $clean_name . "." . $ext;
    
    $targetPath = "../uploads/students/" . $new_filename;
    $pythonPath = $pythonDir . $new_filename;

    if (!empty($old_db_path) && file_exists("../" . $old_db_path)) {
        unlink("../" . $old_db_path);
    }
    
    $old_ai_files = glob($pythonDir . $student_id . "_*");
    foreach($old_ai_files as $f) { if(is_file($f)) unlink($f); }

    if(move_uploaded_file($file['tmp_name'], $targetPath)) {
        copy($targetPath, $pythonPath); 
        $db_path = "uploads/students/" . $new_filename;
    }
}

// 3. Database Update
$stmt = $conn->prepare("UPDATE students SET STUDENT_ID = ?, STUDENT_NAME = ?, FIRST_NAME = ?, LAST_NAME = ?, GRADE = ?, SECTION = ?, IMAGE_PATH = ? WHERE ID = ?");
$stmt->bind_param("ssssissi", $student_id, $student_name, $first_name, $last_name, $grade, $section, $db_path, $id);

if($stmt->execute()){
    $_SESSION["toast"] = ["message" => "Student profile updated successfully!", "type" => "success"];
    header("Location: student-list.php");
    exit;
} else {
    echo "Update Failed: " . $conn->error;
}
?>
