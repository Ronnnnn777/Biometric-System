<?php
session_start();
require "../config/db_conn.php";
require "page-guard.php";
require '../includes/student-profile-schema.php';
require '../includes/student-placement-options.php';
ensureStudentProfileColumns($conn);

if(!isset($_POST["csrf_token"]) || $_POST["csrf_token"] !== $_SESSION["csrf_token"]){
    die("Invalid CSRF token.");
}

$id = $_POST["id"]; 
$student_id = trim($_POST["student_id"]);
$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$student_name = trim($first_name . ' ' . $last_name);
$section = trim($_POST["section"] ?? '');
$grade = (int)($_POST['grade'] ?? 0);
$age = (int)($_POST['age'] ?? 0);
$strand = trim($_POST['strand'] ?? '');
$address = trim($_POST['address'] ?? '');
$enrollmentStatus = trim($_POST['enrollment_status'] ?? 'Enrolled');

if(!$id || $student_id==='' || $first_name==='' || $last_name==='' || !isValidStudentAge($age) || $address==='' || strlen($address)>255 || !isValidStudentPlacement($grade, $strand, $section) || !isValidStudentEnrollmentStatus($enrollmentStatus)){
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

// Keep additional camera-angle references alongside the main enrollment image.
// recognize_face.py groups all files that start with the same student ID.
if(isset($_FILES['face_reference_image']) && $_FILES['face_reference_image']['error'] === UPLOAD_ERR_OK) {
    $reference=$_FILES['face_reference_image'];
    $imageInfo=@getimagesize($reference['tmp_name']);
    $validTypes=[IMAGETYPE_JPEG=>'jpg',IMAGETYPE_PNG=>'png'];
    if(!$imageInfo || !isset($validTypes[$imageInfo[2]]) || $reference['size']>5*1024*1024){
        $_SESSION['toast']=['message'=>'The camera-angle reference must be a JPEG or PNG under 5 MB.','type'=>'error'];
        header("Location: edit-student.php?id=$id");
        exit;
    }
    $referenceName=$student_id.'_emeet_'.date('YmdHis').'_'.bin2hex(random_bytes(3)).'.'.$validTypes[$imageInfo[2]];
    if(!move_uploaded_file($reference['tmp_name'],$pythonDir.$referenceName)){
        $_SESSION['toast']=['message'=>'Could not save the camera-angle reference.','type'=>'error'];
        header("Location: edit-student.php?id=$id");
        exit;
    }
}

// 3. Database Update
$stmt = $conn->prepare("UPDATE students SET STUDENT_ID = ?, STUDENT_NAME = ?, FIRST_NAME = ?, LAST_NAME = ?, GRADE = ?, AGE = ?, STRAND = ?, ADDRESS = ?, SECTION = ?, ENROLLMENT_STATUS = ?, IMAGE_PATH = ? WHERE ID = ?");
$stmt->bind_param("ssssiisssssi", $student_id, $student_name, $first_name, $last_name, $grade, $age, $strand, $address, $section, $enrollmentStatus, $db_path, $id);

if($stmt->execute()){
    $_SESSION["toast"] = ["message" => "Student profile updated successfully!", "type" => "success"];
    header("Location: student-list.php");
    exit;
} else {
    echo "Update Failed: " . $conn->error;
}
?>
