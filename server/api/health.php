<?php
declare(strict_types=1);
/**
 * Lightweight liveness probe for Coolify/Docker — no DB, no bootstrap.
 *
 * Prefer: wget -q -O/dev/null http://127.0.0.1:8080/api/health.php (php built-in direct)
 * Or through Nginx: http://localhost/api/health.php
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
http_response_code(200);

$payload = ['ok' => true, 'service' => 'edv-php'];
$infoPath = __DIR__ . '/build-info.json';
if (is_readable($infoPath)) {
    $info = json_decode((string) file_get_contents($infoPath), true);
    if (is_array($info)) {
        $payload = array_merge($payload, $info);
    }
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES);
