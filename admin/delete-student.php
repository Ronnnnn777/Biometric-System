<?php
session_start();
require "../config/db_conn.php";
require "page-guard.php";

// 1. Security Check
if(!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']){
    die("Invalid CSRF token.");
}

// 2. Capture ID from the Edit Page form
if(!isset($_POST["id"]) || empty($_POST["id"])){
    die("Invalid request: No ID received.");
}

$id = $_POST["id"];

// 3. Cleanup Files (Remove photo from Web and AI folders)
$fetch = $conn->prepare("SELECT IMAGE_PATH FROM students WHERE ID = ?");
$fetch->bind_param("i", $id);
$fetch->execute();
$row = $fetch->get_result()->fetch_assoc();

if ($row) {
    $web_path = "../" . $row['IMAGE_PATH']; 
    $filename = basename($row['IMAGE_PATH']);
    $python_path = "C:/Biometric_Capstone/known_faces/" . $filename;

    if (file_exists($web_path)) unlink($web_path);
    if (file_exists($python_path)) unlink($python_path);
}

// 4. Delete from Database
$stmt = $conn->prepare("DELETE FROM students WHERE ID = ?");
$stmt->bind_param("i", $id);

if($stmt->execute()){
    header("Location: student-list.php?msg=deleted");
    exit;
} else {
    die("Error deleting record: " . $stmt->error);
}
?>