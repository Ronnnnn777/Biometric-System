<?php
    if(session_status() === PHP_SESSION_NONE){
    session_start();
    }

    if(!isset($_SESSION["is-authenticated"]) || !$_SESSION["is-authenticated"]) {
        echo "Unauthorized Access";
        exit;
    }

    if(!isset($_SESSION["user-role"]) || $_SESSION["user-role"] !== "administrator") {
        echo "Unauthorized Acess";
        exit;
    }
?>