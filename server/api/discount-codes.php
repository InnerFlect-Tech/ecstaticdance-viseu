<?php
/**
 * Códigos de desconto — geração, validação e registo de utilizações.
 */
declare(strict_types=1);

require_once __DIR__ . '/attendance.php';

function edv_discount_codes_ensure_schema(PDO $pdo): void
{
    edv_attendance_ensure_schema($pdo);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS discount_campaigns (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL,
  label TEXT DEFAULT NULL,
  min_eur REAL NOT NULL,
  status TEXT NOT NULL DEFAULT 'draft',
  recipient_count INTEGER NOT NULL DEFAULT 0,
  codes_generated INTEGER NOT NULL DEFAULT 0,
  emails_sent INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT ON UPDATE CASCADE
);
SQL
        );
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS discount_codes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  campaign_id INTEGER DEFAULT NULL,
  event_id INTEGER NOT NULL,
  code TEXT NOT NULL,
  min_eur REAL NOT NULL,
  email TEXT DEFAULT NULL,
  name TEXT DEFAULT NULL,
  max_uses INTEGER NOT NULL DEFAULT 1,
  use_count INTEGER NOT NULL DEFAULT 0,
  valid_until TEXT DEFAULT NULL,
  is_active INTEGER NOT NULL DEFAULT 1,
  sent_at TEXT DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (code),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  FOREIGN KEY (campaign_id) REFERENCES discount_campaigns(id) ON DELETE SET NULL ON UPDATE CASCADE
);
SQL
        );
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS discount_code_uses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  discount_code_id INTEGER NOT NULL,
  ticket_id TEXT NOT NULL,
  email TEXT NOT NULL,
  amount_paid REAL NOT NULL,
  used_at TEXT NOT NULL,
  FOREIGN KEY (discount_code_id) REFERENCES discount_codes(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE ON UPDATE CASCADE
);
SQL
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_discount_codes_event ON discount_codes (event_id);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_discount_codes_email ON discount_codes (email);');
        edv_discount_codes_ensure_ticket_columns_sqlite($pdo);

        return;
    }

    if ($driver === 'mysql') {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `discount_campaigns` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` INT UNSIGNED NOT NULL,
  `label` VARCHAR(255) DEFAULT NULL,
  `min_eur` DECIMAL(8,2) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
  `recipient_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `codes_generated` INT UNSIGNED NOT NULL DEFAULT 0,
  `emails_sent` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_campaign_event` (`event_id`),
  CONSTRAINT `fk_campaign_event`
    FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `discount_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` BIGINT UNSIGNED DEFAULT NULL,
  `event_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(32) NOT NULL,
  `min_eur` DECIMAL(8,2) NOT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `name` VARCHAR(255) DEFAULT NULL,
  `max_uses` INT UNSIGNED NOT NULL DEFAULT 1,
  `use_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `valid_until` DATE DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sent_at` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_discount_code` (`code`),
  KEY `idx_discount_event_email` (`event_id`, `email`),
  CONSTRAINT `fk_discount_event`
    FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_discount_campaign`
    FOREIGN KEY (`campaign_id`) REFERENCES `discount_campaigns` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `discount_code_uses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `discount_code_id` BIGINT UNSIGNED NOT NULL,
  `ticket_id` CHAR(36) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `amount_paid` DECIMAL(8,2) NOT NULL,
  `used_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_code_use_code` (`discount_code_id`),
  KEY `idx_code_use_ticket` (`ticket_id`),
  CONSTRAINT `fk_code_use_code`
    FOREIGN KEY (`discount_code_id`) REFERENCES `discount_codes` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_code_use_ticket`
    FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );
        edv_discount_codes_ensure_ticket_columns_mysql($pdo);

        return;
    }

    if ($driver === 'pgsql') {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS discount_campaigns (
  id BIGSERIAL PRIMARY KEY,
  event_id INT NOT NULL REFERENCES events(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  label VARCHAR(255) DEFAULT NULL,
  min_eur NUMERIC(8,2) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  recipient_count INT NOT NULL DEFAULT 0,
  codes_generated INT NOT NULL DEFAULT 0,
  emails_sent INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL
        );
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS discount_codes (
  id BIGSERIAL PRIMARY KEY,
  campaign_id BIGINT REFERENCES discount_campaigns(id) ON DELETE SET NULL ON UPDATE CASCADE,
  event_id INT NOT NULL REFERENCES events(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  code VARCHAR(32) NOT NULL UNIQUE,
  min_eur NUMERIC(8,2) NOT NULL,
  email VARCHAR(255) DEFAULT NULL,
  name VARCHAR(255) DEFAULT NULL,
  max_uses INT NOT NULL DEFAULT 1,
  use_count INT NOT NULL DEFAULT 0,
  valid_until DATE DEFAULT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  sent_at TIMESTAMP DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL
        );
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS discount_code_uses (
  id BIGSERIAL PRIMARY KEY,
  discount_code_id BIGINT NOT NULL REFERENCES discount_codes(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  ticket_id CHAR(36) NOT NULL REFERENCES tickets(id) ON DELETE CASCADE ON UPDATE CASCADE,
  email VARCHAR(255) NOT NULL,
  amount_paid NUMERIC(8,2) NOT NULL,
  used_at TIMESTAMP NOT NULL
);
SQL
        );
        $pdo->exec('ALTER TABLE tickets ADD COLUMN IF NOT EXISTS promo_code VARCHAR(32)');
    }
}

function edv_discount_codes_ensure_ticket_columns_sqlite(PDO $pdo): void
{
    $cols = $pdo->query('PRAGMA table_info(tickets)')->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($cols, 'name');
    if (!in_array('promo_code', $names, true)) {
        $pdo->exec('ALTER TABLE tickets ADD COLUMN promo_code TEXT');
    }
    $lcols = $pdo->query('PRAGMA table_info(link_registrations)')->fetchAll(PDO::FETCH_ASSOC);
    $lnames = array_column($lcols, 'name');
    if (!in_array('promo_code', $lnames, true)) {
        try {
            $pdo->exec('ALTER TABLE link_registrations ADD COLUMN promo_code TEXT');
        } catch (PDOException) {
            // tabela pode não existir no main DB
        }
    }
}

function edv_discount_codes_ensure_ticket_columns_mysql(PDO $pdo): void
{
    try {
        $chk = $pdo->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'promo_code'"
        );
        if ($chk && !$chk->fetchColumn()) {
            $pdo->exec(
                "ALTER TABLE `tickets` ADD COLUMN `promo_code` VARCHAR(32) NULL DEFAULT NULL AFTER `price_tier`"
            );
        }
    } catch (PDOException) {
        // ignore
    }
}

