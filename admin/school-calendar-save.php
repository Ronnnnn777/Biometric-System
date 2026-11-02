<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/school-calendar-schema.php";
ensureSchoolCalendarSchema($conn);

if (!hash_equals($_SESSION["csrf_token"] ?? "", $_POST["csrf_token"] ?? "")) {
    $_SESSION["toast"] = ["message" => "The request expired. Please try again.", "type" => "error"];
    header("Location: school-calendar.php");
    exit();
}

$action = (string) ($_POST["action"] ?? "save");
$date = trim((string) ($_POST["calendar_date"] ?? ""));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $_SESSION["toast"] = ["message" => "Choose a valid date.", "type" => "error"];
    header("Location: school-calendar.php");
    exit();
}

if ($action === "delete") {
    deleteSchoolCalendarEntry($conn, $date);
    $_SESSION["toast"] = ["message" => "Calendar entry removed. The date now follows the default weekday rule.", "type" => "success"];
} else {
    $type = (string) ($_POST["day_type"] ?? "school");
    $label = trim((string) ($_POST["label"] ?? ""));
    if (setSchoolCalendarEntry($conn, $date, $type, $label)) {
        $_SESSION["toast"] = ["message" => "Calendar updated for " . $date . ".", "type" => "success"];
    } else {
        $_SESSION["toast"] = ["message" => "Choose a valid day type.", "type" => "error"];
    }
}

header("Location: school-calendar.php");
exit();
