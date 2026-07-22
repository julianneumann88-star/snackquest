<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$rel = preg_replace('#^/snackquest/#', '', $path);
$file = dirname(__DIR__, 2) . '/public/' . $rel;

if ($rel !== $path && is_file($file)) {
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'webmanifest' => 'application/manifest+json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'woff2' => 'font/woff2',
    ];
    header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($file));
    readfile($file);
    return true;
}

require dirname(__DIR__, 2) . '/public/index.php';
