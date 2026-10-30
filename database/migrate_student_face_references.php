<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/db_conn.php';
require __DIR__ . '/../includes/student-face-references.php';
ensureStudentFaceReferences($conn);
echo "Student face references are ready. Existing images were preserved.\n";
