<?php
/* ============================================================
   webhook.php — Stripe webhook endpoint
   POST /api/webhook.php
   Configure in Stripe Dashboard → Webhooks:
     URL: https://ecstaticdanceviseu.pt/api/webhook.php
     Events: checkout.session.completed
   ============================================================ */

require_once __DIR__ . '/helpers.php';

// Read raw body BEFORE any other processing
$payload    = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Always respond 200 fast so Stripe doesn't retry unnecessarily
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!verify_stripe_webhook($payload, $sig_header, STRIPE_WEBHOOK_SECRET)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

$type = $event['type'] ?? '';

switch ($type) {
    case 'checkout.session.completed':
        handle_checkout_completed($event['data']['object']);
        break;

    case 'checkout.session.expired':
        handle_checkout_expired($event['data']['object']);
        break;

    default:
        // Unhandled event — return 200 to acknowledge receipt
        break;
}

http_response_code(200);
echo json_encode(['received' => true]);
exit;


// ── Handler: payment completed ──
function handle_checkout_completed(array $session): void {
    if (($session['payment_status'] ?? '') !== 'paid') {
        return;
    }

    $session_id = $session['id'];
    $ticket_id  = $session['metadata']['ticket_id'] ?? null;
    $name       = $session['metadata']['name']       ?? null;
    $event_id   = (int)($session['metadata']['event_id'] ?? 0);
    $amount_raw = (int)($session['amount_total'] ?? 0);
    $amount     = $amount_raw / 100;

    if (!$ticket_id || !$event_id) {
        return;
    }

    // Update ticket to paid (idempotent — uses stripe_session_id as lock)
    $stmt = db()->prepare(
        'UPDATE tickets
         SET payment_status = \'paid\',
             amount_paid    = ?,
             paid_at        = NOW()
         WHERE id = ?
           AND stripe_session_id = ?
           AND payment_status = \'pending\''
    );
    $stmt->execute([$amount, $ticket_id, $session_id]);

    if ($stmt->rowCount() === 0) {
        // Already processed or session mismatch — safe to ignore
        return;
    }

    // Load event details for email
    $ev = db()->prepare('SELECT * FROM events WHERE id = ?');
    $ev->execute([$event_id]);
    $event = $ev->fetch();
    if (!$event) {
        return;
    }

    $customer_email = $session['customer_details']['email']
        ?? $session['customer_email']
        ?? null;

    if ($customer_email && $name) {
        send_ticket_email($customer_email, $name, $ticket_id, $event['title'], $event['date'], $amount);
    }
}


// ── Handler: session expired (cleanup pending tickets) ──
function handle_checkout_expired(array $session): void {
    $session_id = $session['id'];
    db()->prepare(
        'DELETE FROM tickets WHERE stripe_session_id = ? AND payment_status = \'pending\''
    )->execute([$session_id]);
}
