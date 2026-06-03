<?php
/**
 * Presenças (quem veio) e preço «dançarino·a de regresso».
 *
 * Fonte de verdade: event_attendance (preenchida no check-in).
 * tickets.checked_in mantém-se para o scanner; a lista por evento usa event_attendance.
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** Piso global para quem já dançou numa edição anterior (se o evento não definir outro). */
const EDV_RETURNING_MIN_EUR_DEFAULT = 15.0;
const EDV_EARLY_BIRD_MIN_EUR_DEFAULT = 20.0;
const EDV_STANDARD_MIN_EUR_DEFAULT = 30.0;

function edv_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

/**
 * Cria/atualiza tabela event_attendance (SQLite + MySQL).
 */
function edv_attendance_ensure_schema(PDO $pdo): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS event_attendance (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL,
  ticket_id TEXT NOT NULL,
  email TEXT NOT NULL,
  name TEXT NOT NULL,
  phone TEXT NOT NULL,
  amount_paid REAL NOT NULL DEFAULT 0,
  checked_in_at TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (event_id, email),
  UNIQUE (ticket_id),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE ON UPDATE CASCADE
);
SQL
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attendance_event ON event_attendance (event_id);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attendance_email ON event_attendance (email);');
        edv_events_ensure_returning_column_sqlite($pdo);
        edv_events_ensure_early_bird_columns_sqlite($pdo);

        return;
    }

    if ($driver === 'mysql') {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `event_attendance` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id`      INT UNSIGNED    NOT NULL,
  `ticket_id`     CHAR(36)        NOT NULL,
  `email`         VARCHAR(255)    NOT NULL,
  `name`          VARCHAR(255)    NOT NULL,
  `phone`         VARCHAR(40)     NOT NULL,
  `amount_paid`   DECIMAL(8,2)    NOT NULL DEFAULT 0.00,
  `checked_in_at` DATETIME        NOT NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_event_email` (`event_id`, `email`),
  UNIQUE KEY `uq_attendance_ticket` (`ticket_id`),
  KEY `idx_attendance_email` (`email`),
  CONSTRAINT `fk_attendance_event`
    FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_attendance_ticket`
    FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );
        edv_events_ensure_returning_column_mysql($pdo);
        edv_events_ensure_early_bird_columns_mysql($pdo);

        return;
    }

    if ($driver === 'pgsql') {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS event_attendance (
  id BIGSERIAL PRIMARY KEY,
  event_id INT NOT NULL,
  ticket_id CHAR(36) NOT NULL,
  email VARCHAR(255) NOT NULL,
  name VARCHAR(255) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  amount_paid NUMERIC(8,2) NOT NULL DEFAULT 0,
  checked_in_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (event_id, email),
  UNIQUE (ticket_id)
);
SQL
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attendance_event ON event_attendance (event_id);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attendance_email ON event_attendance (email);');
        $pdo->exec('ALTER TABLE events ADD COLUMN IF NOT EXISTS returning_min_eur NUMERIC(8,2)');
        $pdo->exec('ALTER TABLE events ADD COLUMN IF NOT EXISTS early_bird_min_eur NUMERIC(8,2)');
        $pdo->exec('ALTER TABLE events ADD COLUMN IF NOT EXISTS early_bird_until DATE');
    }
}

function edv_events_ensure_returning_column_sqlite(PDO $pdo): void
{
    $cols = $pdo->query('PRAGMA table_info(events)')->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($cols, 'name');
    if (!in_array('returning_min_eur', $names, true)) {
        $pdo->exec('ALTER TABLE events ADD COLUMN returning_min_eur REAL');
    }
    $tcols = $pdo->query('PRAGMA table_info(tickets)')->fetchAll(PDO::FETCH_ASSOC);
    $tnames = array_column($tcols, 'name');
    if (!in_array('price_tier', $tnames, true)) {
        $pdo->exec("ALTER TABLE tickets ADD COLUMN price_tier TEXT NOT NULL DEFAULT 'standard'");
    }
}

function edv_events_ensure_returning_column_mysql(PDO $pdo): void
{
    try {
        $chk = $pdo->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'returning_min_eur'"
        );
        if ($chk && !$chk->fetchColumn()) {
            $pdo->exec(
                'ALTER TABLE `events` ADD COLUMN `returning_min_eur` DECIMAL(8,2) NULL DEFAULT NULL AFTER `min_price`'
            );
        }
    } catch (PDOException) {
        // coluna já existe
    }
    try {
        $pdo->exec(
            "ALTER TABLE `tickets` ADD COLUMN `price_tier` VARCHAR(32) NOT NULL DEFAULT 'standard' AFTER `amount_paid`"
        );
    } catch (PDOException) {
        // já existe
    }
}

function edv_events_ensure_early_bird_columns_sqlite(PDO $pdo): void
{
    $cols = $pdo->query('PRAGMA table_info(events)')->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($cols, 'name');
    if (!in_array('early_bird_min_eur', $names, true)) {
        $pdo->exec('ALTER TABLE events ADD COLUMN early_bird_min_eur REAL');
    }
    if (!in_array('early_bird_until', $names, true)) {
        $pdo->exec('ALTER TABLE events ADD COLUMN early_bird_until TEXT');
    }
}

function edv_events_ensure_early_bird_columns_mysql(PDO $pdo): void
{
    try {
        $chk = $pdo->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'early_bird_min_eur'"
        );
        if ($chk && !$chk->fetchColumn()) {
            $pdo->exec(
                'ALTER TABLE `events` ADD COLUMN `early_bird_min_eur` DECIMAL(8,2) NULL DEFAULT NULL AFTER `returning_min_eur`'
            );
        }
    } catch (PDOException) {
        // coluna já existe
    }
    try {
        $chk = $pdo->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'early_bird_until'"
        );
        if ($chk && !$chk->fetchColumn()) {
            $pdo->exec(
                'ALTER TABLE `events` ADD COLUMN `early_bird_until` DATE NULL DEFAULT NULL AFTER `early_bird_min_eur`'
            );
        }
    } catch (PDOException) {
        // coluna já existe
    }
}

/**
 * Regista ou remove presença conforme check-in do bilhete.
 */
function edv_attendance_sync_for_ticket(string $ticketId, bool $checkedIn): void
{
    $ticketId = trim($ticketId);
    if ($ticketId === '') {
        return;
    }

    $pdo = db();
    edv_attendance_ensure_schema($pdo);

    if (!$checkedIn) {
        $pdo->prepare('DELETE FROM event_attendance WHERE ticket_id = ?')->execute([$ticketId]);

        return;
    }

    $q = $pdo->prepare(
        'SELECT t.id, t.event_id, t.name, t.email, t.phone, t.amount_paid, t.checked_in_at, t.payment_status
         FROM tickets t
         WHERE t.id = ?'
    );
    $q->execute([$ticketId]);
    $t = $q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($t) || !in_array((string) ($t['payment_status'] ?? ''), ['paid', 'free'], true)) {
        return;
    }

    $checkedAt = (string) ($t['checked_in_at'] ?? '');
    if ($checkedAt === '') {
        $checkedAt = db_now_string();
        $pdo->prepare('UPDATE tickets SET checked_in = 1, checked_in_at = ? WHERE id = ?')
            ->execute([$checkedAt, $ticketId]);
    }

    $email = edv_normalize_email((string) $t['email']);
    if ($email === '') {
        return;
    }

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->prepare(
            'INSERT INTO event_attendance
             (event_id, ticket_id, email, name, phone, amount_paid, checked_in_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(event_id, email) DO UPDATE SET
               ticket_id = excluded.ticket_id,
               name = excluded.name,
               phone = excluded.phone,
               amount_paid = excluded.amount_paid,
               checked_in_at = excluded.checked_in_at'
        )->execute([
            (int) $t['event_id'],
            $ticketId,
            $email,
            (string) $t['name'],
            (string) $t['phone'],
            (float) $t['amount_paid'],
            $checkedAt,
            $checkedAt,
        ]);
    } else {
        $pdo->prepare(
            'INSERT INTO event_attendance
             (event_id, ticket_id, email, name, phone, amount_paid, checked_in_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               ticket_id = VALUES(ticket_id),
               name = VALUES(name),
               phone = VALUES(phone),
               amount_paid = VALUES(amount_paid),
               checked_in_at = VALUES(checked_in_at)'
        )->execute([
            (int) $t['event_id'],
            $ticketId,
            $email,
            (string) $t['name'],
            (string) $t['phone'],
            (float) $t['amount_paid'],
            $checkedAt,
        ]);
    }
}

/**
 * Já esteve presente numa edição anterior à data do evento em compra?
 */
function edv_is_returning_dancer(string $email, ?int $forEventId = null, ?string $phone = null): bool
{
    $norm = edv_normalize_email($email);
    $hasEmail = $norm !== '' && filter_var($norm, FILTER_VALIDATE_EMAIL);
    $digits = edv_normalize_phone_digits((string) $phone);

    if (!$hasEmail && $digits === '') {
        return false;
    }

    $pdo = db();
    edv_attendance_ensure_schema($pdo);

    $eventDate = null;
    if ($forEventId !== null && $forEventId > 0) {
        $d = $pdo->prepare('SELECT date FROM events WHERE id = ?');
        $d->execute([$forEventId]);
        $eventDate = $d->fetchColumn();
    }

    if ($hasEmail && $eventDate) {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM event_attendance ea
             INNER JOIN events e ON e.id = ea.event_id
             WHERE ea.email = ?
               AND e.date < ?
             LIMIT 1'
        );
        $stmt->execute([$norm, $eventDate]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    } elseif ($hasEmail) {
        $stmt = $pdo->prepare('SELECT 1 FROM event_attendance WHERE email = ? LIMIT 1');
        $stmt->execute([$norm]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }

    if ($digits !== '') {
        $phoneSql = 'SELECT 1 FROM event_attendance ea';
        $params = [];
        if ($eventDate) {
            $phoneSql .= ' INNER JOIN events e ON e.id = ea.event_id WHERE e.date < ? AND ';
            $params[] = $eventDate;
        } else {
            $phoneSql .= ' WHERE ';
        }
        $phoneSql .= "REPLACE(REPLACE(REPLACE(ea.phone, ' ', ''), '+', ''), '-', '') LIKE ? LIMIT 1";
        $params[] = '%' . $digits;
        $ps = $pdo->prepare($phoneSql);
        $ps->execute($params);
        if ($ps->fetchColumn()) {
            return true;
        }
    }

    // Compatibilidade: bilhetes antigos só em tickets.checked_in (antes de event_attendance)
    if ($eventDate) {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM tickets t
             INNER JOIN events e ON e.id = t.event_id
             WHERE LOWER(t.email) = ?
               AND t.checked_in = 1
               AND t.payment_status IN (\'paid\', \'free\')
               AND e.date < ?
             LIMIT 1'
        );
        $stmt->execute([$norm, $eventDate]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM tickets t
             WHERE LOWER(t.email) = ?
               AND t.checked_in = 1
               AND t.payment_status IN (\'paid\', \'free\')
             LIMIT 1'
        );
        $stmt->execute([$norm]);
    }

    return (bool) $stmt->fetchColumn();
}

function edv_resolve_event_id_for_pricing(?int $eventId): ?int
{
    if ($eventId !== null && $eventId > 0) {
        return $eventId;
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT id FROM events WHERE is_active = 1 AND date >= ? ORDER BY date ASC LIMIT 1'
    );
    $stmt->execute([db_today_string()]);
    $id = $stmt->fetchColumn();

    return $id !== false ? (int) $id : null;
}

/**
 * @return array{min_price: mixed, early_bird_min_eur: mixed, early_bird_until: mixed}|null
 */
function edv_event_pricing_row(?int $eventId): ?array
{
    $resolvedId = edv_resolve_event_id_for_pricing($eventId);
    if ($resolvedId === null) {
        return null;
    }

    $pdo = db();
    edv_attendance_ensure_schema($pdo);
    $q = $pdo->prepare(
        'SELECT min_price, early_bird_min_eur, early_bird_until FROM events WHERE id = ?'
    );
    $q->execute([$resolvedId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function edv_returning_min_for_event(?int $eventId): float
{
    if ($eventId === null || $eventId <= 0) {
        return EDV_RETURNING_MIN_EUR_DEFAULT;
    }
    $pdo = db();
    edv_attendance_ensure_schema($pdo);
    $q = $pdo->prepare('SELECT returning_min_eur FROM events WHERE id = ?');
    $q->execute([$eventId]);
    $v = $q->fetchColumn();
    if ($v !== false && $v !== null && $v !== '') {
        return max(0.0, (float) $v);
    }

    return EDV_RETURNING_MIN_EUR_DEFAULT;
}

function edv_standard_min_for_event(?int $eventId): float
{
    $row = edv_event_pricing_row($eventId);
    if ($row !== null && $row['min_price'] !== null && $row['min_price'] !== '') {
        return max(0.0, (float) $row['min_price']);
    }

    return EDV_STANDARD_MIN_EUR_DEFAULT;
}

function edv_early_bird_min_for_event(?int $eventId): float
{
    $row = edv_event_pricing_row($eventId);
    if ($row !== null && $row['early_bird_min_eur'] !== null && $row['early_bird_min_eur'] !== '') {
        return max(0.0, (float) $row['early_bird_min_eur']);
    }

    return EDV_EARLY_BIRD_MIN_EUR_DEFAULT;
}

function edv_early_bird_until_for_event(?int $eventId): ?string
{
    $row = edv_event_pricing_row($eventId);
    if ($row === null || $row['early_bird_until'] === null || $row['early_bird_until'] === '') {
        return null;
    }

    return (string) $row['early_bird_until'];
}

function edv_is_early_bird_period(?DateTime $at = null, ?int $eventId = null): bool
{
    $until = edv_early_bird_until_for_event($eventId);
    if ($until === null) {
        return false;
    }

    $tz = new DateTimeZone('Europe/Lisbon');
    $now = $at ?? new DateTime('now', $tz);
    $end = DateTime::createFromFormat('Y-m-d H:i:s', $until . ' 23:59:59', $tz);
    if ($end === false) {
        return false;
    }

    return $now <= $end;
}

/**
 * @return 'discount_code'|'returning'|'early_bird'|'standard'
 */
function edv_ticket_price_tier(
    string $email,
    ?int $eventId = null,
    ?DateTime $at = null,
    ?string $phone = null,
    ?string $promoCode = null
): string {
    if ($promoCode !== null && trim($promoCode) !== '') {
        require_once __DIR__ . '/discount-codes.php';
        $codeRow = edv_lookup_discount_code($promoCode, $eventId, $email !== '' ? $email : null);
        if ($codeRow !== null) {
            return 'discount_code';
        }
    }
    if (edv_is_returning_dancer($email, $eventId, $phone)) {
        return 'returning';
    }
    if (edv_is_early_bird_period($at, $eventId)) {
        return 'early_bird';
    }

    return 'standard';
}

/**
 * Piso em euros para compra (sliding scale mínimo).
 */
function edv_ticket_min_eur(
    ?string $email = null,
    ?int $eventId = null,
    ?DateTime $at = null,
    ?string $phone = null,
    ?string $promoCode = null
): float {
    if ($promoCode !== null && trim($promoCode) !== '') {
        require_once __DIR__ . '/discount-codes.php';
        $codeRow = edv_lookup_discount_code($promoCode, $eventId, $email);
        if ($codeRow !== null) {
            return max(0.0, (float) $codeRow['min_eur']);
        }
    }

    if (($email !== null && $email !== '') || ($phone !== null && edv_normalize_phone_digits($phone) !== '')) {
        $tier = edv_ticket_price_tier($email ?? '', $eventId, $at, $phone);
        if ($tier === 'returning') {
            return edv_returning_min_for_event($eventId);
        }
    }

    return edv_is_early_bird_period($at, $eventId)
        ? edv_early_bird_min_for_event($eventId)
        : edv_standard_min_for_event($eventId);
}

/**
 * Escalão efectivo para exibição (sem email — early bird vs standard).
 */
function edv_public_price_tier(?int $eventId = null, ?DateTime $at = null, ?string $promoCode = null): string
{
    if ($promoCode !== null && trim($promoCode) !== '') {
        require_once __DIR__ . '/discount-codes.php';
        if (edv_lookup_discount_code($promoCode, $eventId) !== null) {
            return 'discount_code';
        }
    }

    return edv_is_early_bird_period($at, $eventId) ? 'early_bird' : 'standard';
}

/**
 * @return list<array<string,mixed>>
 */
function edv_attendance_list_for_event(int $eventId): array
{
    $pdo = db();
    edv_attendance_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT ea.*, e.title AS event_title, e.date AS event_date
         FROM event_attendance ea
         INNER JOIN events e ON e.id = ea.event_id
         WHERE ea.event_id = ?
         ORDER BY ea.checked_in_at ASC, ea.name ASC'
    );
    $stmt->execute([$eventId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Preenche event_attendance a partir de check-ins já gravados em tickets.
 */
function edv_attendance_backfill_from_tickets(): int
{
    $pdo = db();
    edv_attendance_ensure_schema($pdo);
    $rows = $pdo->query(
        "SELECT id FROM tickets
         WHERE checked_in = 1 AND payment_status IN ('paid', 'free')"
    )->fetchAll(PDO::FETCH_COLUMN);
    $n = 0;
    foreach ($rows as $tid) {
        edv_attendance_sync_for_ticket((string) $tid, true);
        $n++;
    }

    return $n;
}

/** Data da edição #01 (lista à porta). */
const EDV_EVENT_01_DATE = '2026-05-23';

/**
 * Lista da folha de presenças — edição #01 (23 maio 2026).
 *
 * @return list<array{name:string,amount_eur:float,present:bool,phone?:string,email?:string}>
 */
function edv_event_01_door_roster(): array
{
    return [
        [
            'name'       => 'Sofia Bernardo',
            'amount_eur' => 30.0,
            'present'    => true,
            'email'      => 'sofia_bernardo27@hotmail.com',
            'phone'      => '+351919176698',
        ],
        [
            'name'       => 'Joana Silva',
            'amount_eur' => 20.0,
            'present'    => false,
            'email'      => 'jsb.joana@gmail.com',
            'phone'      => '912949274',
        ],
        [
            'name'       => 'Catarina Cerineu',
            'amount_eur' => 30.0,
            'present'    => true,
            'email'      => 'catarinacerineu@gmail.com',
            'phone'      => '915685431',
        ],
        [
            'name'       => 'Fernando Santos',
            'amount_eur' => 20.0,
            'present'    => true,
            'email'      => 'nandosantos1@gmail.com',
            'phone'      => '916273452',
        ],
        [
            'name'       => 'Joana Dias',
            'amount_eur' => 20.0,
            'present'    => true,
            'email'      => 'joanapoliveiradias@gmail.com',
            'phone'      => '926585887',
        ],
        [
            'name'       => 'Cláudia Pina',
            'amount_eur' => 20.0,
            'present'    => true,
            'email'      => 'cvitoria250392@gmail.com',
            'phone'      => '927651255',
        ],
        [
            'name'       => 'Alesia Matusevych',
            'amount_eur' => 30.0,
            'present'    => true,
            'email'      => 'yulianovna1@gmail.com',
            'phone'      => '+351911544075',
        ],
        [
            'name'       => 'Guilherme Rolo',
            'amount_eur' => 20.0,
            'present'    => true,
            'email'      => 'guirolo21@gmail.com',
            'phone'      => '+351965413573',
        ],
        [
            'name'       => 'Ana Luísa Saraiva',
            'amount_eur' => 20.0,
            'present'    => false,
            'email'      => 'analuisasaraiva.tf@gmail.com',
            'phone'      => '965746186',
        ],
        [
            'name'       => 'William',
            'amount_eur' => 30.0,
            'present'    => true,
            'phone'      => '912775972',
        ],
        [
            'name'       => 'Marco Aurélio Mesquita Moutinho',
            'amount_eur' => 30.0,
            'present'    => true,
            'email'      => 'marquito@sapo.pt',
            'phone'      => '965142244',
        ],
        [
            'name'       => 'Leonore Davim',
            'amount_eur' => 30.0,
            'present'    => true,
            'phone'      => '919497711',
        ],
    ];
}

function edv_normalize_phone_digits(string $phone): string
{
    $d = preg_replace('/\D+/', '', $phone) ?? '';

    return strlen($d) >= 9 ? $d : '';
}

function edv_is_placeholder_presence_email(string $email): bool
{
    return str_ends_with(edv_normalize_email($email), '@presenca.ecstaticdanceviseu.pt');
}

/**
 * Email real ou marcador estável a partir do telemóvel / nome (sem email na folha).
 */
function edv_presence_email_resolve(?string $email, ?string $phone, string $name): string
{
    $email = edv_normalize_email((string) $email);
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && !edv_is_placeholder_presence_email($email)) {
        return $email;
    }

    $digits = edv_normalize_phone_digits((string) $phone);
    if ($digits !== '') {
        return 'tel+' . $digits . '@presenca.ecstaticdanceviseu.pt';
    }

    $slug = preg_replace('/[^a-z0-9]+/', '.', mb_strtolower(trim($name), 'UTF-8')) ?? 'convidado';
    $slug = trim($slug, '.') ?: 'convidado';

    return $slug . '@presenca.ecstaticdanceviseu.pt';
}

function edv_normalize_name_key(string $name): string
{
    $n = mb_strtolower(trim($name), 'UTF-8');
    $n = preg_replace('/\s+/u', ' ', $n) ?? $n;

    return $n;
}

/**
 * @return array<string,mixed>|null
 */
function edv_attendance_find_ticket_for_person(PDO $pdo, int $eventId, string $name, ?string $email = null, ?string $phone = null): ?array
{
    $nameKey = edv_normalize_name_key($name);
    $digits = edv_normalize_phone_digits((string) $phone);
    $emailNorm = edv_normalize_email((string) $email);

    if ($emailNorm !== '' && filter_var($emailNorm, FILTER_VALIDATE_EMAIL)) {
        $byEmail = $pdo->prepare(
            "SELECT * FROM tickets
             WHERE event_id = ? AND LOWER(email) = ?
               AND payment_status IN ('paid', 'free')
             ORDER BY created_at DESC LIMIT 1"
        );
        $byEmail->execute([$eventId, $emailNorm]);
        $row = $byEmail->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }
    }

    if ($digits !== '') {
        $byPhone = $pdo->prepare(
            "SELECT * FROM tickets
             WHERE event_id = ?
               AND REPLACE(REPLACE(REPLACE(phone, ' ', ''), '+', ''), '-', '') LIKE ?
               AND payment_status IN ('paid', 'free')
             ORDER BY created_at DESC LIMIT 1"
        );
        $byPhone->execute([$eventId, '%' . $digits]);
        $row = $byPhone->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }
    }

    $all = $pdo->prepare(
        "SELECT * FROM tickets
         WHERE event_id = ? AND payment_status IN ('paid', 'free')
         ORDER BY created_at DESC"
    );
    $all->execute([$eventId]);
    $eventTickets = $all->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($eventTickets as $t) {
        if (edv_normalize_name_key((string) ($t['name'] ?? '')) === $nameKey) {
            return $t;
        }
    }

    // Correspondência parcial (ex.: «William» vs nome completo)
    foreach ($eventTickets as $t) {
        $tn = edv_normalize_name_key((string) ($t['name'] ?? ''));
        if ($tn === $nameKey || str_contains($tn, $nameKey) || str_contains($nameKey, $tn)) {
            return $t;
        }
    }

    // link_registrations (reservas manuais) — mesmo nome
    try {
        $lr = $pdo->prepare(
            "SELECT lr.ticket_id, lr.name, lr.email, lr.phone, lr.ticket_euros
             FROM link_registrations lr
             WHERE lr.ticket_id IS NOT NULL
               AND LOWER(TRIM(lr.name)) = ?
             ORDER BY lr.step1_at DESC LIMIT 5"
        );
        $lr->execute([$nameKey]);
        foreach ($lr->fetchAll(PDO::FETCH_ASSOC) as $reg) {
            $tid = (string) ($reg['ticket_id'] ?? '');
            if ($tid === '') {
                continue;
            }
            $tq = $pdo->prepare('SELECT * FROM tickets WHERE id = ? AND event_id = ? LIMIT 1');
            $tq->execute([$tid, $eventId]);
            $row = $tq->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return $row;
            }
        }
    } catch (Throwable $e) {
        // tabela pode não existir
    }

    return null;
}

/**
 * Importa a folha de presenças da edição #01 (idempotente).
 *
 * @return array{event_id:int,matched:int,created:int,present:int,absent:int,skipped:int,messages:list<string>}|null
 */
function edv_attendance_import_event_01_roster(PDO $pdo): ?array
{
    edv_attendance_ensure_schema($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, date FROM events WHERE `date` = ? OR title LIKE ? ORDER BY `date` ASC LIMIT 1'
    );
    $stmt->execute([EDV_EVENT_01_DATE, '%#01%']);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($event)) {
        return null;
    }

    $eventId = (int) $event['id'];
    $checkedAt = (string) $event['date'] . ' 17:00:00';

    $stats = [
        'event_id' => $eventId,
        'matched'  => 0,
        'created'  => 0,
        'present'  => 0,
        'absent'   => 0,
        'skipped'  => 0,
        'messages' => [],
    ];

    foreach (edv_event_01_door_roster() as $person) {
        $name = trim((string) $person['name']);
        $amount = round((float) $person['amount_eur'], 2);
        $present = (bool) $person['present'];
        $phone = isset($person['phone']) ? trim((string) $person['phone']) : '';
        $hintEmail = isset($person['email']) ? trim((string) $person['email']) : '';

        $ticket = edv_attendance_find_ticket_for_person($pdo, $eventId, $name, $hintEmail, $phone);
        $resolvedEmail = edv_presence_email_resolve(
            $ticket['email'] ?? $hintEmail,
            $phone !== '' ? $phone : ($ticket['phone'] ?? ''),
            $name
        );

        if (is_array($ticket)) {
            $stats['matched']++;
            $ticketId = (string) $ticket['id'];
            $pdo->prepare(
                'UPDATE tickets
                 SET name = ?, email = ?, phone = ?, amount_paid = ?, payment_status = \'paid\',
                     price_tier = COALESCE(NULLIF(price_tier, \'\'), \'standard\')
                 WHERE id = ? AND event_id = ?'
            )->execute([
                $name,
                $resolvedEmail,
                $phone !== '' ? $phone : (string) ($ticket['phone'] ?? ''),
                $amount,
                $ticketId,
                $eventId,
            ]);
        } else {
            $stats['created']++;
            $ticketId = generate_uuid();
            $now = db_now_string();
            $pdo->prepare(
                'INSERT INTO tickets
                 (id, event_id, name, email, phone, amount_paid, price_tier, payment_status, paid_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, \'standard\', \'paid\', ?, ?)'
            )->execute([
                $ticketId,
                $eventId,
                $name,
                $resolvedEmail,
                $phone,
                $amount,
                $checkedAt,
                $now,
            ]);
            $stats['messages'][] = "Bilhete criado: {$name}";
        }

        if ($present) {
            $stats['present']++;
            $pdo->prepare('UPDATE tickets SET checked_in = 1, checked_in_at = ? WHERE id = ?')
                ->execute([$checkedAt, $ticketId]);
            edv_attendance_sync_for_ticket($ticketId, true);
        } else {
            $stats['absent']++;
            $pdo->prepare('UPDATE tickets SET checked_in = 0, checked_in_at = NULL WHERE id = ?')
                ->execute([$ticketId]);
            edv_attendance_sync_for_ticket($ticketId, false);
        }
    }

    return $stats;
}

function edv_attendance_find_event_01_id(PDO $pdo): ?int
{
    $stmt = $pdo->prepare(
        'SELECT id FROM events WHERE `date` = ? OR title LIKE ? ORDER BY `date` ASC LIMIT 1'
    );
    $stmt->execute([EDV_EVENT_01_DATE, '%#01%']);
    $id = (int) ($stmt->fetchColumn() ?: 0);

    return $id > 0 ? $id : null;
}

/**
 * Limpa bilhetes e presenças da edição #01 e reimporta a folha (edv_event_01_door_roster).
 *
 * @return array{event_id:int,matched:int,created:int,present:int,absent:int,skipped:int,messages:list<string>}|null
 */
function edv_attendance_reseed_event_01_from_roster(PDO $pdo): ?array
{
    $eventId = edv_attendance_find_event_01_id($pdo);
    if ($eventId === null) {
        return null;
    }
    edv_attendance_ensure_schema($pdo);
    $pdo->prepare('DELETE FROM event_attendance WHERE event_id = ?')->execute([$eventId]);
    $pdo->prepare('DELETE FROM tickets WHERE event_id = ?')->execute([$eventId]);

    return edv_attendance_import_event_01_roster($pdo);
}
