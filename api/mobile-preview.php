<?php
http_response_code(410);
header('Content-Type: application/json');
echo json_encode(['ok' => false, 'message' => 'Mobile live preview has been retired.']);
