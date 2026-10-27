<?php
require 'page-guard.php';

// Teacher attendance is recorded through the facial-recognition scanner.
// Keep this route closed for bookmarked forms and direct HTTP requests.
http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
exit('Manual attendance recording is disabled. Use the facial-recognition scanner.');
