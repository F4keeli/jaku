<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (str_contains($path, "\0") || str_contains($path, '\\') || preg_match('~(?:^|/)\.\.?(/|$)~', $path)) {
    http_response_code(404);
    exit;
}

if (str_starts_with($path, '/api/') || str_starts_with($path, '/staff/api/') || $path === '/api.php') {
    if ($path !== '/api.php') {
        $_GET['route'] = ltrim($path, '/');
    }
    require $root . '/api.php';
    return true;
}

if ($path === '/staff' || $path === '/staff/') {
    $path = '/staff.html';
}
if ($path === '/') {
    $path = '/index.html';
}

$page = preg_match('~^/(?:index|games|team|contact|store|application|account|staff|section)\.html$~', $path);
$asset = preg_match('~^/(?:assets|website scripts)/[^?]+\.(?:css|js|png|jpg|jpeg|jfif|webp|svg|ico|woff|woff2)$~i', $path);
$file = realpath($root . $path);
if ((!$page && !$asset) || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

if ($page) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($file);
    return true;
}

return false;
