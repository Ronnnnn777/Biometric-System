<?php
/** A long-lived, same-site session for a trusted school mobile scanner. */
function startMobileApiSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $lifetime = 60 * 60 * 12;
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
    $basePath = rtrim(dirname(dirname($script)), '/');
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => ($basePath === '' ? '/' : $basePath . '/'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
