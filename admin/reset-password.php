<?php
session_start();
require "../config/db_conn.php";
if (!hash_equals($_SESSION["csrf_token"] ?? "", $_POST["csrf_token"] ?? "")) {
    exit("Invalid request.");
}
$user = $_SESSION["password-reset-user"] ?? "";
$answer = mb_strtolower(trim($_POST["security_answer"] ?? ""));
$new = $_POST["new_password"] ?? "";
$confirm = $_POST["confirm_password"] ?? "";
$stmt = $conn->prepare(
    "SELECT SECURITY_ANSWER FROM admin_security WHERE ADMIN_USER=?",
);
$stmt->bind_param("s", $user);
$stmt->execute();
$security = $stmt->get_result()->fetch_assoc();
if (
    !$user ||
    !$security ||
    !password_verify($answer, $security["SECURITY_ANSWER"]) ||
    strlen($new) < 8 ||
    $new !== $confirm
) {
    $_SESSION["reset-error"] = "Answer is incorrect or passwords do not match.";
    header("Location: forgot-password.php");
    exit();
}
$hash = password_hash($new, PASSWORD_DEFAULT);
$up = $conn->prepare("UPDATE admins SET ADMIN_PASS=? WHERE ADMIN_USER=?");
$up->bind_param("ss", $hash, $user);
$up->execute();
unset($_SESSION["password-reset-user"]);
$_SESSION["reset-success"] =
    "Password reset successfully. Sign in with your new password.";
header("Location: login.php");
