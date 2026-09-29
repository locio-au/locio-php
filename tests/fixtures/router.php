<?php

// A test server for SecurityTest: a redirect, an oversized body, and an echo.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$flag = sys_get_temp_dir() . '/locio-php-redirect-seen-' . $_SERVER['SERVER_PORT'];

if (str_starts_with($path, '/redirect/')) {
    header('Location: /target');
    http_response_code(302);
    return true;
}
if ($path === '/target') {
    file_put_contents($flag, '1');
    header('Content-Type: application/json');
    echo '{"data":[]}';
    return true;
}
if ($path === '/seen') {
    echo is_file($flag) ? '1' : '0';
    return true;
}
if (str_starts_with($path, '/huge/')) {
    header('Content-Type: application/json');
    echo '{"data":[],"pad":"' . str_repeat('x', 9 * 1024 * 1024) . '"}';
    return true;
}
if (str_starts_with($path, '/echo/')) {
    header('Content-Type: application/json');
    echo json_encode(['data' => [], 'note' => $_SERVER['HTTP_AUTHORIZATION'] ?? '']);
    return true;
}
http_response_code(404);
return true;
