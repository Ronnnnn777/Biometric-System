<?php
date_default_timezone_set('Asia/Manila');

// Reuse an existing handle so a guard or page that includes this file more than
// once (admin/page-guard.php now loads it) does not open duplicate connections.
if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
    $dbhost="localhost";
    $dbuser="root";
    $dbpass="";
    $dbname="biometric";

    // MariaDB in this XAMPP installation listens on the default port 3306.
    // Passing the port explicitly keeps the application connection predictable.
    $conn = new mysqli($dbhost, $dbuser, $dbpass, $dbname, 3306);

    if ($conn->connect_error){
        die("Database Connection Failed.");
    }

    // Keep PHP calculations and database timestamps on the school's local time.
    $conn->query("SET time_zone = '+08:00'");
}

?>
