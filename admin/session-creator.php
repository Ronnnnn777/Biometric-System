<?php
    session_start();
    $_SESSION["is-authenticated"] = true;
    $_SESSION["user-role"] = "administrator";

?>