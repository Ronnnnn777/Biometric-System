<?php
session_start();
require "../config/db_conn.php";
require "../includes/validation.php";
require "../includes/default-admin.php";
require "../includes/teacher-profile-schema.php";
ensureTeacherProfileColumns($conn);
ensureDefaultAdministrator($conn);
if (!hash_equals($_SESSION["csrf_token"] ?? "", $_POST["csrf_token"] ?? "")) {
    http_response_code(400);
    exit("Invalid request.");
}
$email = sanitize($_POST["username"] ?? "");
$inputPassword = $_POST["password"] ?? "";
$stmt = $conn->prepare(
    "SELECT ADMIN_ID,ADMIN_USER,ADMIN_PASS FROM admins WHERE ADMIN_USER=? LIMIT 1",
);
$stmt->bind_param("s", $email);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
if ($admin && password_verify($inputPassword, $admin["ADMIN_PASS"])) {
    $isDefaultAccount =
        strcasecmp($admin["ADMIN_USER"], DEFAULT_ADMIN_EMAIL) === 0 &&
        $inputPassword === DEFAULT_ADMIN_PASSWORD;
    if ($isDefaultAccount) {
        $newPassword = $_POST["new_password"] ?? "";
        $confirmPassword = $_POST["confirm_password"] ?? "";
        if (
            strlen($newPassword) < 8 ||
            $newPassword !== $confirmPassword ||
            $newPassword === DEFAULT_ADMIN_PASSWORD
        ) {
            $_SESSION["login-error"] =
                "For first-time setup, choose matching new passwords with at least 8 characters that are not the default password.";
            header("Location: login.php");
            exit();
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $update = $conn->prepare(
            "UPDATE admins SET ADMIN_PASS=? WHERE ADMIN_USER=?",
        );
        $update->bind_param("ss", $hash, $admin["ADMIN_USER"]);
        $update->execute();
    }
    session_regenerate_id(true);
    $_SESSION["is-authenticated"] = true;
    $_SESSION["user-role"] = "administrator";
    $_SESSION["admin-user"] = $admin["ADMIN_USER"];
    header("Location: home.php");
    exit();
}
$stmt = $conn->prepare(
    "SELECT TEACHER_ID,TEACHER_USER,TEACHER_PASS,TEACHER_NAME,SECTION FROM teachers WHERE TEACHER_USER=? AND COALESCE(TEACHER_STATUS,'Active')='Active' LIMIT 1",
);
$stmt->bind_param("s", $email);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
if ($teacher && password_verify($inputPassword, $teacher["TEACHER_PASS"])) {
    session_regenerate_id(true);
    $_SESSION["is-authenticated"] = true;
    $_SESSION["user-role"] = "teacher";
    $_SESSION["teacher-id"] = $teacher["TEACHER_ID"];
    $_SESSION["teacher-name"] = $teacher["TEACHER_NAME"];
    $_SESSION["teacher-section"] = $teacher["SECTION"];
    header("Location: ../teacher/students.php");
    exit();
}
$_SESSION["login-error"] = "Invalid email address or password.";
header("Location: login.php");
