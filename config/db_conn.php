<?php
$dbhost="localhost";
$dbuser="root";
$dbpass="";
$dbname="biometric";

// MariaDB in this XAMPP installation listens on 5173 (see mysql/bin/my.ini).
// Passing the port explicitly prevents PHP from silently falling back to 3306.
$conn = new mysqli($dbhost, $dbuser, $dbpass, $dbname, 5173);

if ($conn->connect_error){
    die("Database Connection Failed.");
}

?>
