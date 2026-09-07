<?php
if(session_status()===PHP_SESSION_NONE)session_start();
if(empty($_SESSION['is-authenticated'])||($_SESSION['user-role']??'')!=='teacher'){header('Location: login.php');exit;}