<?php
declare(strict_types=1);

/**
 * Participantes por evento — lista unificada de bilhetes (pagos/grátis),
 * reservas manuais do /links ainda por confirmar, e facilitadores.
 *
 * Acrescenta a coluna tickets.role ('participant' | 'facilitator') e dá
 * suporte à página /admin/participants.php: edição de contactos, marcação
 * de presença e mensagem WhatsApp pré-preenchida por pessoa (envio via WAHA).
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/attendance.php';

const EDV_TICKET_ROLES = [
    'participant' => 'Participante',
    'facilitator' => 'Facilitador·a',
];

/**
 * Garante tickets.role (SQLite + MySQL + Postgres). Idempotente.
 */
function edv_tickets_ensure_role_column(PDO $pdo): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $cols = $pdo->query('PRAGMA table_info(tickets)')->fetchAll(PDO::FETCH_ASSOC);
        if (!in_array('role', array_column($cols, 'name'), true)) {
            $pdo->exec("ALTER TABLE tickets ADD COLUMN role TEXT NOT NULL DEFAULT 'participant'");
        }

        return;
    }

    if ($driver === 'mysql') {
        try {
            $chk = $pdo->query(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'role'"
            );
            if ($chk && !$chk->fetchColumn()) {
                $pdo->exec(
                    "ALTER TABLE `tickets` ADD COLUMN `role` VARCHAR(20) NOT NULL DEFAULT 'participant' AFTER `price_tier`"
                );
            }
        } catch (PDOException) {
            // coluna já existe
        }

        return;
    }

    if ($driver === 'pgsql') {
        $pdo->exec("ALTER TABLE tickets ADD COLUMN IF NOT EXISTS role VARCHAR(20) NOT NULL DEFAULT 'participant'");
    }
}

function edv_event_slug_for_date(string $date): string
{
    return 'edv-' . substr(trim($date), 0, 10);
}

/**
 * Lista unificada de pessoas do evento.
 *
 * Cada linha: kind ('ticket'|'booking'), id, name, email, phone, amount (float),
 * role, payment_status, checked_in (bool), checked_in_at, payment_ref, step2_at.
 *
 * @return list<array<string,mixed>>
 */
function edv_participants_list_for_event(PDO $pdo, int $eventId): array
{
    edv_attendance_ensure_schema($pdo);
    edv_tickets_ensure_role_column($pdo);

    $rows = [];

    $tq = $pdo->prepare(
        "SELECT t.id, t.name, t.email, t.phone, t.amount_paid, t.price_tier, t.role,
                t.payment_status, t.checked_in, t.checked_in_at, t.created_at,
                ea.id AS attendance_id, ea.checked_in_at AS attended_at
         FROM tickets t
         LEFT JOIN event_attendance ea ON ea.ticket_id = t.id
         WHERE t.event_id = ? AND t.payment_status IN ('paid', 'free')
         ORDER BY CASE WHEN t.role = 'facilitator' THEN 0 ELSE 1 END, t.name ASC"
    );
    $tq->execute([$eventId]);
    foreach ($tq->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $rows[] = [
            'kind'           => 'ticket',
            'id'             => (string) $t['id'],
            'name'           => (string) $t['name'],
            'email'          => (string) $t['email'],
            'phone'          => (string) $t['phone'],
            'amount'         => (float) $t['amount_paid'],
            'role'           => in_array((string) ($t['role'] ?? ''), array_keys(EDV_TICKET_ROLES), true)
                ? (string) $t['role'] : 'participant',
            'payment_status' => (string) $t['payment_status'],
            'checked_in'     => $t['attendance_id'] !== null || (int) ($t['checked_in'] ?? 0) === 1,
            'checked_in_at'  => (string) ($t['attended_at'] ?? $t['checked_in_at'] ?? ''),
            'payment_ref'    => null,
            'step2_at'       => null,
        ];
    }

    // Reservas /links ainda sem bilhete (por confirmar)
    $dq = $pdo->prepare('SELECT date FROM events WHERE id = ?');
    $dq->execute([$eventId]);
    $eventDate = (string) ($dq->fetchColumn() ?: '');
    if ($eventDate !== '') {
        try {
            $bq = $pdo->prepare(
                'SELECT id, payment_ref, name, email, phone, ticket_euros, step2_at
                 FROM link_registrations
                 WHERE event_slug = ? AND ticket_id IS NULL
                 ORDER BY step1_at ASC'
            );
            $bq->execute([edv_event_slug_for_date($eventDate)]);
            foreach ($bq->fetchAll(PDO::FETCH_ASSOC) as $b) {
                $rows[] = [
                    'kind'           => 'booking',
                    'id'             => (string) $b['id'],
                    'name'           => (string) $b['name'],
                    'email'          => (string) $b['email'],
                    'phone'          => (string) $b['phone'],
                    'amount'         => (float) $b['ticket_euros'],
                    'role'           => 'participant',
                    'payment_status' => 'pending',
                    'checked_in'     => false,
                    'checked_in_at'  => '',
                    'payment_ref'    => (string) $b['payment_ref'],
                    'step2_at'       => $b['step2_at'] !== null ? (string) $b['step2_at'] : null,
                ];
            }
        } catch (Throwable) {
            // tabela link_registrations pode não existir neste ambiente
        }
    }

    return $rows;
}

