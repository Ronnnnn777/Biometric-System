<?php
/**
 * Read-only LAN discovery endpoint for the Android scanner.
 * It deliberately exposes no account, scanner, or student information.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

$host = trim(explode(',', $_SERVER['HTTP_HOST'] ?? '')[0]);
if ($host === '' || str_starts_with($host, '[')) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

$host = preg_replace('/:\d+$/', '', $host) ?? '';
$script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/api/mobile-discovery.php');
$apiPosition = strrpos($script, '/api/');
$basePath = $apiPosition === false ? '/' : substr($script, 0, $apiPosition + 1);
$basePath = rtrim($basePath, '/') . '/';

echo json_encode([
    'ok' => true,
    'application' => 'mnhs-attendance-scanner',
    'server_url' => 'http://' . $host . $basePath,
]);
