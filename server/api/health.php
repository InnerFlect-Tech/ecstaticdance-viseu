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
echo '{"ok":true,"service":"edv-php"}';