function edv_normalize_promo_code(string $code): string
{
    $code = strtoupper(trim($code));
    $code = preg_replace('/[^A-Z0-9\-]/', '', $code) ?? '';

    return $code;
}

function edv_generate_promo_code(PDO $pdo): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $suffix = '';
        for ($i = 0; $i < 6; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $code = 'EDV-' . $suffix;
        $q = $pdo->prepare('SELECT 1 FROM discount_codes WHERE code = ? LIMIT 1');
        $q->execute([$code]);
        if (!$q->fetchColumn()) {
            return $code;
        }
    }

    return 'EDV-' . strtoupper(substr(generate_uuid(), 0, 8));
}

/**
 * @return array<string,mixed>|null
 */
function edv_lookup_discount_code(string $code, ?int $eventId = null, ?string $email = null): ?array
{
    $code = edv_normalize_promo_code($code);
    if ($code === '') {
        return null;
    }

    $pdo = db();
    edv_discount_codes_ensure_schema($pdo);

    $stmt = $pdo->prepare(
        'SELECT dc.*, e.date AS event_date, e.title AS event_title, e.is_active AS event_is_active
         FROM discount_codes dc
         INNER JOIN events e ON e.id = dc.event_id
         WHERE dc.code = ? AND dc.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    $resolvedEventId = edv_resolve_event_id_for_pricing($eventId);
    if ($resolvedEventId !== null && (int) $row['event_id'] !== $resolvedEventId) {
        return null;
    }

    if (!empty($row['valid_until'])) {
        $tz = new DateTimeZone('Europe/Lisbon');
        $now = new DateTime('now', $tz);
        $end = DateTime::createFromFormat('Y-m-d H:i:s', (string) $row['valid_until'] . ' 23:59:59', $tz);
        if ($end !== false && $now > $end) {
            return null;
        }
    }

    if ((int) $row['max_uses'] > 0 && (int) $row['use_count'] >= (int) $row['max_uses']) {
        return null;
    }

    $boundEmail = edv_normalize_email((string) ($row['email'] ?? ''));
    if ($boundEmail !== '') {
        $buyerEmail = edv_normalize_email((string) ($email ?? ''));
        if ($buyerEmail === '' || $buyerEmail !== $boundEmail) {
            return null;
        }
    }

    return $row;
}

/**
 * Motivo de exclusão de um email da lista de campanha, ou null se for elegível (antes de «já tem código»).
 */
function edv_discount_recipient_skip_reason(string $email): ?string
{
    $email = edv_normalize_email($email);
    if ($email === '') {
        return 'email vazio';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'email inválido';
    }
    if (edv_is_placeholder_presence_email($email)) {
        return 'presença sem email real';
    }

    return null;
}

/**
 * @return array{recipients:list<array{email:string,name:string,last_event_date:string}>,excluded:list<array{email:string,name:string,reason:string}>}
 */
function edv_discount_recipient_analysis_for_event(int $eventId): array
{
    $pdo = db();
    edv_discount_codes_ensure_schema($pdo);

    $ev = $pdo->prepare('SELECT date FROM events WHERE id = ?');
    $ev->execute([$eventId]);
    $eventDate = $ev->fetchColumn();
    if ($eventDate === false) {
        return ['recipients' => [], 'excluded' => []];
    }

    $stmt = $pdo->prepare(
        'SELECT ea.email, MAX(ea.name) AS name, MAX(e.date) AS last_event_date
         FROM event_attendance ea
         INNER JOIN events e ON e.id = ea.event_id
         WHERE e.date < ?
           AND ea.email NOT LIKE ?
         GROUP BY ea.email
         ORDER BY name ASC, ea.email ASC'
    );
    $stmt->execute([(string) $eventDate, '%@presenca.ecstaticdanceviseu.pt']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $existing = $pdo->prepare(
        'SELECT email FROM discount_codes WHERE event_id = ? AND email IS NOT NULL AND email != \'\''
    );
    $existing->execute([$eventId]);
    $hasCode = [];
    foreach ($existing->fetchAll(PDO::FETCH_COLUMN) as $em) {
        $hasCode[edv_normalize_email((string) $em)] = true;
    }

    $recipients = [];
    $excluded = [];
    foreach ($rows as $row) {
        $rawEmail = (string) ($row['email'] ?? '');
        $email = edv_normalize_email($rawEmail);
        $name = trim((string) ($row['name'] ?? ''));
        $skip = edv_discount_recipient_skip_reason($rawEmail);
        if ($skip !== null) {
            $excluded[] = ['email' => $rawEmail, 'name' => $name, 'reason' => $skip];
            continue;
        }
        if (isset($hasCode[$email])) {
            $excluded[] = ['email' => $email, 'name' => $name, 'reason' => 'já tem código para este evento'];
            continue;
        }
        $recipients[] = [
            'email'           => $email,
            'name'            => $name,
            'last_event_date' => (string) ($row['last_event_date'] ?? ''),
        ];
    }

    return ['recipients' => $recipients, 'excluded' => $excluded];
}

/**
 * @return list<array{email:string,name:string,last_event_date:string}>
 */
function edv_discount_recipients_for_event(int $eventId): array
{
    return edv_discount_recipient_analysis_for_event($eventId)['recipients'];
}

/**
 * Normaliza e deduplica destinatários vindos do formulário (JSON).
 *
 * @param list<mixed> $raw
 * @return array{recipients:list<array{email:string,name:string}>,skipped:list<array{email:string,reason:string}>}
 */
function edv_discount_normalize_recipient_list(array $raw): array
{
    $recipients = [];
    $skipped = [];
    $seen = [];

    foreach ($raw as $r) {
        if (!is_array($r)) {
            continue;
        }
        $rawEmail = (string) ($r['email'] ?? '');
        $email = edv_normalize_email($rawEmail);
        $name = trim((string) ($r['name'] ?? ''));
        $skip = edv_discount_recipient_skip_reason($rawEmail);
        if ($skip !== null) {
            $skipped[] = ['email' => $rawEmail !== '' ? $rawEmail : '(vazio)', 'reason' => $skip];
            continue;
        }
        if (isset($seen[$email])) {
            $skipped[] = ['email' => $email, 'reason' => 'email duplicado na lista'];
            continue;
        }
        $seen[$email] = true;
        $recipients[] = ['email' => $email, 'name' => $name];
    }

    return ['recipients' => $recipients, 'skipped' => $skipped];
}

/**
 * @return list<mixed>
 */
function edv_discount_decode_recipients_json(string $encoded): array
{
    $encoded = trim($encoded);
    if ($encoded === '') {
        return [];
    }
    if (str_starts_with($encoded, 'b64:')) {
        $decoded = base64_decode(substr($encoded, 4), true);
        if ($decoded === false) {
            return [];
        }
        $encoded = $decoded;
    }
    $data = json_decode($encoded, true);

    return is_array($data) ? $data : [];
}

/**
 * Apaga campanha e todos os códigos associados (se nenhum foi utilizado).
 *
 * @return array{ok:bool,message:string}
 */
function edv_delete_discount_campaign(PDO $pdo, int $campaignId): array
{
    if ($campaignId <= 0) {
        return ['ok' => false, 'message' => 'Campanha inválida.'];
    }

    $camp = $pdo->prepare('SELECT id FROM discount_campaigns WHERE id = ?');
    $camp->execute([$campaignId]);
    if (!$camp->fetchColumn()) {
        return ['ok' => false, 'message' => 'Campanha não encontrada.'];
    }

    $uses = $pdo->prepare(
        'SELECT COUNT(*)
         FROM discount_code_uses u
         INNER JOIN discount_codes dc ON dc.id = u.discount_code_id
         WHERE dc.campaign_id = ?'
    );
    $uses->execute([$campaignId]);
    if ((int) $uses->fetchColumn() > 0) {
        return ['ok' => false, 'message' => 'Não é possível apagar: já há códigos utilizados nesta campanha.'];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM discount_codes WHERE campaign_id = ?')->execute([$campaignId]);
        $pdo->prepare('DELETE FROM discount_campaigns WHERE id = ?')->execute([$campaignId]);
        $pdo->commit();

        return ['ok' => true, 'message' => 'Campanha apagada. Podes preparar a lista de novo.'];
    } catch (Throwable $e) {
        $pdo->rollBack();

        return ['ok' => false, 'message' => 'Erro ao apagar campanha: ' . $e->getMessage()];
    }
}

function edv_record_discount_code_use(string $code, string $ticketId, string $email, float $amountPaid): void
{
    $code = edv_normalize_promo_code($code);
    if ($code === '' || $ticketId === '') {
        return;
    }

    $pdo = db();
    edv_discount_codes_ensure_schema($pdo);

    $q = $pdo->prepare('SELECT id, use_count, max_uses FROM discount_codes WHERE code = ? LIMIT 1');
    $q->execute([$code]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return;
    }

    $codeId = (int) $row['id'];
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $now = db_now_string();

    if ($driver === 'sqlite') {
        $pdo->prepare(
            'INSERT OR IGNORE INTO discount_code_uses
             (discount_code_id, ticket_id, email, amount_paid, used_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$codeId, $ticketId, edv_normalize_email($email), $amountPaid, $now]);
    } else {
        try {
            $pdo->prepare(
                'INSERT INTO discount_code_uses
                 (discount_code_id, ticket_id, email, amount_paid, used_at)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$codeId, $ticketId, edv_normalize_email($email), $amountPaid, $now]);
        } catch (PDOException) {
            // duplicate use for same ticket — ignore
        }
    }

    $pdo->prepare(
        'UPDATE discount_codes SET use_count = use_count + 1 WHERE id = ? AND use_count < max_uses'
    )->execute([$codeId]);
}

function edv_send_discount_code_email(array $codeRow, array $eventRow): bool
{
    require_once __DIR__ . '/link-mail.php';

    $email = trim((string) ($codeRow['email'] ?? ''));
    $name = trim((string) ($codeRow['name'] ?? ''));
    $code = trim((string) ($codeRow['code'] ?? ''));
    $min = (float) ($codeRow['min_eur'] ?? 0);
    if ($email === '' || $code === '') {
        return false;
    }

    $firstName = $name !== '' ? explode(' ', $name)[0] : 'dançarino·a';
    $eventTitle = (string) ($eventRow['title'] ?? 'Ecstatic Dance Viseu');
    $eventDate = (string) ($eventRow['date'] ?? '');
    $dateFmt = $eventDate !== '' ? date('j \d\e F \d\e Y', strtotime($eventDate)) : '';
    $appUrl = defined('APP_URL') && is_string(APP_URL) ? APP_URL : 'https://ecstaticdanceviseu.pt';
    $buyUrl = rtrim($appUrl, '/') . '/bilhetes';

    $subject = 'Faz parte da comunidade — o teu código para ' . $eventTitle;

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="pt">
<head><meta charset="UTF-8"><title><?= htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') ?></title></head>
<body style="margin:0;padding:0;background:#0E0B09;font-family:Georgia,serif;color:#F5EFE6;">
  <div style="max-width:560px;margin:0 auto;padding:32px 24px;">
    <p style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:rgba(245,239,230,.45);margin:0 0 12px;">Ecstatic Dance Viseu</p>
    <h1 style="font-weight:300;font-size:28px;line-height:1.25;margin:0 0 18px;">Olá, <?= htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') ?></h1>
    <p style="font-size:16px;line-height:1.65;color:rgba(245,239,230,.88);margin:0 0 16px;">
      Já dançaste connosco — fazes parte desta comunidade. Para a próxima edição
      <?php if ($dateFmt !== ''): ?>
        (<strong><?= htmlspecialchars($dateFmt, ENT_QUOTES, 'UTF-8') ?></strong>)
      <?php endif; ?>
      preparamos um código especial para ti.
    </p>
    <div style="background:rgba(212,168,90,.12);border:1px solid rgba(212,168,90,.35);border-radius:12px;padding:20px 22px;margin:24px 0;text-align:center;">
      <p style="margin:0 0 8px;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:rgba(245,239,230,.5);">O teu código</p>
      <p style="margin:0;font-size:28px;letter-spacing:.18em;font-family:monospace;color:#D4A85A;"><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?></p>
      <p style="margin:12px 0 0;font-size:14px;color:rgba(245,239,230,.75);">Sliding scale a partir de <strong><?= number_format($min, 0, ',', ' ') ?>€</strong></p>
    </div>
    <p style="font-size:15px;line-height:1.6;color:rgba(245,239,230,.82);margin:0 0 22px;">
      Usa este código ao reservar bilhete (online ou em /links). Introduz-o no campo «Código de desconto» com o mesmo email —
      <strong><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></strong>.
    </p>
    <p style="text-align:center;margin:28px 0;">
      <a href="<?= htmlspecialchars($buyUrl, ENT_QUOTES, 'UTF-8') ?>"
         style="display:inline-block;background:#D4A85A;color:#0E0B09;text-decoration:none;padding:14px 28px;border-radius:999px;font-size:14px;letter-spacing:.08em;text-transform:uppercase;">
        Reservar bilhete
      </a>
    </p>
    <p style="font-size:13px;line-height:1.55;color:rgba(245,239,230,.45);margin:24px 0 0;">
      Com gratidão,<br>Ecstatic Dance Viseu
    </p>
  </div>
</body>
</html>
    <?php
    $html = (string) ob_get_clean();

    return link_send_customer_html($email, $subject, $html);
}
