<?php

function validateBiometricImage(string $path): ?string {
    if(!is_file($path) || filesize($path)===0 || filesize($path)>5*1024*1024) return 'Use a JPEG or PNG image smaller than 5 MB.';
    $imageInfo=@getimagesize($path);
    if(!$imageInfo || !in_array($imageInfo[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG],true)) return 'Only JPEG and PNG images are accepted for biometric registration.';
    $pythonPath='C:\\Users\\jaxim\\AppData\\Local\\Python\\pythoncore-3.14-64\\python.exe';
    $pythonCommand=is_file($pythonPath) ? escapeshellarg($pythonPath) : 'py -3';
    $script=escapeshellarg(__DIR__.'/../admin/validate_biometric_image.py');
    $raw=shell_exec($pythonCommand.' '.$script.' '.escapeshellarg($path).' 2>NUL');
    $result=json_decode($raw ?? '',true);
    return is_array($result) && !empty($result['valid']) ? null : ($result['message'] ?? 'Use one clear, upright, uncropped face photo.');
}

function biometricImageExtension(string $path): ?string {
    $imageInfo=@getimagesize($path);
    return match($imageInfo[2] ?? 0) { IMAGETYPE_JPEG=>'jpg', IMAGETYPE_PNG=>'png', default=>null };
}
