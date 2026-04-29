<?php
/* ============================================================
   create-checkout.php — Stripe Checkout session
   POST /api/create-checkout.php          → create session
   GET  /api/create-checkout.php?verify=  → verify & get ticket
   ============================================================ */

require_once __DIR__ . '/helpers.php';

cors();
header('Cache-Control: no-store');

// ── GET: verify a completed session ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['verify'])) {
    $session_id = sanitise($_GET['verify'], 200);

    // Retrieve session from Stripe
    $resp = stripe_request('GET', "checkout/sessions/{$session_id}");
    if ($resp['status'] !== 200 || ($resp['data']['object'] ?? '') !== 'checkout.session') {
        json_err('Sessão não encontrada.', 404);
    }

    $session = $resp['data'];
    if ($session['payment_status'] !== 'paid') {
        json_err('Pagamento ainda não confirmado.', 402);
    }

    // Load ticket from DB
    $stmt = db()->prepare(
        'SELECT t.*, e.title AS event_title, e.date AS event_date
         FROM tickets t
         JOIN events e ON e.id = t.event_id
         WHERE t.stripe_session_id = ?'
    );
    $stmt->execute([$session_id]);
    $ticket = $stmt->fetch();

    if (!$ticket) {
        json_err('Bilhete não encontrado. Aguarda alguns segundos e refresca.', 404);
    }

    json_ok(['ticket' => $ticket]);
}

// ── POST: create checkout session ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body = json_body();

$event_id = (int)($body['event_id'] ?? 0);
$name     = sanitise($body['name']  ?? '');
$email    = sanitise($body['email'] ?? '');
$phone    = sanitise($body['phone'] ?? '');
$amount   = (int)($body['amount']   ?? 30);

if (!$event_id || !$name || !$email || !$phone) {
    json_err('Campos obrigatórios em falta.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_err('Email inválido.');
}
$tz_early = new DateTimeZone('Europe/Lisbon');
$now_lx   = new DateTime('now', $tz_early);
$early_end = new DateTime('2026-05-04 00:00:00', $tz_early);
$min_allowed = ($now_lx < $early_end) ? 20 : 30;

if ($amount < $min_allowed || $amount > 200) {
    json_err('Valor fora do intervalo permitido (€' . $min_allowed . '–€200).');
}

// Verify event exists, is active and is paid type
$stmt = db()->prepare(
    'SELECT * FROM events WHERE id = ? AND is_active = 1 AND type = \'paid\''
);
$stmt->execute([$event_id]);
$event = $stmt->fetch();
if (!$event) {
    json_err('Evento não encontrado ou não disponível para compra.');
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

// Create pending ticket
$ticket_id = generate_uuid();
$ins = db()->prepare(
    'INSERT INTO tickets
     (id, event_id, name, email, phone, amount_paid, payment_status, created_at)
     VALUES (?, ?, ?, ?, ?, ?, \'pending\', NOW())'
);
$ins->execute([$ticket_id, $event_id, $name, $email, $phone, $amount]);

// Create Stripe Checkout session
$app_url = APP_URL;
$resp = stripe_request('POST', 'checkout/sessions', [
    'mode'                => 'payment',
    'currency'            => 'eur',
    'payment_method_types[]' => 'card',
    // MB Way and Multibanco are enabled via Stripe Dashboard automatic payment methods
    'line_items[0][price_data][currency]'                       => 'eur',
    'line_items[0][price_data][product_data][name]'             => $event['title'],
    'line_items[0][price_data][product_data][description]'      => date('d/m/Y', strtotime($event['date'])) . ' · Ecstatic Dance Viseu',
    'line_items[0][price_data][unit_amount]'                    => $amount * 100,
    'line_items[0][quantity]'                                   => 1,
    'customer_email'                                            => $email,
    'metadata[ticket_id]'                                       => $ticket_id,
    'metadata[event_id]'                                        => $event_id,
    'metadata[name]'                                            => $name,
    'metadata[phone]'                                           => $phone,
    'success_url'   => $app_url . '/confirmacao?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'    => $app_url . '/cancelamento',
    'locale'        => 'pt',
    'phone_number_collection[enabled]' => 'false',
]);

if ($resp['status'] !== 200 || empty($resp['data']['url'])) {
    // Roll back the pending ticket
    db()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticket_id]);
    json_err('Erro ao criar sessão de pagamento. Tenta novamente.', 500);
}

$session = $resp['data'];

// Store session ID on the ticket
db()->prepare(
    'UPDATE tickets SET stripe_session_id = ? WHERE id = ?'
)->execute([$session['id'], $ticket_id]);

json_ok(['url' => $session['url']]);
