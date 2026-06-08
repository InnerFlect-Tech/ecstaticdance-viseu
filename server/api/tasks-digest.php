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

$today = date('Y-m-d');
$soonLimit = date('Y-m-d', strtotime('+3 days'));

$rows = $pdo->query(
    "SELECT * FROM campaign_tasks WHERE status <> 'done'
     ORDER BY (CASE WHEN due_date IS NULL THEN 1 ELSE 0 END), due_date ASC, sort_order ASC"
)->fetchAll();

$overdue = [];
$dueToday = [];
$soon = [];
$noOwner = [];
foreach ($rows as $r) {
    $d = (string) ($r['due_date'] ?? '');
    if ($d !== '' && $d < $today) {
        $overdue[] = $r;
    } elseif ($d === $today && $d !== '') {
        $dueToday[] = $r;
    } elseif ($d !== '' && $d <= $soonLimit) {
        $soon[] = $r;
    }
    if (empty($r['owner'])) {
        $noOwner[] = $r;
    }
}

$fmt = static function (array $r): string {
    $s = '• ' . (string) $r['title'];
    if (!empty($r['owner'])) {
        $s .= ' (' . (string) $r['owner'] . ')';
    }
    if (!empty($r['due_date'])) {
        $s .= ' — ' . substr((string) $r['due_date'], 0, 10);
    }
    return $s;
};

$lines = ['☀️ *Tarefas ED Viseu* — ' . $today];
if ($overdue !== []) {
    $lines[] = '';
    $lines[] = '⚠️ *Atrasadas*';
    foreach ($overdue as $r) {
        $lines[] = $fmt($r);
    }
}
if ($dueToday !== []) {
    $lines[] = '';
    $lines[] = '📌 *Hoje*';
    foreach ($dueToday as $r) {
        $lines[] = $fmt($r);
    }
}
if ($soon !== []) {
    $lines[] = '';
    $lines[] = '🔜 *Próximos 3 dias*';
    foreach ($soon as $r) {
        $lines[] = $fmt($r);
    }
}
if ($overdue === [] && $dueToday === [] && $soon === []) {
    $lines[] = '';
    $lines[] = 'Sem tarefas com prazo iminente. 🙌';
}
if ($noOwner !== []) {
    $lines[] = '';
    $lines[] = '🙋 *Sem responsável* (' . count($noOwner) . ') — atribuir';
}
$lines[] = '';
$lines[] = count($rows) . ' tarefas abertas no total → /admin/tasks.php';

$text = implode("\n", $lines);

$result = [
    'ok' => true,
    'date' => $today,
    'open' => count($rows),
    'overdue' => count($overdue),
    'today' => count($dueToday),
    'soon' => count($soon),
    'no_owner' => count($noOwner),
    'text' => $text,
];

if (($_GET['send'] ?? '') === '1') {
    $res = edv_waha_send_text($text);
    $result['sent'] = (bool) $res['ok'];
    if (empty($res['ok'])) {
        $result['send_error'] = $res['error'] ?? 'failed';
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
