<?php
require 'page-guard.php';
require '../config/db_conn.php';
header('Content-Type: application/json');

if(!hash_equals($_SESSION['csrf_token']??'',$_POST['csrf_token']??'')){http_response_code(403);echo json_encode(['removed'=>false]);exit;}
$entryId=(int)($_POST['entry_id']??0);
if($entryId<1){http_response_code(400);echo json_encode(['removed'=>false]);exit;}
$query=$conn->prepare('SELECT IMAGE_PATH FROM unregistered_entries WHERE ENTRY_ID=? LIMIT 1');$query->bind_param('i',$entryId);$query->execute();$entry=$query->get_result()->fetch_assoc();
if(!$entry){echo json_encode(['removed'=>true]);exit;}
$path=(string)$entry['IMAGE_PATH'];
if(str_starts_with($path,'uploads/unregistered/')){@unlink('../'.$path);}
$delete=$conn->prepare('DELETE FROM unregistered_entries WHERE ENTRY_ID=?');$delete->bind_param('i',$entryId);$delete->execute();
echo json_encode(['removed'=>$delete->affected_rows>0]);
