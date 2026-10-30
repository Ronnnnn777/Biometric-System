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

$prepared = [];
$createdFiles = [];
try {
    $conn->begin_transaction();
    $current = $conn->prepare('SELECT IMAGE_PATH FROM students WHERE ID=? FOR UPDATE');
    $current->bind_param('i', $id);
    $current->execute();
    $existing = $current->get_result()->fetch_assoc();
    if (!$existing) throw new RuntimeException('Student profile was not found.');
    $db_path = $existing['IMAGE_PATH'];
    $prepared = prepareStudentFaceUploads(false, recognitionReferencePath($db_path), studentFaceReferences($conn, (int)$id));
// 3. Database Update
if ($enrollmentStatus === 'Archived') {
    $stmt = $conn->prepare("UPDATE students SET STUDENT_ID = ?, STUDENT_NAME = ?, FIRST_NAME = ?, MIDDLE_NAME = ?, LAST_NAME = ?, GRADE = ?, AGE = ?, STRAND = ?, ADDRESS = ?, SECTION = ?, ENROLLMENT_STATUS = ?, IMAGE_PATH = ?, ARCHIVE_REASON='Manual archive', ARCHIVED_AT=NOW() WHERE ID = ?");
} else {
    $stmt = $conn->prepare("UPDATE students SET STUDENT_ID = ?, STUDENT_NAME = ?, FIRST_NAME = ?, MIDDLE_NAME = ?, LAST_NAME = ?, GRADE = ?, AGE = ?, STRAND = ?, ADDRESS = ?, SECTION = ?, ENROLLMENT_STATUS = ?, IMAGE_PATH = ? WHERE ID = ?");
}
$stmt->bind_param("sssssiisssssi", $student_id, $student_name, $first_name, $middle_name, $last_name, $grade, $age, $strand, $address, $section, $enrollmentStatus, $db_path, $id);

    $stmt->execute();
    saveStudentFaceUploads($conn, (int)$id, $prepared, $createdFiles);
    $conn->commit();
    $_SESSION['toast'] = ['message'=>'Student profile updated successfully!', 'type'=>'success'];
    $redirect = 'student-list.php';
} catch (Throwable $error) {
    $conn->rollback();
    foreach ($createdFiles as $file) @unlink($file);
    error_log('Student update: ' . $error->getMessage());
    $_SESSION['toast'] = ['message'=>$error instanceof RuntimeException && !($error instanceof mysqli_sql_exception) ? $error->getMessage() : 'The student could not be saved. Please try again.', 'type'=>'error'];
    $redirect = 'edit-student.php?id=' . (int)$id;
}
cleanupPreparedStudentFaces($prepared);
if ($prepared && ($_SESSION['toast']['type'] ?? '') === 'success') {
    try { $warmed = warmStudentFaceCache($conn); } catch (Throwable $error) { $warmed = false; }
    if (!$warmed) $_SESSION['toast']['message'] .= ' Photos saved; the recognition cache will refresh on the next scan.';
}
header('Location: ' . $redirect);
exit;
