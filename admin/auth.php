<?php
    session_start();

    if(!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']){
        die("Invalid CSRF token.");
    }

    require "../config/db_conn.php";
    require "../includes/validation.php";

    $username = sanitize($_POST["username"]);
    $input_password = $_POST["password"];

    $stmt = $conn->prepare("SELECT * FROM admins WHERE ADMIN_USER = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user) {
    $hashed_password = $user["ADMIN_PASS"];

    if (password_verify($input_password, $hashed_password)) {
        $_SESSION['admin-user'] = $user['ADMIN_USER'];
        require "session-creator.php";
        header("Location: home.php");
        exit;
    }
}
    echo "Invalid credentials.";
    exit;
?>
