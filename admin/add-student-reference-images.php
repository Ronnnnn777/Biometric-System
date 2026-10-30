<?php
session_start();
require '../config/db_conn.php';
require 'page-guard.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(400); exit('Invalid request.');
}
$id = (int)($_POST['id'] ?? 0);
// Retire the unlabeled bulk uploader: all modifications use the three pose slots.
$_SESSION['toast'] = ['message'=>'Use the labeled Front View, Slight Left View, and Slight Right View slots, then save changes.', 'type'=>'error'];
header('Location: edit-student.php?id=' . $id);
exit;
