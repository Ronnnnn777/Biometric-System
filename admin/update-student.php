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

if(!isset($_POST["csrf_token"]) || $_POST["csrf_token"] !== $_SESSION["csrf_token"]){
    die("Invalid CSRF token.");
}

$id = $_POST["id"]; 
$student_id = trim($_POST["student_id"]);
$first_name = trim($_POST['first_name'] ?? '');
$middle_name = trim($_POST['middle_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$student_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
$section = trim($_POST["section"] ?? '');
$grade = (int)($_POST['grade'] ?? 0);
$age = (int)($_POST['age'] ?? 0);
$strand = trim($_POST['strand'] ?? '');
$address = trim($_POST['address'] ?? '');
$enrollmentStatus = trim($_POST['enrollment_status'] ?? 'Enrolled');

$validPlacement = isValidStudentPlacement($grade, $strand, $section) || isApprovedStudentSection($conn, $grade, $strand, $section);
if(!$id || $student_id==='' || $first_name==='' || $last_name==='' || !isValidStudentAge($age) || $address==='' || strlen($address)>255 || !$validPlacement || !isValidStudentEnrollmentStatus($enrollmentStatus)){
    $_SESSION['toast']=['message'=>'All student details are required.','type'=>'error'];
    header("Location: edit-student.php?id=$id");
    exit;
}

// 🛡️ GUARD: Duplicate LRN check
$check_sql = "SELECT ID FROM students WHERE STUDENT_ID = ? AND ID != ?";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("si", $student_id, $id);
$check_stmt->execute();

if($check_stmt->get_result()->num_rows > 0) {
    // If ID is taken, send back to edit page with error toast
    $_SESSION["toast"] = ["message" => "Error: LRN $student_id is already registered!", "type" => "error"];
    header("Location: edit-student.php?id=$id");
    exit; 
}

if ($enrollmentStatus === 'Enrolled' && !studentSectionHasCapacity($conn, $grade, $strand, $section, (int) $id)) {
    $_SESSION['toast'] = ['message' => studentSectionCapacityMessage($grade, $section), 'type' => 'error'];
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
$biometricFilesChanged=false;

function refreshStudentRecognitionService(): void {
    $context=stream_context_create(['http'=>['method'=>'POST','timeout'=>2,'ignore_errors'=>true]]);
    @file_get_contents('http://127.0.0.1:8772/refresh',false,$context);
}

// 2. Process Image Update
if(isset($_FILES['student_image']) && $_FILES['student_image']['error'] == 0) {
    $file = $_FILES['student_image'];
    $validationError=validateBiometricImage($file['tmp_name']);
    if($validationError) {
        $_SESSION['toast']=['message'=>$validationError,'type'=>'error'];
        header("Location: edit-student.php?id=$id");
        exit;
    }
    $ext = biometricImageExtension($file['tmp_name']);
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
        if(!is_dir($pythonDir) || !copy($targetPath, $pythonPath)) {
            $_SESSION['toast']=['message'=>'The replacement photo could not be added to face recognition.','type'=>'error'];
            header("Location: edit-student.php?id=$id");
            exit;
        }
        $db_path = "uploads/students/" . $new_filename;
        $biometricFilesChanged=true;
    }
}

// 3. Database Update
if ($enrollmentStatus === 'Archived') {
    $stmt = $conn->prepare("UPDATE students SET STUDENT_ID = ?, STUDENT_NAME = ?, FIRST_NAME = ?, MIDDLE_NAME = ?, LAST_NAME = ?, GRADE = ?, AGE = ?, STRAND = ?, ADDRESS = ?, SECTION = ?, ENROLLMENT_STATUS = ?, IMAGE_PATH = ?, ARCHIVE_REASON='Manual archive', ARCHIVED_AT=NOW() WHERE ID = ?");
} else {
    $stmt = $conn->prepare("UPDATE students SET STUDENT_ID = ?, STUDENT_NAME = ?, FIRST_NAME = ?, MIDDLE_NAME = ?, LAST_NAME = ?, GRADE = ?, AGE = ?, STRAND = ?, ADDRESS = ?, SECTION = ?, ENROLLMENT_STATUS = ?, IMAGE_PATH = ? WHERE ID = ?");
}
$stmt->bind_param("sssssiisssssi", $student_id, $student_name, $first_name, $middle_name, $last_name, $grade, $age, $strand, $address, $section, $enrollmentStatus, $db_path, $id);

if($stmt->execute()){
    if($biometricFilesChanged) refreshStudentRecognitionService();
    $_SESSION["toast"] = ["message" => "Student profile updated successfully!", "type" => "success"];
    header("Location: student-list.php");
    exit;
} else {
    echo "Update Failed: " . $conn->error;
}
?>
