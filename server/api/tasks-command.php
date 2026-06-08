<?php
declare(strict_types=1);

/**
 * WhatsApp two-way command handler for the ED Viseu Core Team group.
 *
 * Flow: WAHA delivers an inbound group message to an n8n webhook → n8n POSTs
 * { "text": "<message body>" } here → this endpoint interprets the command,
 * performs the action on campaign_tasks, and returns { ok, reply }. n8n then
 * sends `reply` back to the group via WAHA. When `reply` is null the message
 * wasn't a command and n8n should send nothing (keeps the bot quiet on chat).
 *
 * Auth: env EDV_TASKS_TOKEN is REQUIRED. Pass it as ?token= or header
 *       X-Tasks-Token. Same token guards the public board (tarefas.php).
 *
 * Commands (PT): ajuda | tarefas | feito <nº> | nova <área>: <título>
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../admin/campaign-lib.php';

header('Content-Type: application/json; charset=utf-8');

$expected = trim((string) getenv('EDV_TASKS_TOKEN'));
if ($expected === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'EDV_TASKS_TOKEN não configurada (env do edv-server no Coolify).'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = (string) ($_GET['token'] ?? ($_SERVER['HTTP_X_TASKS_TOKEN'] ?? ''));
if (!hash_equals($expected, $token)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_body();
$text = trim((string) ($body['text'] ?? ($_GET['text'] ?? '')));

$pdo = db();
edv_campaign_ensure_schema($pdo);
edv_campaign_seed_from_meeting($pdo);

$reply = edv_campaign_handle_command($pdo, $text);

echo json_encode(['ok' => true, 'reply' => $reply], JSON_UNESCAPED_UNICODE);
