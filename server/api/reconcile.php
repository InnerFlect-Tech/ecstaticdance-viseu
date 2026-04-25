<?php
/* ============================================================
   reconcile.php — Reconcile pending Stripe payments
   Called by cPanel cron every 15 minutes:
     curl "https://ecstaticdanceviseu.pt/api/reconcile.php?token=YOUR_TOKEN"
   ============================================================ */

require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Token-protect this endpoint
$token = $_GET['token'] ?? '';
if (!hash_equals(RECONCILE_TOKEN, $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$results = [
    'processed' => 0,
    'failed'    => 0,
    'skipped'   => 0,
    'errors'    => [],
];

// Find pending tickets that have a Stripe session ID and are older than 2 minutes
$stmt = db()->prepare(
    'SELECT * FROM tickets
     WHERE payment_status = \'pending\'
       AND stripe_session_id IS NOT NULL
       AND stripe_session_id != \'\'
       AND created_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)
     LIMIT 50'
);
$stmt->execute();
$pending = $stmt->fetchAll();

foreach ($pending as $ticket) {
    $session_id = $ticket['stripe_session_id'];

    try {
        $resp = stripe_request('GET', "checkout/sessions/{$session_id}");

        if ($resp['status'] !== 200) {
            $results['errors'][] = "Session {$session_id}: HTTP {$resp['status']}";
            $results['failed']++;
            continue;
        }

        $session        = $resp['data'];
        $payment_status = $session['payment_status'] ?? '';

        if ($payment_status === 'paid') {
            // Mark as paid
            $amount = (int)($session['amount_total'] ?? 0) / 100;

            $upd = db()->prepare(
                'UPDATE tickets
                 SET payment_status = \'paid\',
                     amount_paid    = ?,
                     paid_at        = NOW()
                 WHERE id = ?
                   AND payment_status = \'pending\''
            );
            $upd->execute([$amount, $ticket['id']]);

            if ($upd->rowCount() > 0) {
                // Send confirmation email
                $ev_stmt = db()->prepare('SELECT * FROM events WHERE id = ?');
                $ev_stmt->execute([$ticket['event_id']]);
                $event = $ev_stmt->fetch();
                if ($event) {
                    send_ticket_email(
                        $ticket['email'],
                        $ticket['name'],
                        $ticket['id'],
                        $event['title'],
                        $event['date'],
                        $amount
                    );
                }
                $results['processed']++;
            } else {
                $results['skipped']++;
            }

        } elseif ($session['status'] === 'expired') {
            // Clean up expired session
            db()->prepare(
                'DELETE FROM tickets WHERE id = ? AND payment_status = \'pending\''
            )->execute([$ticket['id']]);
            $results['skipped']++;

        } else {
            // Still pending — nothing to do yet
            $results['skipped']++;
        }

    } catch (Throwable $e) {
        $results['errors'][] = "Ticket {$ticket['id']}: " . $e->getMessage();
        $results['failed']++;
    }
}

$results['total_checked'] = count($pending);
$results['timestamp']     = date('Y-m-d H:i:s');

echo json_encode($results, JSON_PRETTY_PRINT);
exit;
