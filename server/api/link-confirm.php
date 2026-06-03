<?php
/**
 * Confirma uma reserva manual (link_registrations) → cria bilhete + email com QR.
 */
declare(strict_types=1);

require_once __DIR__ . '/link-common.php';
require_once __DIR__ . '/helpers.php';

/**
 * @return array{ok:bool, error?:string, ticket_id?:string, already?:bool}
 */
function link_confirm_registration(string $registrationId): array
{
    $rid = link_sanitise($registrationId, 36);
    if (strlen($rid) < 32) {
        return ['ok' => false, 'error' => 'ID de registo inválido.'];
    }

    $backend = link_registration_backend();
    $row     = null;

    if ($backend === 'json') {
        require_once __DIR__ . '/link-json-store.php';
        $row = link_json_find_registration($rid);
    } else {
        $pdo = link_api_db();
        link_registrations_ensure_columns($pdo);
        $q = $pdo->prepare('SELECT * FROM link_registrations WHERE id = ?');
        $q->execute([$rid]);
        $row = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!is_array($row) || $row === []) {
        return ['ok' => false, 'error' => 'Registo não encontrado.'];
    }

    if (empty($row['step2_at'])) {
        return ['ok' => false, 'error' => 'O passo 2 ainda não foi concluído (sem comprovativo ou envio combinado).'];
    }

    $existingTicket = trim((string) ($row['ticket_id'] ?? ''));
    if ($existingTicket !== '') {
        return ['ok' => true, 'ticket_id' => $existingTicket, 'already' => true];
    }

    $email      = trim((string) ($row['email'] ?? ''));
    $name       = trim((string) ($row['name'] ?? ''));
    $phone      = trim((string) ($row['phone'] ?? ''));
    $slug       = trim((string) ($row['event_slug'] ?? ''));
    $total      = (float) ($row['total_euros'] ?? 0);
    $ticketEuro = (float) ($row['ticket_euros'] ?? $total);

    if ($email === '' || $name === '' || $phone === '') {
        return ['ok' => false, 'error' => 'Dados do participante incompletos.'];
    }

    $eventId = link_resolve_event_id_from_slug($slug);
    if ($eventId === null) {
        return ['ok' => false, 'error' => 'Não foi encontrado evento na base principal para o slug ' . $slug . '. Cria/activa o evento em Admin → Eventos.'];
    }

    $evStmt = db()->prepare('SELECT id, title, date, capacity FROM events WHERE id = ?');
    $evStmt->execute([$eventId]);
    $event = $evStmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) {
        return ['ok' => false, 'error' => 'Evento não encontrado.'];
    }

    $dup = db()->prepare(
        'SELECT id FROM tickets WHERE event_id = ? AND email = ? AND payment_status IN (\'paid\', \'free\') LIMIT 1'
    );
    $dup->execute([$eventId, $email]);
    if ($dup->fetch()) {
        return ['ok' => false, 'error' => 'Já existe bilhete confirmado com este email para este evento.'];
    }

    $cap = (int) ($event['capacity'] ?? 0);
    if ($cap > 0) {
        $sold = db()->prepare(
            'SELECT COUNT(*) FROM tickets WHERE event_id = ? AND payment_status IN (\'paid\', \'free\')'
        );
        $sold->execute([$eventId]);
        if ((int) $sold->fetchColumn() >= $cap) {
            return ['ok' => false, 'error' => 'Capacidade do evento esgotada.'];
        }
    }

    $ticketId = generate_uuid();
    $now      = db_now_string();

    $priceTier = edv_ticket_price_tier($email, $eventId);
    edv_attendance_ensure_schema(db());

    db()->prepare(
        'INSERT INTO tickets
         (id, event_id, name, email, phone, amount_paid, price_tier, payment_status, paid_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, \'paid\', ?, ?)'
    )->execute([$ticketId, $eventId, $name, $email, $phone, $ticketEuro, $priceTier, $now, $now]);

    $patch = [
        'ticket_id'    => $ticketId,
        'confirmed_at' => $now,
        'updated_at'   => link_sql_now(),
    ];

    if ($backend === 'json') {
        if (!link_json_patch_registration($rid, $patch)) {
            db()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
            return ['ok' => false, 'error' => 'Não foi possível actualizar o registo.'];
        }
    } else {
        $pdo = link_api_db();
        link_registrations_ensure_columns($pdo);
        $u = $pdo->prepare(
            'UPDATE link_registrations SET ticket_id = ?, confirmed_at = ?, updated_at = ? WHERE id = ?'
        );
        $u->execute([$ticketId, $now, $patch['updated_at'], $rid]);
    }

    $mailed = send_ticket_email(
        $email,
        $name,
        $ticketId,
        (string) $event['title'],
        (string) $event['date'],
        $ticketEuro
    );

    if (!$mailed) {
        return [
            'ok'        => true,
            'ticket_id' => $ticketId,
            'error'     => 'Bilhete criado, mas o email não foi enviado (verifica mail() no servidor).',
        ];
    }

    return ['ok' => true, 'ticket_id' => $ticketId];
}

