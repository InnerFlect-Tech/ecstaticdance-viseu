<?php
declare(strict_types=1);
/**
 * PHP built-in router: serve /uploads/* from EDV_SERVER_ROOT when set (Coolify volume).
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (!str_starts_with($uri, '/uploads/')) {
    return false;
}

$serverRoot = getenv('EDV_SERVER_ROOT');
$base = (is_string($serverRoot) && trim($serverRoot) !== '')
    ? rtrim(trim($serverRoot), '/')
    : __DIR__;

$file = $base . $uri;
if (!is_file($file)) {
    http_response_code(404);
    echo 'Not Found';
    return true;
}

$mime = mime_content_type($file);
if ($mime !== false) {
    header('Content-Type: ' . $mime);
}
header('Content-Length: ' . (string) filesize($file));
readfile($file);

return true;
