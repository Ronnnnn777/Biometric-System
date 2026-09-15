<?php
require 'page-guard.php';
require '../config/db_conn.php';
header('Content-Type: application/json');

$conn->query('CREATE TABLE IF NOT EXISTS unregistered_entries (ENTRY_ID INT AUTO_INCREMENT PRIMARY KEY, DETECTED_AT DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, IMAGE_PATH VARCHAR(255) NOT NULL)');
$afterId=max(0,(int)($_GET['after_id']??0));
if($afterId>0){
    $query=$conn->prepare('SELECT ENTRY_ID, IMAGE_PATH, UNIX_TIMESTAMP(DETECTED_AT)*1000 AS detected_at_ms FROM unregistered_entries WHERE ENTRY_ID>? ORDER BY ENTRY_ID ASC');
    $query->bind_param('i',$afterId);
}else{
    // Recover alerts from a scanner refresh without resurfacing old history.
    $query=$conn->prepare('SELECT ENTRY_ID, IMAGE_PATH, UNIX_TIMESTAMP(DETECTED_AT)*1000 AS detected_at_ms FROM unregistered_entries WHERE DETECTED_AT>=DATE_SUB(NOW(),INTERVAL 2 MINUTE) ORDER BY ENTRY_ID ASC');
}
$query->execute();$result=$query->get_result();$entries=[];$latest=$afterId;
while($entry=$result->fetch_assoc()){$entries[]=['entry_id'=>(int)$entry['ENTRY_ID'],'image_path'=>$entry['IMAGE_PATH'],'detected_at_ms'=>(int)$entry['detected_at_ms']];$latest=max($latest,(int)$entry['ENTRY_ID']);}
echo json_encode(['entries'=>$entries,'latest_id'=>$latest]);
