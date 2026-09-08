<?php
const DEFAULT_ADMIN_EMAIL = 'admin@biometrics.com';
const DEFAULT_ADMIN_PASSWORD = 'password';

function ensureDefaultAdministrator(mysqli $conn): void {
    $find=$conn->prepare('SELECT ADMIN_ID FROM admins WHERE ADMIN_USER=? LIMIT 1');
    $email=DEFAULT_ADMIN_EMAIL;
    $find->bind_param('s',$email);
    $find->execute();
    if($find->get_result()->fetch_assoc()) return;

    $hash=password_hash(DEFAULT_ADMIN_PASSWORD,PASSWORD_DEFAULT);
    $create=$conn->prepare('INSERT INTO admins (ADMIN_USER,ADMIN_PASS) VALUES (?,?)');
    $create->bind_param('ss',$email,$hash);
    $create->execute();
}
