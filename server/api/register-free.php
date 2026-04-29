<?php
/* ============================================================
   register-free.php — Register a free ticket
   POST /api/register-free.php
   ============================================================ */

require_once __DIR__ . '/helpers.php';

cors();
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body = json_body();

$event_id = (int)($body['event_id'] ?? 0);
$name     = sanitise($body['name']  ?? '');
$email    = sanitise($body['email'] ?? '');
$phone    = sanitise($body['phone'] ?? '');

if (!$event_id || !$name || !$email || !$phone) {
    json_err('Campos obrigatórios em falta.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_err('Email inválido.');
}

// Verify event exists, is active, and is free type
$stmt = db()->prepare(
    'SELECT * FROM events WHERE id = ? AND is_active = 1 AND type = \'free\''
);
$stmt->execute([$event_id]);
$event = $stmt->fetch();
if (!$event) {
    json_err('Evento não encontrado ou não disponível para reserva gratuita.');
}

// Check capacity
$cap_stmt = db()->prepare(
    'SELECT COUNT(*) FROM tickets
     WHERE event_id = ? AND payment_status IN (\'paid\', \'free\')'
);
$cap_stmt->execute([$event_id]);
$sold = (int)$cap_stmt->fetchColumn();
if ((int)$event['capacity'] > 0 && $sold >= (int)$event['capacity']) {
    json_err('Lamentamos, este evento está esgotado.');
}

// Check for duplicate email on this event
$dup = db()->prepare(
    'SELECT id FROM tickets WHERE event_id = ? AND email = ? AND payment_status IN (\'paid\', \'free\')'
);
$dup->execute([$event_id, $email]);
if ($dup->fetch()) {
    json_err('Já existe uma reserva com este email para este evento.');
}

// Create confirmed free ticket
$ticket_id = generate_uuid();
$ins = db()->prepare(
    'INSERT INTO tickets
     (id, event_id, name, email, phone, amount_paid, payment_status, created_at)
     VALUES (?, ?, ?, ?, ?, 0, \'free\', NOW())'
);
$ins->execute([$ticket_id, $event_id, $name, $email, $phone]);

// Send email with QR code
send_ticket_email($email, $name, $ticket_id, $event['title'], $event['date'], 0);

json_ok(['ticket_id' => $ticket_id]);
