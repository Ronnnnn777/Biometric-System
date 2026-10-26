<?php
// Archived students share the student-list filter card through the archived
// view, so this entry point stays a lightweight redirect.
require 'page-guard.php';
header('Location: student-list.php?view=archived', true, 302);
exit;
