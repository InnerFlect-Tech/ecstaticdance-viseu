<?php
/* ============================================================
   export.php — Export tickets as CSV
   GET /admin/export.php?event_id=N
   ============================================================ */

require_once __DIR__ . '/auth.php';
require_admin_session();

$event_id = (int)($_GET['event_id'] ?? 0);
if (!$event_id) {
    http_response_code(400);
    echo 'event_id em falta';
    exit;
}

// Load event name for filename
$ev_stmt = db()->prepare('SELECT title, date FROM events WHERE id = ?');
$ev_stmt->execute([$event_id]);
$event = $ev_stmt->fetch();
if (!$event) {
    http_response_code(404);
    echo 'Evento não encontrado';
    exit;
}

$stmt = db()->prepare(
    'SELECT
        id              AS "ID Bilhete",
        name            AS "Nome",
        email           AS "Email",
        phone           AS "Telemóvel",
        payment_status  AS "Estado",
        amount_paid     AS "Valor (€)",
        checked_in      AS "Entrada",
        checked_in_at   AS "Hora Entrada",
        created_at      AS "Reservado Em"
     FROM tickets
     WHERE event_id = ?
       AND payment_status IN (\'paid\', \'free\')
     ORDER BY created_at ASC'
);
$stmt->execute([$event_id]);
$tickets = $stmt->fetchAll();

$slug     = preg_replace('/[^a-z0-9]+/i', '-', strtolower($event['title']));
$date     = date('Y-m-d', strtotime($event['date']));
$filename = "bilhetes-{$slug}-{$date}.csv";

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

// BOM for Excel UTF-8 compatibility
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

if (!empty($tickets)) {
    fputcsv($out, array_keys($tickets[0]), ';');
    foreach ($tickets as $row) {
        $row['Entrada'] = $row['Entrada'] ? 'Sim' : 'Não';
        fputcsv($out, array_values($row), ';');
    }
} else {
    fputcsv($out, ['Sem bilhetes para este evento.'], ';');
}

fclose($out);
exit;
