<?php
/** Adds a checkout field while keeping existing attendance records unchanged. */
function ensureAttendanceTimeOutColumn(mysqli $conn): void
{
    $columns=[];$result=$conn->query('SHOW COLUMNS FROM attendance');
    while($result&&($column=$result->fetch_assoc()))$columns[strtoupper($column['Field'])]=true;
    if(!isset($columns['TIME_OUT']))$conn->query('ALTER TABLE attendance ADD COLUMN TIME_OUT DATETIME NULL DEFAULT NULL AFTER TIME_IN');
}