/**
 * Atualiza nome/email/telemóvel de um bilhete e propaga a event_attendance
 * e link_registrations associadas.
 *
 * @return array{ok:bool,error?:string}
 */
function edv_participant_update_contact(PDO $pdo, string $ticketId, string $name, string $email, string $phone): array
{
    edv_attendance_ensure_schema($pdo);

    $ticketId = trim($ticketId);
    $name = sanitise($name);
    $phone = sanitise($phone, 40);
    $email = edv_normalize_email($email);

    if ($ticketId === '' || $name === '') {
        return ['ok' => false, 'error' => 'Nome em falta.'];
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Email inválido.'];
    }

    $tq = $pdo->prepare('SELECT id, event_id, email FROM tickets WHERE id = ? LIMIT 1');
    $tq->execute([$ticketId]);
    $ticket = $tq->fetch(PDO::FETCH_ASSOC);
    if (!is_array($ticket)) {
        return ['ok' => false, 'error' => 'Bilhete não encontrado.'];
    }

    // Sem email real → marcador estável a partir do telemóvel/nome (tickets.email é NOT NULL)
    $resolvedEmail = $email !== '' ? $email : edv_presence_email_resolve(null, $phone, $name);

    // Não colidir com outra presença do mesmo evento
    $dup = $pdo->prepare(
        'SELECT 1 FROM event_attendance WHERE event_id = ? AND email = ? AND ticket_id <> ? LIMIT 1'
    );
    $dup->execute([(int) $ticket['event_id'], $resolvedEmail, $ticketId]);
    if ($dup->fetchColumn()) {
        return ['ok' => false, 'error' => 'Já existe outra presença neste evento com esse email.'];
    }

    try {
        $pdo->prepare('UPDATE tickets SET name = ?, email = ?, phone = ? WHERE id = ?')
            ->execute([$name, $resolvedEmail, $phone, $ticketId]);
        $pdo->prepare('UPDATE event_attendance SET name = ?, email = ?, phone = ? WHERE ticket_id = ?')
            ->execute([$name, $resolvedEmail, $phone, $ticketId]);
        try {
            $pdo->prepare('UPDATE link_registrations SET name = ?, email = ?, phone = ? WHERE ticket_id = ?')
                ->execute([$name, $resolvedEmail, $phone, $ticketId]);
        } catch (Throwable) {
            // tabela pode não existir
        }
    } catch (PDOException $e) {
        return ['ok' => false, 'error' => 'Erro ao gravar: ' . $e->getMessage()];
    }

    return ['ok' => true];
}

/**
 * Atualiza contactos de uma reserva /links ainda por confirmar.
 *
 * @return array{ok:bool,error?:string}
 */
