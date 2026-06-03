<?php
declare(strict_types=1);
/**
 * GET /api/health.php — liveness + diagnóstico (?diag=1).
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
http_response_code(200);

$root = dirname(__DIR__, 2);
$distDir = $root . '/dist';
$infoPath = __DIR__ . '/build-info.json';

$payload = [
    'ok'      => true,
    'service' => 'edv-php',
    'stack'   => getenv('COOLIFY_RESOURCE_UUID') ? 'nixpacks-vite-preview' : 'local',
];

if (is_readable($infoPath)) {
    $info = json_decode((string) file_get_contents($infoPath), true);
    if (is_array($info)) {
        $payload = array_merge($payload, $info);
    }
}

$diag = isset($_GET['diag']) && $_GET['diag'] !== '0' && $_GET['diag'] !== '';
if ($diag) {
    $payload['diag'] = [
        'php_version'     => PHP_VERSION,
        'dist_exists'     => is_dir($distDir),
        'deploy_stamp'    => is_readable($distDir . '/deploy-stamp.json'),
        'get_ticket_pricing' => is_file(__DIR__ . '/get-ticket-pricing.php'),
        'link_mail'       => is_file(__DIR__ . '/link-mail.php'),
    ];
    $linksHtml = $distDir . '/links.html';
    if (is_readable($linksHtml)) {
        $html = (string) file_get_contents($linksHtml);
        if (preg_match('/manual-booking-([A-Za-z0-9_-]+)\.js/', $html, $m)) {
            $payload['diag']['links_manual_booking_js'] = $m[1];
        }
        $payload['diag']['links_html_mtime'] = date('c', (int) filemtime($linksHtml));
    }
    if (is_readable($distDir . '/deploy-stamp.json')) {
        $stamp = json_decode((string) file_get_contents($distDir . '/deploy-stamp.json'), true);
        if (is_array($stamp)) {
            $payload['diag']['deploy_stamp'] = $stamp;
        }
    }
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES);
