<?php
/* POST /api/save-link-booking.php
 * Passo 1: grava pedido (link_registrations) e devolve id + payment_ref. */

declare(strict_types=1);

require_once __DIR__ . '/link-common.php';

link_api_cors();
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    link_json_err('Method not allowed', 405);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    link_json_err('JSON inválido.');
}

$name          = link_sanitise((string)($body['name'] ?? ''), 255);
$email         = link_sanitise((string)($body['email'] ?? ''), 255);
$phone         = link_sanitise((string)($body['phone'] ?? ''), 40);
$ticket_euros  = (float)($body['ticket_euros'] ?? 0);
$total_euros   = (float)($body['total_euros'] ?? 0);
$dinner_note   = link_sanitise((string)($body['dinner_note'] ?? ''), 64);
$payment_method = link_sanitise((string)($body['payment_method'] ?? ''), 20);
$heard_from    = link_sanitise((string)($body['heard_from'] ?? ''), 32);
$heard_other   = link_sanitise((string)($body['heard_other'] ?? ''), 255);
$event_slug    = link_sanitise((string)($body['event_slug'] ?? 'edv-2026-05-23'), 64);

$allowed_m = ['mbway', 'transfer', 'revolut'];
$allowed_h = ['instagram', 'facebook', 'friends', 'mailing', 'whatsapp', 'telegram', 'other'];

if ($name === '' || $email === '' || $phone === '') {
    link_json_err('Nome, email e telemóvel são obrigatórios.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    link_json_err('Email inválido.');
}
if (!in_array($payment_method, $allowed_m, true)) {
    link_json_err('Método de pagamento inválido.');
}
if (!in_array($heard_from, $allowed_h, true)) {
    link_json_err('Indica como tiveste conhecimento do evento.');
}
if ($heard_from === 'other' && $heard_other === '') {
    link_json_err('Especifica em «Outro» como tiveste conhecimento.');
}
if ($ticket_euros < 0 || $total_euros < 0) {
    link_json_err('Valores inválidos.');
}
$tmin = link_ticket_min_eur();
$tmax = link_ticket_max_eur();
if ($ticket_euros < $tmin - 0.001 || $ticket_euros > $tmax + 0.001) {
    link_json_err(
        sprintf('Valor do bilhete fora do intervalo (€%d–€%d).', (int) $tmin, (int) $tmax),
        400
    );
}

try {
    $pdo = link_api_db();
} catch (Throwable $e) {
    link_json_err('Base de dados: ' . $e->getMessage(), 500);
}
$now   = link_sql_now();
$done  = false;
$lastE = null;
$ref   = '';
$id    = '';
for ($tries = 0; $tries < 6 && !$done; $tries++) {
    $id  = link_uuid_v4();
    $ref = link_generate_payment_ref();
    try {
        $st = $pdo->prepare(
            'INSERT INTO link_registrations
             (id, payment_ref, event_slug, name, email, phone, ticket_euros, dinner_note, total_euros, payment_method, heard_from, heard_other, step1_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $id,
            $ref,
            $event_slug,
            $name,
            $email,
            $phone,
            $ticket_euros,
            $dinner_note,
            $total_euros,
            $payment_method,
            $heard_from,
            $heard_other === '' ? null : $heard_other,
            $now,
            $now,
            $now,
        ]);
        $done = true;
    } catch (PDOException $e) {
        $lastE = $e;
        if (
            str_contains($e->getMessage(), '1062')
            || str_contains($e->getMessage(), 'Duplicate')
            || str_contains($e->getMessage(), 'UNIQUE constraint')
        ) {
            continue;
        }
        link_json_err('Erro a gravar: ' . $e->getMessage(), 500);
    }
}
if (!$done) {
    link_json_err('Não foi possível gravar. Tenta outra vez. ' . ($lastE?->getMessage() ?? ''), 500);
}

$label_h = match ($heard_from) {
    'instagram' => 'Instagram',
    'facebook'  => 'Facebook',
    'friends'   => 'Amigos / Friends',
    'mailing'   => 'Mailing list',
    'whatsapp'  => 'WhatsApp',
    'telegram'  => 'Telegram',
    'other'     => 'Outro: ' . $heard_other,
    default     => $heard_from,
};

$email_body = "Passo 1 — pedido (links)\n\n"
    . "Ref: $ref\nID: $id\n"
    . "Evento: $event_slug\n"
    . "Nome: $name\nEmail: $email\nTel: $phone\n"
    . "Bilhete: €" . number_format($ticket_euros, 2, ',', ' ') . "\n"
    . "Jantar: $dinner_note\n"
    . "Total: €" . number_format($total_euros, 2, ',', ' ') . "\n"
    . "Pagamento (preferência): $payment_method\n"
    . "Como conheceu: $label_h\n";

link_notify_team("Pedido $ref — passo 1", $email_body);

link_json_ok([
    'registration_id' => $id,
    'payment_ref'     => $ref,
]);
