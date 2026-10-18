<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This maintenance script can run only from the server command line.');
}

require __DIR__ . '/../config/db_conn.php';
require __DIR__ . '/../includes/attendance-schema.php';

ensureAttendanceTimeOutColumn($conn);
echo 'Automatic absence marking is disabled. Teachers mark absences manually.' . PHP_EOL;
