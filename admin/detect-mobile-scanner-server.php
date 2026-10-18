<?php
session_start();
require "page-guard.php";

header("Content-Type: application/json");
header("Cache-Control: no-store");

function isPrivateIpv4(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }

    $parts = array_map("intval", explode(".", $ip));
    return $parts[0] === 10 ||
        ($parts[0] === 172 && $parts[1] >= 16 && $parts[1] <= 31) ||
        ($parts[0] === 192 && $parts[1] === 168);
}

function requestHostIpv4(): ?string
{
    $host = trim(explode(",", $_SERVER["HTTP_HOST"] ?? "")[0]);
    if (str_starts_with($host, "[")) {
        return null;
    }
    $host = preg_replace("/:\\d+$/", "", $host) ?? "";
    return isPrivateIpv4($host) ? $host : null;
}

function detectedLanIpv4(): ?string
{
    $fromRequest = requestHostIpv4();
    if ($fromRequest !== null) {
        return $fromRequest;
    }

    $hostname = gethostname();
    $addresses = $hostname ? gethostbynamel($hostname) : false;
    foreach ($addresses ?: [] as $address) {
        if (isPrivateIpv4($address)) {
            return $address;
        }
    }

    return null;
}

$ip = detectedLanIpv4();
if ($ip === null) {
    http_response_code(422);
    echo json_encode([
        "ok" => false,
        "message" => "No LAN IPv4 address was detected. Enter the XAMPP computer's address manually.",
    ]);
    exit;
}

$script = str_replace("\\", "/", $_SERVER["SCRIPT_NAME"] ?? "/");
$adminPosition = strrpos($script, "/admin/");
$basePath = $adminPosition === false ? "/" : substr($script, 0, $adminPosition + 1);
$basePath = rtrim($basePath, "/") . "/";

echo json_encode([
    "ok" => true,
    "server_ip" => $ip,
    "server_url" => "http://" . $ip . $basePath,
]);
