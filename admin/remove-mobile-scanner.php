<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/csrf-token.php";
require "../includes/mobile-scanner-settings.php";

ensureMobileScannerSettingsSchema($conn);

if (!hash_equals($_SESSION["csrf_token"] ?? "", $_POST["csrf_token"] ?? "")) {
    $_SESSION["toast"] = [
        "message" => "The remove request expired. Please try again.",
        "type" => "error",
    ];
    header("Location: home.php");
    exit;
}

$scannerId = trim((string) ($_POST["scanner_id"] ?? ""));
if ($scannerId === "") {
    $_SESSION["toast"] = ["message" => "Mobile scanner was not found.", "type" => "error"];
    header("Location: home.php");
    exit;
}

$lookup = $conn->prepare("SELECT SCANNER_NAME FROM authorized_mobile_scanners WHERE SCANNER_ID=? LIMIT 1");
$lookup->bind_param("s", $scannerId);
$lookup->execute();
$scanner = $lookup->get_result()->fetch_assoc();

if (!$scanner) {
    $_SESSION["toast"] = ["message" => "Mobile scanner was not found.", "type" => "error"];
    header("Location: home.php");
    exit;
}

try {
    $conn->begin_transaction();
    $block = $conn->prepare("INSERT INTO removed_mobile_scanners (SCANNER_ID, SCANNER_NAME) VALUES (?, ?) ON DUPLICATE KEY UPDATE SCANNER_NAME=VALUES(SCANNER_NAME), REMOVED_AT=CURRENT_TIMESTAMP");
    $block->bind_param("ss", $scannerId, $scanner["SCANNER_NAME"]);
    $block->execute();

    $remove = $conn->prepare("DELETE FROM authorized_mobile_scanners WHERE SCANNER_ID=?");
    $remove->bind_param("s", $scannerId);
    $remove->execute();
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    $_SESSION["toast"] = ["message" => "Could not remove the mobile scanner. Please try again.", "type" => "error"];
    header("Location: home.php");
    exit;
}

$_SESSION["toast"] = [
    "message" => "Mobile scanner removed and blocked. It cannot register itself again.",
    "type" => "success",
];
header("Location: home.php");
exit;
