<?php
session_start();
require "../config/db_conn.php";
require "page-guard.php";
require '../includes/student-profile-schema.php';
ensureStudentProfileColumns($conn);

// 1. Security Check
if(!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']){
    die("Invalid CSRF token.");
}

// This legacy endpoint intentionally archives rather than destroys confidential records.
if(!isset($_POST["id"]) || empty($_POST["id"])){
    die("Invalid request: No ID received.");
}

$id = (int)$_POST["id"];

// Keep the student profile, image, and attendance history for authorized records requests.
$stmt = $conn->prepare("UPDATE students SET ENROLLMENT_STATUS='Archived' WHERE ID = ?");
$stmt->bind_param("i", $id);

if($stmt->execute()){
    $_SESSION['toast']=['message'=>'Student archived. The profile and attendance history have been retained.','type'=>'success'];
    header("Location: student-list.php");
    exit;
} else {
    die("Error deleting record: " . $stmt->error);
}
?>