function edv_booking_update_contact(PDO $pdo, string $regId, string $name, string $email, string $phone): array
{
    $regId = trim($regId);
    $name = sanitise($name);
    $phone = sanitise($phone, 40);
    $email = edv_normalize_email($email);

    if ($regId === '' || $name === '') {
        return ['ok' => false, 'error' => 'Nome em falta.'];
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Email inválido.'];
    }

    try {
        $st = $pdo->prepare(
            "UPDATE link_registrations SET name = ?, email = ?, phone = ?, updated_at = ? WHERE id = ?"
        );
        $st->execute([$name, $email, $phone, db_now_string(), $regId]);
        if ($st->rowCount() === 0) {
            return ['ok' => false, 'error' => 'Reserva não encontrada.'];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Erro ao gravar: ' . $e->getMessage()];
    }

    return ['ok' => true];
}

/**
 * Cria um bilhete manual (walk-in ou facilitador·a). Facilitadores e valores 0
 * ficam payment_status 'free'; com valor ficam 'paid' (pagamento à porta).
 *
 * @return array{ok:bool,ticket_id?:string,error?:string}
 */
function edv_participant_create(
    PDO $pdo,
    int $eventId,
    string $name,
    string $email,
    string $phone,
    string $role = 'participant',
    float $amountEur = 0.0,
    bool $markPresent = false
): array {
    edv_attendance_ensure_schema($pdo);
    edv_tickets_ensure_role_column($pdo);

    $name = sanitise($name);
    $phone = sanitise($phone, 40);
    $email = edv_normalize_email($email);
    $role = array_key_exists($role, EDV_TICKET_ROLES) ? $role : 'participant';
    $amountEur = max(0.0, round($amountEur, 2));

    if ($eventId <= 0 || $name === '') {
        return ['ok' => false, 'error' => 'Nome em falta.'];
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Email inválido.'];
    }

    $resolvedEmail = $email !== '' ? $email : edv_presence_email_resolve(null, $phone, $name);
    $status = ($role === 'facilitator' || $amountEur <= 0.0) ? 'free' : 'paid';
    $ticketId = generate_uuid();
    $now = db_now_string();

    try {
        $pdo->prepare(
            "INSERT INTO tickets
             (id, event_id, name, email, phone, amount_paid, price_tier, role, payment_status, paid_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'standard', ?, ?, ?, ?)"
        )->execute([
            $ticketId,
            $eventId,
            $name,
            $resolvedEmail,
            $phone,
            $amountEur,
            $role,
            $status,
            $status === 'paid' ? $now : null,
            $now,
        ]);
    } catch (PDOException $e) {
        return ['ok' => false, 'error' => 'Erro ao criar bilhete: ' . $e->getMessage()];
    }

    if ($markPresent) {
        edv_attendance_sync_for_ticket($ticketId, true);
    }

    return ['ok' => true, 'ticket_id' => $ticketId];
}

/**
 * Telemóvel → chatId WhatsApp (sufixo @c.us). Números PT de 9 dígitos
 * recebem indicativo 351. Devolve null se não for utilizável.
 */
function edv_wa_chat_id(string $phone): ?string
{
    $d = preg_replace('/\D+/', '', $phone) ?? '';
    if ($d === '') {
        return null;
    }
    if (strlen($d) === 9) {
        $d = '351' . $d;
    }
    if (strlen($d) < 10 || strlen($d) > 15) {
        return null;
    }

    return $d . '@c.us';
}

/** Dia da semana em português (para a mensagem). */
function edv_pt_weekday(string $date): string
{
    $map = [1 => 'segunda', 2 => 'terça', 3 => 'quarta', 4 => 'quinta', 5 => 'sexta', 6 => 'sábado', 7 => 'domingo'];
    $n = (int) date('N', strtotime($date));

    return $map[$n] ?? '';
}

/**
 * Mensagem WhatsApp base para uma pessoa — editável no admin antes do envio.
 * Todos os factos do evento vêm da BD (título, data, horas, local).
 *
 * @param array<string,mixed> $p     linha de edv_participants_list_for_event
 * @param array<string,mixed> $event linha de events
 */
function edv_participant_wa_message(array $p, array $event): string
{
    $first = trim((string) (explode(' ', trim((string) $p['name']))[0] ?? ''));
    $title = trim((string) ($event['title'] ?? 'Ecstatic Dance Viseu'));
    $date = (string) ($event['date'] ?? '');
    $when = $date !== ''
        ? edv_pt_weekday($date) . ', ' . date('d/m', strtotime($date))
        : '';

    $hm = static fn (?string $t): string => $t !== null && trim((string) $t) !== ''
        ? substr(trim((string) $t), 0, 5) : '';
    $start = $hm($event['time_start'] ?? null);
    $end = $hm($event['time_end'] ?? null);
    $doors = $hm($event['doors_open'] ?? null);
    $times = $start !== '' && $end !== '' ? $start . '–' . $end : $start;
    if ($doors !== '') {
        $times .= ($times !== '' ? ' ' : '') . '(portas ' . $doors . ')';
    }
    $location = trim((string) ($event['location'] ?? ''));

    if (($p['role'] ?? '') === 'facilitator') {
        $opening = 'Obrigado por facilitares esta edição connosco 🙏 Ficam aqui os detalhes:';
    } elseif (($p['payment_status'] ?? '') === 'pending') {
        $ref = trim((string) ($p['payment_ref'] ?? ''));
        $opening = 'Recebemos a tua reserva' . ($ref !== '' ? ' (ref ' . $ref . ')' : '')
            . ' — falta só o comprovativo do pagamento para confirmarmos o teu bilhete.';
    } else {
        $opening = 'O teu bilhete está confirmado ✅';
    }

    $lines = [
        'Olá' . ($first !== '' ? ' ' . $first : '') . '!',
        '',
        $opening,
        '',
        '✦ ' . $title,
    ];
    if ($when !== '') {
        $lines[] = '📅 ' . ucfirst($when);
    }
    if ($times !== '') {
        $lines[] = '🕓 ' . $times;
    }
    if ($location !== '') {
        $lines[] = '📍 ' . $location;
    }
    $lines[] = '';
    $lines[] = 'Vem com roupa confortável e traz garrafa de água. Dançamos descalços, sóbrios, presentes.';
    $lines[] = '';
    $lines[] = 'Qualquer coisa, responde por aqui. Até já 🌀';

    return implode("\n", $lines);
}
