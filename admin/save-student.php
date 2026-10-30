<?php
session_start();
require "../config/db_conn.php";
require "page-guard.php";
require '../includes/student-profile-schema.php';
require '../includes/student-placement-options.php';
require '../includes/section-catalog-schema.php';
require '../includes/student-face-references.php';
ensureStudentFaceReferences($conn);
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

// Photos and student details commit together; old photos are never removed.
$prepared = [];
$createdFiles = [];
try {
    $prepared = prepareStudentFaceUploads(true);
    $conn->begin_transaction();
    $db_path = '';
    $sql = "INSERT INTO students (STUDENT_ID, STUDENT_NAME, FIRST_NAME, MIDDLE_NAME, LAST_NAME, GRADE, AGE, STRAND, ADDRESS, SECTION, IMAGE_PATH) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $insert = $conn->prepare($sql);
    $insert->bind_param('sssssiissss', $student_id, $student_name, $first_name, $middle_name, $last_name, $grade, $age, $strand, $address, $section, $db_path);
    $insert->execute();
    saveStudentFaceUploads($conn, (int)$conn->insert_id, $prepared, $createdFiles);
    $conn->commit();
    $_SESSION['toast'] = ['message'=>'Registered successfully', 'type'=>'success'];
    $redirect = 'student-list.php';
} catch (Throwable $error) {
    $conn->rollback();
    foreach ($createdFiles as $file) @unlink($file);
    error_log('Student enrollment: ' . $error->getMessage());
    $_SESSION['toast'] = ['message'=>$error instanceof RuntimeException && !($error instanceof mysqli_sql_exception) ? $error->getMessage() : 'The student could not be saved. Please try again.', 'type'=>'error'];
    $redirect = 'add-student.php';
}
cleanupPreparedStudentFaces($prepared);
if ($prepared && ($_SESSION['toast']['type'] ?? '') === 'success') {
    try { $warmed = warmStudentFaceCache($conn); } catch (Throwable $error) { $warmed = false; }
    if (!$warmed) $_SESSION['toast']['message'] .= ' Photos saved; the recognition cache will refresh on the next scan.';
}
header('Location: ' . $redirect);
exit;
