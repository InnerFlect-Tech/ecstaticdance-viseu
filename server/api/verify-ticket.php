<?php
/* ============================================================
   verify-ticket.php — Verify and check in a ticket by QR scan
   GET  /api/verify-ticket.php?code=UUID&preview=1   → read-only lookup
   POST /api/verify-ticket.php                       → check-in
   ============================================================ */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/attendance.php';

cors();
header('Cache-Control: no-store');

// ── GET: preview / load ticket for confirmation page ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $code    = normalize_ticket_code((string) ($_GET['code'] ?? ''));
    $preview = isset($_GET['preview']) && $_GET['preview'] === '1';

    if ($code === '') {
        json_err('Código em falta.', 400);
    }

    $stmt = db()->prepare(
        'SELECT t.*, e.title AS event_title, e.date AS event_date
         FROM tickets t
         JOIN events e ON e.id = t.event_id
         WHERE t.id = ?'
    );
    $stmt->execute([$code]);
    $ticket = $stmt->fetch();

    if (!$ticket) {
        json_err('Bilhete não encontrado.', 404);
    }

    if (!$preview && $ticket['payment_status'] === 'pending') {
        json_err('Pagamento pendente — bilhete ainda não válido.', 402);
    }

    json_ok(['ticket' => $ticket]);
}

// ── POST: check-in (called from admin QR scanner) ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

// Require admin session
require_once __DIR__ . '/../admin/auth.php';
require_admin_session();

$body = json_body();
$code = normalize_ticket_code((string) ($body['code'] ?? ''));
$expectedEventId = isset($body['event_id']) ? (int) $body['event_id'] : 0;

if ($code === '') {
    json_err('Código em falta — lê o QR ou cola o código do bilhete.');
}

$stmt = db()->prepare(
    'SELECT t.*, e.title AS event_title, e.date AS event_date, e.capacity,
            (SELECT COUNT(*) FROM tickets t2
             WHERE t2.event_id = e.id
               AND t2.checked_in = 1) AS checked_in_count
     FROM tickets t
     JOIN events e ON e.id = t.event_id
     WHERE t.id = ?'
);
$stmt->execute([$code]);
$ticket = $stmt->fetch();

if (!$ticket) {
    json_err('Bilhete inválido — não encontrado.', 404);
}

if (!in_array($ticket['payment_status'], ['paid', 'free'], true)) {
    json_err('Bilhete inválido — pagamento não confirmado.', 402);
}

if ($expectedEventId > 0 && (int) $ticket['event_id'] !== $expectedEventId) {
    json_err(
        'Este bilhete é para «' . $ticket['event_title'] . '» ('
        . date('d/m/Y', strtotime((string) $ticket['event_date']))
        . '), não para o evento seleccionado no scanner.',
        409
    );
}

if ($ticket['checked_in']) {
    json_err(
        'Bilhete já utilizado às ' . date('H:i', strtotime($ticket['checked_in_at'])) . '.',
        409
    );
}

// Mark checked in
db()->prepare(
    'UPDATE tickets SET checked_in = 1, checked_in_at = ? WHERE id = ?'
)->execute([db_now_string(), $code]);

$ticket['checked_in']    = 1;
$ticket['checked_in_at'] = date('Y-m-d H:i:s');

edv_attendance_sync_for_ticket($code, true);

json_ok([
    'ticket'        => $ticket,
    'message'       => 'Entrada válida — bem-vindo/a!',
    'checked_in_count' => (int)$ticket['checked_in_count'] + 1,
]);
