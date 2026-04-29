<?php
/* ============================================================
   checkin.php — Manual check-in toggle (called via AJAX from admin panel)
   POST /admin/checkin.php
   Body: { ticket_id: string, checked_in: bool }
   ============================================================ */

require_once __DIR__ . '/auth.php';
require_admin_session();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$ticket_id = trim($body['ticket_id'] ?? '');
$checked   = !empty($body['checked_in']);

if (!$ticket_id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'ticket_id em falta']);
    exit;
}

$stmt = db()->prepare(
    'UPDATE tickets
     SET checked_in    = ?,
         checked_in_at = IF(? = 1, NOW(), NULL)
     WHERE id = ?
       AND payment_status IN (\'paid\', \'free\')'
);
$stmt->execute([(int)$checked, (int)$checked, $ticket_id]);

if ($stmt->rowCount() === 0) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Bilhete não encontrado ou inválido']);
    exit;
}

echo json_encode(['ok' => true, 'checked_in' => $checked]);
exit;
