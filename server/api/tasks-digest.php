<?php
declare(strict_types=1);

/**
 * Read-only campaign task digest for the morning WhatsApp message.
 * Returns the open / overdue / due-soon tasks as JSON (+ a ready-to-send text).
 *
 * Auth: if env EDV_DIGEST_TOKEN is set, require ?token= to match it.
 *       If it is not set, the endpoint is open (tasks are low-sensitivity).
 * ?send=1 also posts the digest to the WhatsApp group via WAHA (needs
 *          EDV_WAHA_API_KEY on this app); otherwise just returns the text.
 *
 * Used by the n8n "EDV — Task Digest 06:00" workflow.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../admin/campaign-lib.php';
require_once __DIR__ . '/whatsapp.php';

header('Content-Type: application/json; charset=utf-8');

$expected = getenv('EDV_DIGEST_TOKEN');
$expected = is_string($expected) ? trim($expected) : '';
if ($expected !== '') {
    $token = (string) ($_GET['token'] ?? ($_SERVER['HTTP_X_DIGEST_TOKEN'] ?? ''));
    if (!hash_equals($expected, $token)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'unauthorized']);
        exit;
    }
}

$pdo = db();
edv_campaign_ensure_schema($pdo);
edv_campaign_seed_from_meeting($pdo);

$digest = edv_campaign_digest($pdo);

// Footer: total open + a follow-up link to the public board (token-protected).
$linkBase = trim((string) getenv('EDV_TASKS_PUBLIC_URL'));
if ($linkBase === '') {
    $appUrl = defined('APP_URL') && APP_URL !== '' ? rtrim((string) APP_URL, '/') : 'https://ecstaticdanceviseu.pt';
    $linkBase = $appUrl . '/api/tarefas.php';
}
$tasksToken = trim((string) getenv('EDV_TASKS_TOKEN'));
$followUp = $linkBase . ($tasksToken !== '' ? '?t=' . rawurlencode($tasksToken) : '');

$text = $digest['text'] . "\n\n" . $digest['open'] . ' tarefas abertas → ' . $followUp;

$result = [
    'ok'       => true,
    'date'     => $digest['date'],
    'open'     => $digest['open'],
    'overdue'  => $digest['overdue'],
    'today'    => $digest['today'],
    'soon'     => $digest['soon'],
    'no_owner' => $digest['no_owner'],
    'text'     => $text,
];

if (($_GET['send'] ?? '') === '1') {
    $res = edv_waha_send_text($text);
    $result['sent'] = (bool) $res['ok'];
    if (empty($res['ok'])) {
        $result['send_error'] = $res['error'] ?? 'failed';
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
