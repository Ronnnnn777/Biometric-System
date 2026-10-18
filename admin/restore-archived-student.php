<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/csrf-token.php";
require "../includes/student-profile-schema.php";

ensureStudentProfileColumns($conn);

if (!hash_equals($_SESSION["csrf_token"] ?? "", $_POST["csrf_token"] ?? "")) {
    http_response_code(400);
    exit("Invalid request.");
}

$id = (int) ($_POST["id"] ?? 0);
$restore = $conn->prepare("UPDATE students SET ENROLLMENT_STATUS='Enrolled', ARCHIVE_REASON='', ARCHIVED_AT=NULL WHERE ID=? AND ENROLLMENT_STATUS='Archived'");
$restore->bind_param("i", $id);
$restore->execute();

$_SESSION["toast"] = [
    "message" => $restore->affected_rows
        ? "Student restored to Enrolled status."
        : "Archived student was not found.",
    "type" => $restore->affected_rows ? "success" : "error",
];
header("Location: archived-students.php");
exit;
