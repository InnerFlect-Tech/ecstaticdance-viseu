<?php
/* ============================================================
   get-events.php — Returns the next active event
   GET /api/get-events.php
   ============================================================ */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/event-display.php';

cors();
header('Cache-Control: no-store');

edv_events_ensure_display_columns(db());
edv_event_apply_02_activation(db());

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$stmt = db()->prepare(
    'SELECT e.*,
            (SELECT COUNT(*) FROM tickets t
             WHERE t.event_id = e.id
               AND t.payment_status IN (\'paid\', \'free\')) AS tickets_sold
     FROM events e
     WHERE e.is_active = 1
       AND e.date >= ?
     ORDER BY e.date ASC
     LIMIT 1'
);
$stmt->execute([db_today_string()]);
$event = $stmt->fetch();

if (!$event) {
    json_ok(['event' => null]);
}

json_ok(['event' => edv_event_public_payload($event)]);
