<?php
require "page-guard.php";
require_once __DIR__ . '/../includes/csrf-token.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(400); exit('Invalid request.');
}
require "../config/db_conn.php";
require "../includes/teacher-profile-schema.php";
require "../includes/teacher-assignment-schema.php";
require "../includes/student-placement-options.php";
require "../includes/section-catalog-schema.php";
ensureTeacherProfileColumns($conn);
ensureSectionCatalogColumns($conn);
seedDefaultTeacherSections($conn);
ensureTeacherAssignmentSchema($conn);
$id = (int) ($_POST["id"] ?? 0);
$passwordOnly = ($_POST["password_only"] ?? "") === "1";
$current = $_POST["current_password"] ?? "";
$pass = $_POST["teacher_pass"] ?? "";
$confirm = $_POST["teacher_confirm"] ?? "";
$find = $conn->prepare("SELECT TEACHER_PASS,TEACHER_STATUS FROM teachers WHERE TEACHER_ID=?");
$find->bind_param("i", $id);
$find->execute();
$existing = $find->get_result()->fetch_assoc();
if (!$existing) {
    exit("Teacher not found.");
}
if ($passwordOnly) {
    $adminUser = $_SESSION["admin-user"] ?? "";
    $admin = $conn->prepare(
        "SELECT ADMIN_PASS FROM admins WHERE ADMIN_USER=? LIMIT 1",
    );
    $admin->bind_param("s", $adminUser);
    $admin->execute();
    $account = $admin->get_result()->fetch_assoc();
    if (
        !$account ||
        !password_verify($current, $account["ADMIN_PASS"]) ||
        strlen($pass) < 8 ||
        $pass !== $confirm
    ) {
        exit(
            "Administrator password is incorrect or the new passwords do not match."
        );
    }
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $stmt = $conn->prepare(
        "UPDATE teachers SET TEACHER_PASS=?, MUST_CHANGE_PASSWORD=1 WHERE TEACHER_ID=?",
    );
    $stmt->bind_param("si", $hash, $id);
    $stmt->execute();
    $_SESSION["toast"] = [
        "message" => "Teacher password updated successfully.",
        "type" => "success",
    ];
    header("Location: teacher-list.php");
    exit();
}
$firstName = trim($_POST["first_name"] ?? "");
$lastName = trim($_POST["last_name"] ?? "");
$name = trim($firstName . " " . $lastName);
$assignments = postedTeacherAssignments($conn);
if (isset($_POST['assignment_grade']) && !$assignments) {
    http_response_code(400); exit('Choose valid subject teaching assignments or remove the empty assignment rows.');
}
if ($firstName === "" || $lastName === "") {
    exit("Provide a valid teacher name.");
}
$conn->begin_transaction();
try {
    $stmt = $conn->prepare(
        "UPDATE teachers SET TEACHER_NAME=?,FIRST_NAME=?,LAST_NAME=? WHERE TEACHER_ID=?",
    );
    $stmt->bind_param(
        "sssi",
        $name,
        $firstName,
        $lastName,
        $id,
    );
    $stmt->execute();
    replaceTeacherAssignments($conn, $id, $assignments);
    if ($existing['TEACHER_STATUS']==='Active') {
        $advisory = teacherAdvisoryAssignments($conn,$id)[0] ?? null;
        $sectionId = isset($_POST['advisory_section_id']) ? (int)$_POST['advisory_section_id'] : (int)($advisory['SECTION_ID'] ?? 0);
        setTeacherAdvisory($conn, $id, $sectionId);
        requireTeacherAdvisory($conn,$id);
    }
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    $_SESSION['toast'] = ['message' => $error instanceof mysqli_sql_exception ? 'No changes saved. The advisory section is already assigned, or the assignment could not be saved.' : 'No changes saved. '.$error->getMessage(), 'type' => 'error'];
    header('Location: teacher-list.php'); exit;
}
$_SESSION["toast"] = [
    "message" => "Teacher profile updated successfully.",
    "type" => "success",
];
header("Location: teacher-list.php");
exit();
