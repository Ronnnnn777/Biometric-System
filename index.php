<?php
// The public root is the staff sign-in entry point.  Requiring the controller
// keeps the browser address at the site root instead of exposing /admin/login.php.
require __DIR__ . '/admin/login.php';
