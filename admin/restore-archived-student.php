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
$student = $conn->prepare("SELECT GRADE,STRAND,SECTION FROM students WHERE ID=? AND ENROLLMENT_STATUS='Archived' LIMIT 1");
$student->bind_param('i', $id);
$student->execute();
$placement = $student->get_result()->fetch_assoc();
if (!$placement) {
    $_SESSION['toast'] = ['message' => 'Archived student was not found.', 'type' => 'error'];
    header('Location: archived-students.php');
    exit;
}
if (!studentSectionHasCapacity($conn, (int) $placement['GRADE'], (string) $placement['STRAND'], (string) $placement['SECTION'])) {
    $_SESSION['toast'] = ['message' => studentSectionCapacityMessage((int) $placement['GRADE'], (string) $placement['SECTION']), 'type' => 'error'];
    header('Location: archived-students.php');
    exit;
}
$restore = $conn->prepare("UPDATE students SET ENROLLMENT_STATUS='Enrolled', ARCHIVE_REASON='', ARCHIVED_AT=NULL WHERE ID=? AND ENROLLMENT_STATUS='Archived'");
$restore->bind_param("i", $id);
$restore->execute();

$_SESSION["toast"] = [
    "message" => $restore->affected_rows
        ? "Student restored to Enrolled status."
        : "Archived student was not found.",
    "type" => $restore->affected_rows ? "success" : "error",
];
header("Location: student-list.php?view=archived");
exit;
