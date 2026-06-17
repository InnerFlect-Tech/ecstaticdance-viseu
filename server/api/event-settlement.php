<?php
/**
 * Repartição financeira por evento (modelo da folha de cálculo).
 *
 * 1. Receita (bilhetes por escalão)
 * 2. − Custos base (event_costs.cost_bucket = 'base')
 * 3. Espaço % sobre pós-custos-base
 * 4. Facilitadores / DJ / … % sobre pós-espaço (mesmo pool)
 * 5. Indias / Carolina % sobre o restante (soma 100%)
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** Custos base fixos (subtraídos antes das percentagens). */
const EDV_BASE_COST_SLUGS = [
    'transportes'  => 'Transportes',
    'flyers'       => 'Flyers',
    'comidas'      => 'Comidas',
    'promo_online' => 'Promo online',
];

/** Escalões de bilhete por defeito (€) para referência na UI. */
const EDV_DEFAULT_TICKET_TIERS = [20.0, 25.0, 30.0];

function edv_settlement_ensure_schema(PDO $pdo): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        edv_settlement_ensure_cost_columns_sqlite($pdo);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS event_settlement_shares (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL,
  role_key TEXT NOT NULL,
  label TEXT NOT NULL,
  percent REAL NOT NULL DEFAULT 0,
  pool TEXT NOT NULL DEFAULT 'post_venue',
  sort_order INTEGER NOT NULL DEFAULT 0,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (event_id, role_key),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE ON UPDATE CASCADE
);
SQL
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_settlement_shares_event ON event_settlement_shares (event_id);');
        edv_settlement_ensure_extended_schema($pdo);

        return;
    }

    if ($driver === 'mysql') {
        edv_settlement_ensure_cost_columns_mysql($pdo);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `event_settlement_shares` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id`   INT UNSIGNED    NOT NULL,
  `role_key`   VARCHAR(40)     NOT NULL,
  `label`      VARCHAR(120)    NOT NULL,
  `percent`    DECIMAL(6,2)    NOT NULL DEFAULT 0.00,
  `pool`       VARCHAR(20)     NOT NULL DEFAULT 'post_venue',
  `sort_order` SMALLINT        NOT NULL DEFAULT 0,
  `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settlement_event_role` (`event_id`, `role_key`),
  KEY `idx_settlement_event` (`event_id`),
  CONSTRAINT `fk_settlement_event`
    FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );
        edv_settlement_ensure_extended_schema($pdo);

        return;
    }

    if ($driver === 'pgsql') {
        edv_settlement_ensure_cost_columns_pgsql($pdo);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS event_settlement_shares (
  id BIGSERIAL PRIMARY KEY,
  event_id INT NOT NULL REFERENCES events(id) ON DELETE CASCADE ON UPDATE CASCADE,
  role_key VARCHAR(40) NOT NULL,
  label VARCHAR(120) NOT NULL,
  percent NUMERIC(6,2) NOT NULL DEFAULT 0,
  pool VARCHAR(20) NOT NULL DEFAULT 'post_venue',
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (event_id, role_key)
);
SQL
        );
        edv_settlement_ensure_extended_schema($pdo);
    }
}

/** Data da edição #01 (contas fechadas à mão). Definida também em attendance.php — guarda contra redefinição quando ambos são carregados. */
if (!defined('EDV_EVENT_01_DATE')) {
    define('EDV_EVENT_01_DATE', '2026-05-23');
}

function edv_settlement_ensure_extended_schema(PDO $pdo): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $ecols = array_column($pdo->query('PRAGMA table_info(events)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('settlement_profile', $ecols, true)) {
            $pdo->exec("ALTER TABLE events ADD COLUMN settlement_profile TEXT NOT NULL DEFAULT 'standard'");
        }
        $scols = array_column($pdo->query('PRAGMA table_info(event_settlement_shares)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('amount_fixed_eur', $scols, true)) {
            $pdo->exec('ALTER TABLE event_settlement_shares ADD COLUMN amount_fixed_eur REAL');
        }
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS event_revenue_tiers_manual (
  event_id INTEGER NOT NULL,
  price_eur REAL NOT NULL,
  quantity INTEGER NOT NULL DEFAULT 0,
  notes TEXT,
  PRIMARY KEY (event_id, price_eur),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE ON UPDATE CASCADE
);
SQL
        );

        return;
    }

    if ($driver === 'mysql') {
        $q = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'events\' AND COLUMN_NAME = \'settlement_profile\''
        );
        $q->execute();
        if ((int) $q->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `events` ADD COLUMN `settlement_profile` VARCHAR(20) NOT NULL DEFAULT 'standard'");
        }
        $q2 = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'event_settlement_shares\' AND COLUMN_NAME = \'amount_fixed_eur\''
        );
        $q2->execute();
        if ((int) $q2->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE `event_settlement_shares` ADD COLUMN `amount_fixed_eur` DECIMAL(10,2) NULL DEFAULT NULL AFTER `percent`');
        }
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `event_revenue_tiers_manual` (
  `event_id`   INT UNSIGNED    NOT NULL,
  `price_eur`  DECIMAL(8,2)    NOT NULL,
  `quantity`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `notes`      VARCHAR(255)    DEFAULT NULL,
  PRIMARY KEY (`event_id`, `price_eur`),
  CONSTRAINT `fk_manual_tiers_event`
    FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );

        return;
    }

    if ($driver === 'pgsql') {
        $pdo->exec("ALTER TABLE events ADD COLUMN IF NOT EXISTS settlement_profile VARCHAR(20) NOT NULL DEFAULT 'standard'");
        $pdo->exec('ALTER TABLE event_settlement_shares ADD COLUMN IF NOT EXISTS amount_fixed_eur NUMERIC(10,2)');
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS event_revenue_tiers_manual (
  event_id INT NOT NULL REFERENCES events(id) ON DELETE CASCADE,
  price_eur NUMERIC(8,2) NOT NULL,
  quantity SMALLINT NOT NULL DEFAULT 0,
  notes VARCHAR(255),
  PRIMARY KEY (event_id, price_eur)
);
SQL
        );
    }
}

function edv_settlement_get_profile(PDO $pdo, int $eventId): string
{
    edv_settlement_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT settlement_profile FROM events WHERE id = ?');
    $stmt->execute([$eventId]);
    $v = (string) ($stmt->fetchColumn() ?: 'standard');

    return $v === 'venue_first' ? 'venue_first' : 'standard';
}

function edv_settlement_profile_label(string $profile): string
{
    return $profile === 'venue_first'
        ? 'Edição #01 — espaço 25% da receita, depois custos base, facilitadores em valor fixo'
        : 'Padrão — custos base, depois espaço % e equipa %';
}

function edv_settlement_ensure_cost_columns_sqlite(PDO $pdo): void
{
    $cols = $pdo->query('PRAGMA table_info(event_costs)')->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($cols, 'name');
    if (!in_array('cost_bucket', $names, true)) {
        $pdo->exec("ALTER TABLE event_costs ADD COLUMN cost_bucket TEXT NOT NULL DEFAULT 'base'");
    }
    if (!in_array('base_cost_slug', $names, true)) {
        $pdo->exec('ALTER TABLE event_costs ADD COLUMN base_cost_slug TEXT');
    }
    if (!in_array('cost_stage', $names, true)) {
        $pdo->exec("ALTER TABLE event_costs ADD COLUMN cost_stage TEXT NOT NULL DEFAULT 'actual'");
    }
}

function edv_settlement_ensure_cost_columns_mysql(PDO $pdo): void
{
    foreach (
        [
            'cost_bucket'   => "VARCHAR(16) NOT NULL DEFAULT 'base'",
            'base_cost_slug'=> 'VARCHAR(40) NULL DEFAULT NULL',
            'cost_stage'    => "VARCHAR(16) NOT NULL DEFAULT 'actual'",
        ] as $col => $def
    ) {
        $q = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'event_costs\' AND COLUMN_NAME = ?'
        );
        $q->execute([$col]);
        if ((int) $q->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `event_costs` ADD COLUMN `{$col}` {$def}");
        }
    }
}

function edv_settlement_ensure_cost_columns_pgsql(PDO $pdo): void
{
    $pdo->exec("ALTER TABLE event_costs ADD COLUMN IF NOT EXISTS cost_bucket VARCHAR(16) NOT NULL DEFAULT 'base'");
    $pdo->exec('ALTER TABLE event_costs ADD COLUMN IF NOT EXISTS base_cost_slug VARCHAR(40)');
    $pdo->exec("ALTER TABLE event_costs ADD COLUMN IF NOT EXISTS cost_stage VARCHAR(16) NOT NULL DEFAULT 'actual'");
}

/**
 * @return list<array{role_key:string,label:string,percent:float,pool:string,sort_order:int,is_active:bool}>
 */
function edv_settlement_default_shares(): array
{
    return [
        ['role_key' => 'espaco', 'label' => 'Espaço', 'percent' => 25.0, 'pool' => 'post_base', 'sort_order' => 10, 'is_active' => true],
        ['role_key' => 'facilitador_1', 'label' => 'Facilitador 1', 'percent' => 8.0, 'pool' => 'post_venue', 'sort_order' => 20, 'is_active' => true],
        ['role_key' => 'facilitador_2', 'label' => 'Facilitador 2', 'percent' => 8.0, 'pool' => 'post_venue', 'sort_order' => 30, 'is_active' => true],
        ['role_key' => 'dj', 'label' => 'DJ', 'percent' => 25.0, 'pool' => 'post_venue', 'sort_order' => 40, 'is_active' => true],
        ['role_key' => 'fotografo', 'label' => 'Fotógrafo', 'percent' => 8.0, 'pool' => 'post_venue', 'sort_order' => 50, 'is_active' => false],
        ['role_key' => 'indias', 'label' => 'Indias', 'percent' => 50.0, 'pool' => 'final', 'sort_order' => 60, 'is_active' => true],
        ['role_key' => 'carolina', 'label' => 'Carolina', 'percent' => 50.0, 'pool' => 'final', 'sort_order' => 70, 'is_active' => true],
    ];
}

/**
 * @return list<array{role_key:string,label:string,percent:float,pool:string,sort_order:int,is_active:bool,amount_fixed:?float}>
 */
function edv_settlement_default_shares_venue_first(): array
{
    return [
        ['role_key' => 'espaco', 'label' => 'Nua e Crua (espaço)', 'percent' => 25.0, 'pool' => 'gross', 'sort_order' => 10, 'is_active' => true, 'amount_fixed' => null],
        ['role_key' => 'facilitador_1', 'label' => 'Facilitador 1', 'percent' => 0.0, 'pool' => 'post_gross_base', 'sort_order' => 20, 'is_active' => true, 'amount_fixed' => 20.0],
        ['role_key' => 'facilitador_2', 'label' => 'Facilitador 2', 'percent' => 0.0, 'pool' => 'post_gross_base', 'sort_order' => 30, 'is_active' => true, 'amount_fixed' => 20.0],
        ['role_key' => 'dj', 'label' => 'DJ', 'percent' => 0.0, 'pool' => 'post_gross_base', 'sort_order' => 40, 'is_active' => false, 'amount_fixed' => null],
        ['role_key' => 'indias', 'label' => 'Indias', 'percent' => 50.0, 'pool' => 'final', 'sort_order' => 50, 'is_active' => true, 'amount_fixed' => null],
        ['role_key' => 'carolina', 'label' => 'Carolina', 'percent' => 50.0, 'pool' => 'final', 'sort_order' => 60, 'is_active' => true, 'amount_fixed' => null],
    ];
}

function edv_settlement_seed_shares_for_event(PDO $pdo, int $eventId, bool $force = false): void
{
    $check = $pdo->prepare('SELECT 1 FROM event_settlement_shares WHERE event_id = ? LIMIT 1');
    $check->execute([$eventId]);
    if (!$force && $check->fetchColumn()) {
        return;
    }
    if ($force) {
        $pdo->prepare('DELETE FROM event_settlement_shares WHERE event_id = ?')->execute([$eventId]);
    }
    $profile = edv_settlement_get_profile($pdo, $eventId);
    $defaults = $profile === 'venue_first'
        ? edv_settlement_default_shares_venue_first()
        : edv_settlement_default_shares();
    $ins = $pdo->prepare(
        'INSERT INTO event_settlement_shares (event_id, role_key, label, percent, amount_fixed_eur, pool, sort_order, is_active, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $now = date('Y-m-d H:i:s');
    foreach ($defaults as $row) {
        $ins->execute([
            $eventId,
            $row['role_key'],
            $row['label'],
            $row['percent'],
            $row['amount_fixed'] ?? null,
            $row['pool'],
            $row['sort_order'],
            $row['is_active'] ? 1 : 0,
            $now,
        ]);
    }
}

/**
 * Idempotente: regista contas reais da edição #01 (300€, 6×20 + 6×30, etc.).
 *
 * @return int|null event id
 */
function edv_settlement_seed_event_01_historical(PDO $pdo, bool $force = false): ?int
{
    edv_settlement_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT id FROM events WHERE `date` = ? OR title LIKE ? ORDER BY `date` ASC LIMIT 1'
    );
    $stmt->execute([EDV_EVENT_01_DATE, '%#01%']);
    $eventId = (int) ($stmt->fetchColumn() ?: 0);
    if ($eventId <= 0) {
        return null;
    }

    $hasShares = $pdo->prepare('SELECT 1 FROM event_settlement_shares WHERE event_id = ? LIMIT 1');
    $hasShares->execute([$eventId]);
    $sharesExist = (bool) $hasShares->fetchColumn();

    if ($force || !$sharesExist) {
        $pdo->prepare("UPDATE events SET settlement_profile = 'venue_first' WHERE id = ?")->execute([$eventId]);
    }

    $now = date('Y-m-d H:i:s');
    foreach ([20.0 => 6, 30.0 => 6] as $price => $qty) {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->prepare(
                'INSERT INTO event_revenue_tiers_manual (event_id, price_eur, quantity, notes)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), notes = VALUES(notes)'
            )->execute([$eventId, $price, $qty, 'Edição #01 — contagem manual']);
        } else {
            $pdo->prepare(
                'INSERT INTO event_revenue_tiers_manual (event_id, price_eur, quantity, notes)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT(event_id, price_eur) DO UPDATE SET quantity = excluded.quantity, notes = excluded.notes'
            )->execute([$eventId, $price, $qty, 'Edição #01 — contagem manual']);
        }
    }

    foreach ([
        'flyers' => 20.0,
        'comidas' => 27.0,
    ] as $slug => $amount) {
        $label = EDV_BASE_COST_SLUGS[$slug];
        $chk = $pdo->prepare(
            'SELECT id FROM event_costs WHERE event_id = ? AND cost_bucket = \'base\' AND base_cost_slug = ? LIMIT 1'
        );
        $chk->execute([$eventId, $slug]);
        $cid = (int) ($chk->fetchColumn() ?: 0);
        if ($cid > 0) {
            $pdo->prepare('UPDATE event_costs SET amount_eur = ?, cost_stage = \'actual\' WHERE id = ?')->execute([$amount, $cid]);
        } else {
            $pdo->prepare(
                'INSERT INTO event_costs (event_id, label, category, base_cost_slug, amount_eur, paid_by, notes,
                 incurred_at, reimbursed, cost_stage, cost_bucket, reimbursed_at, created_at)
                 VALUES (?, ?, \'custos_base\', ?, ?, NULL, \'Edição #01\', ?, 0, \'actual\', \'base\', NULL, ?)'
            )->execute([$eventId, $label, $slug, $amount, $now, $now]);
        }
    }

    if ($force || !$sharesExist) {
        edv_settlement_seed_shares_for_event($pdo, $eventId, true);
    }

    return $eventId;
}

function edv_settlement_find_event_01_id(PDO $pdo): ?int
{
    $stmt = $pdo->prepare(
        'SELECT id FROM events WHERE `date` = ? OR title LIKE ? ORDER BY `date` ASC LIMIT 1'
    );
    $stmt->execute([EDV_EVENT_01_DATE, '%#01%']);
    $id = (int) ($stmt->fetchColumn() ?: 0);

    return $id > 0 ? $id : null;
}

/**
 * @return list<array<string,mixed>>
 */
function edv_settlement_get_shares(PDO $pdo, int $eventId): array
{
    edv_settlement_ensure_schema($pdo);
    edv_settlement_seed_shares_for_event($pdo, $eventId);
    $stmt = $pdo->prepare(
        'SELECT * FROM event_settlement_shares WHERE event_id = ? ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$eventId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function edv_settlement_unique_role_key(PDO $pdo, int $eventId): string
{
    $check = $pdo->prepare('SELECT 1 FROM event_settlement_shares WHERE event_id = ? AND role_key = ? LIMIT 1');
    for ($i = 0; $i < 20; $i++) {
        $key = 'beneficiary_' . substr(bin2hex(random_bytes(6)), 0, 12);
        $check->execute([$eventId, $key]);
        if (!$check->fetchColumn()) {
            return $key;
        }
    }

    return 'beneficiary_' . (string) time();
}

/**
 * @return int|null New share id
 */
function edv_settlement_add_share(PDO $pdo, int $eventId, string $label = 'Nova entidade'): ?int
{
    edv_settlement_ensure_schema($pdo);
    edv_settlement_seed_shares_for_event($pdo, $eventId);

    $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM event_settlement_shares WHERE event_id = ?');
    $sortStmt->execute([$eventId]);
    $sortOrder = (int) ($sortStmt->fetchColumn() ?: 0) + 10;

    $roleKey = edv_settlement_unique_role_key($pdo, $eventId);
    $now = date('Y-m-d H:i:s');
    $ins = $pdo->prepare(
        'INSERT INTO event_settlement_shares (event_id, role_key, label, percent, amount_fixed_eur, pool, sort_order, is_active, created_at)
         VALUES (?, ?, ?, 0, NULL, ?, ?, 1, ?)'
    );
    $ins->execute([
        $eventId,
        $roleKey,
        mb_substr(trim($label) !== '' ? trim($label) : 'Nova entidade', 0, 120),
        'post_venue',
        $sortOrder,
        $now,
    ]);

    $id = (int) $pdo->lastInsertId();

    return $id > 0 ? $id : null;
}

function edv_settlement_delete_share(PDO $pdo, int $eventId, int $shareId): bool
{
    if ($shareId <= 0 || $eventId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('DELETE FROM event_settlement_shares WHERE id = ? AND event_id = ?');

    return $stmt->execute([$shareId, $eventId]) && $stmt->rowCount() > 0;
}

/**
 * Bilhetes vendidos agrupados por valor (escalões).
 *
 * @return list<array{price_eur:float,quantity:int,subtotal:float}>
 */
function edv_settlement_revenue_tiers(PDO $pdo, int $eventId): array
{
    edv_settlement_ensure_schema($pdo);
    $manual = $pdo->prepare(
        'SELECT price_eur, quantity FROM event_revenue_tiers_manual WHERE event_id = ? ORDER BY price_eur ASC'
    );
    $manual->execute([$eventId]);
    $mrows = $manual->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($mrows)) {
        $rows = [];
        foreach ($mrows as $r) {
            $price = round((float) $r['price_eur'], 2);
            $qty = (int) $r['quantity'];
            $rows[] = [
                'price_eur' => $price,
                'quantity'  => $qty,
                'subtotal'  => round($price * $qty, 2),
                'source'    => 'manual',
            ];
        }

        return $rows;
    }

    $stmt = $pdo->prepare(
        "SELECT amount_paid AS price_eur, COUNT(*) AS quantity
         FROM tickets
         WHERE event_id = ?
           AND payment_status IN ('paid', 'free')
         GROUP BY amount_paid
         ORDER BY amount_paid ASC"
    );
    $stmt->execute([$eventId]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $price = round((float) $r['price_eur'], 2);
        $qty = (int) $r['quantity'];
        $rows[] = [
            'price_eur' => $price,
            'quantity'  => $qty,
            'subtotal'  => round($price * $qty, 2),
            'source'    => 'tickets',
        ];
    }

    return $rows;
}

/**
 * @param list<array{kind:string,label:string,amount:float,percent?:string,note?:string}> $flow
 */
function edv_settlement_flow_line(string $kind, string $label, float $amount, ?string $percent = null, ?string $note = null): array
{
    return array_filter([
        'kind'    => $kind,
        'label'   => $label,
        'amount'  => round($amount, 2),
        'percent' => $percent,
        'note'    => $note,
    ], static fn ($v) => $v !== null);
}

/**
 * Soma custos base (reais, bucket base).
 */
function edv_settlement_base_costs_total(PDO $pdo, int $eventId, bool $includePromised = false): float
{
    $sql = "SELECT COALESCE(SUM(amount_eur), 0) FROM event_costs
            WHERE event_id = ? AND cost_bucket = 'base'";
    if (!$includePromised) {
        $sql .= " AND cost_stage = 'actual'";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$eventId]);

    return round((float) $stmt->fetchColumn(), 2);
}

/**
 * @return list<array<string,mixed>>
 */
function edv_settlement_base_cost_lines(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM event_costs
         WHERE event_id = ? AND cost_bucket = 'base'
         ORDER BY
           CASE base_cost_slug
             WHEN 'transportes' THEN 1
             WHEN 'flyers' THEN 2
             WHEN 'comidas' THEN 3
             WHEN 'promo_online' THEN 4
             ELSE 99
           END,
           id ASC"
    );
    $stmt->execute([$eventId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Calcula waterfall completo.
 *
 * @return array<string,mixed>
 */
function edv_settlement_calculate(PDO $pdo, int $eventId): array
{
    edv_settlement_ensure_schema($pdo);
    $profile = edv_settlement_get_profile($pdo, $eventId);
    if ($profile === 'venue_first') {
        return edv_settlement_calculate_venue_first($pdo, $eventId);
    }

    return edv_settlement_calculate_standard($pdo, $eventId);
}

/**
 * Modelo folha (#02+): custos base → espaço % → equipa % → lucro.
 *
 * @return array<string,mixed>
 */
function edv_settlement_calculate_standard(PDO $pdo, int $eventId): array
{
    $tiers = edv_settlement_revenue_tiers($pdo, $eventId);
    $revenue = 0.0;
    foreach ($tiers as $t) {
        $revenue += (float) $t['subtotal'];
    }
    $revenue = round($revenue, 2);

    $baseTotal = edv_settlement_base_costs_total($pdo, $eventId, false);
    $postBase = round($revenue - $baseTotal, 2);

    $shares = edv_settlement_get_shares($pdo, $eventId);
    $venueShare = 0.0;
    $talentShares = [];
    $finalShares = [];
    $lines = [];

    foreach ($shares as $s) {
        if (!(bool) (int) ($s['is_active'] ?? 0)) {
            continue;
        }
        $pool = (string) ($s['pool'] ?? '');
        $pct = (float) ($s['percent'] ?? 0);
        if ($pool === 'post_base') {
            $venueShare += round($postBase * $pct / 100, 2);
        }
    }

    $postVenue = round($postBase - $venueShare, 2);

    foreach ($shares as $s) {
        if (!(bool) (int) ($s['is_active'] ?? 0)) {
            continue;
        }
        if ((string) ($s['pool'] ?? '') !== 'post_venue') {
            continue;
        }
        $pct = (float) ($s['percent'] ?? 0);
        $amt = round($postVenue * $pct / 100, 2);
        $talentShares[] = [
            'role_key' => (string) $s['role_key'],
            'label'    => (string) $s['label'],
            'percent'  => $pct,
            'amount'   => $amt,
            'pool'     => 'post_venue',
        ];
    }

    $talentTotal = 0.0;
    foreach ($talentShares as $t) {
        $talentTotal += (float) $t['amount'];
    }
    $talentTotal = round($talentTotal, 2);
    $postTalent = round($postVenue - $talentTotal, 2);

    foreach ($shares as $s) {
        if (!(bool) (int) ($s['is_active'] ?? 0)) {
            continue;
        }
        if ((string) ($s['pool'] ?? '') !== 'final') {
            continue;
        }
        $pct = (float) ($s['percent'] ?? 0);
        $amt = round($postTalent * $pct / 100, 2);
        $finalShares[] = [
            'role_key' => (string) $s['role_key'],
            'label'    => (string) $s['label'],
            'percent'  => $pct,
            'amount'   => $amt,
            'pool'     => 'final',
        ];
    }

    // Linha única de espaço para UI
    foreach ($shares as $s) {
        if ((string) ($s['pool'] ?? '') === 'post_base' && (bool) (int) ($s['is_active'] ?? 0)) {
            $lines[] = [
                'role_key' => (string) $s['role_key'],
                'label'    => (string) $s['label'],
                'percent'  => (float) $s['percent'],
                'amount'   => $venueShare,
                'pool'     => 'post_base',
                'pool_label' => 'Pós custos base',
                'pool_amount' => $postBase,
            ];
            break;
        }
    }

    $flow = [
        edv_settlement_flow_line('total', 'Receita total', $revenue),
    ];
    foreach (edv_settlement_base_cost_lines($pdo, $eventId) as $bc) {
        if ((string) ($bc['cost_stage'] ?? 'actual') !== 'actual') {
            continue;
        }
        $flow[] = edv_settlement_flow_line('deduction', (string) $bc['label'], -(float) $bc['amount_eur'], null, 'custo base');
    }
    $flow[] = edv_settlement_flow_line('pool', 'Pós custos base', $postBase);
    foreach ($shares as $s) {
        if ((string) ($s['pool'] ?? '') === 'post_base' && (int) ($s['is_active'] ?? 0) === 1) {
            $flow[] = edv_settlement_flow_line(
                'deduction',
                (string) $s['label'],
                -$venueShare,
                number_format((float) $s['percent'], 0) . '%'
            );
            break;
        }
    }
    $flow[] = edv_settlement_flow_line('pool', 'Pós espaço', $postVenue);
    foreach ($talentShares as $ts) {
        $flow[] = edv_settlement_flow_line('deduction', (string) $ts['label'], -(float) $ts['amount'], number_format((float) $ts['percent'], 0) . '%');
    }
    $flow[] = edv_settlement_flow_line('pool', 'Pós custos facilitadores', $postTalent);
    foreach ($finalShares as $fs) {
        $flow[] = edv_settlement_flow_line('split', (string) $fs['label'], (float) $fs['amount'], number_format((float) $fs['percent'], 0) . '%');
    }

    return [
        'profile'        => 'standard',
        'profile_label'  => edv_settlement_profile_label('standard'),
        'event_id'       => $eventId,
        'revenue'        => $revenue,
        'revenue_tiers'  => $tiers,
        'base_costs'     => edv_settlement_base_cost_lines($pdo, $eventId),
        'base_total'     => $baseTotal,
        'post_base'      => $postBase,
        'venue_share'    => $venueShare,
        'post_venue'     => $postVenue,
        'talent_shares'  => $talentShares,
        'talent_total'   => $talentTotal,
        'post_talent'    => $postTalent,
        'final_shares'   => $finalShares,
        'shares_config'  => $shares,
        'flow'           => $flow,
    ];
}

/**
 * Modelo edição #01: espaço 25% da receita → custos base → 20€/facilitador → 50/50.
 *
 * @return array<string,mixed>
 */
function edv_settlement_calculate_venue_first(PDO $pdo, int $eventId): array
{
    $tiers = edv_settlement_revenue_tiers($pdo, $eventId);
    $revenue = 0.0;
    foreach ($tiers as $t) {
        $revenue += (float) $t['subtotal'];
    }
    $revenue = round($revenue, 2);

    $shares = edv_settlement_get_shares($pdo, $eventId);
    $venueShare = 0.0;
    $venueLabel = 'Espaço';
    foreach ($shares as $s) {
        if ((string) ($s['pool'] ?? '') === 'gross' && (int) ($s['is_active'] ?? 0) === 1) {
            $venueShare = round($revenue * (float) ($s['percent'] ?? 0) / 100, 2);
            $venueLabel = (string) $s['label'];
            break;
        }
    }

    $postGross = round($revenue - $venueShare, 2);
    $baseLines = edv_settlement_base_cost_lines($pdo, $eventId);
    $baseTotal = edv_settlement_base_costs_total($pdo, $eventId, false);
    $postGrossBase = round($postGross - $baseTotal, 2);

    $fixedShares = [];
    $fixedTotal = 0.0;
    foreach ($shares as $s) {
        if ((int) ($s['is_active'] ?? 0) !== 1) {
            continue;
        }
        if ((string) ($s['pool'] ?? '') !== 'post_gross_base') {
            continue;
        }
        $fixed = $s['amount_fixed_eur'] ?? null;
        if ($fixed === null || $fixed === '') {
            continue;
        }
        $amt = round((float) $fixed, 2);
        $fixedShares[] = [
            'role_key' => (string) $s['role_key'],
            'label'    => (string) $s['label'],
            'amount'   => $amt,
            'percent'  => null,
            'pool'     => 'post_gross_base',
        ];
        $fixedTotal += $amt;
    }
    $fixedTotal = round($fixedTotal, 2);
    $postTalent = round($postGrossBase - $fixedTotal, 2);

    $finalShares = [];
    foreach ($shares as $s) {
        if ((int) ($s['is_active'] ?? 0) !== 1 || (string) ($s['pool'] ?? '') !== 'final') {
            continue;
        }
        $pct = (float) ($s['percent'] ?? 0);
        $finalShares[] = [
            'role_key' => (string) $s['role_key'],
            'label'    => (string) $s['label'],
            'percent'  => $pct,
            'amount'   => round($postTalent * $pct / 100, 2),
            'pool'     => 'final',
        ];
    }

    $flow = [
        edv_settlement_flow_line('total', 'Receita total (bilhetes)', $revenue, null, '6×20€ + 6×30€'),
        edv_settlement_flow_line('deduction', $venueLabel, -$venueShare, '25%', 'sobre receita'),
        edv_settlement_flow_line('pool', 'Após espaço (Nua e Crua)', $postGross),
    ];
    foreach ($baseLines as $bc) {
        if ((string) ($bc['cost_stage'] ?? 'actual') !== 'actual') {
            continue;
        }
        if ((float) $bc['amount_eur'] <= 0) {
            continue;
        }
        $flow[] = edv_settlement_flow_line('deduction', (string) $bc['label'], -(float) $bc['amount_eur'], null, 'custo base');
    }
    $flow[] = edv_settlement_flow_line('pool', 'Após custos base', $postGrossBase);
    foreach ($fixedShares as $fs) {
        $flow[] = edv_settlement_flow_line('deduction', (string) $fs['label'], -(float) $fs['amount'], '20€ fixo');
    }
    $flow[] = edv_settlement_flow_line('pool', 'Para dividir (nós dois)', $postTalent);
    foreach ($finalShares as $fs) {
        $flow[] = edv_settlement_flow_line('split', (string) $fs['label'], (float) $fs['amount'], '50%');
    }

    return [
        'profile'        => 'venue_first',
        'profile_label'  => edv_settlement_profile_label('venue_first'),
        'event_id'       => $eventId,
        'revenue'        => $revenue,
        'revenue_tiers'  => $tiers,
        'base_costs'     => $baseLines,
        'base_total'     => $baseTotal,
        'venue_share'    => $venueShare,
        'post_gross'     => $postGross,
        'post_gross_base'=> $postGrossBase,
        'talent_shares'  => $fixedShares,
        'talent_total'   => $fixedTotal,
        'post_talent'    => $postTalent,
        'final_shares'   => $finalShares,
        'shares_config'  => $shares,
        'flow'           => $flow,
        'post_base'      => $postGrossBase,
        'post_venue'     => $postGrossBase,
    ];
}

/**
 * Cria linhas vazias dos 4 custos base se ainda não existirem.
 */
function edv_settlement_ensure_base_cost_rows(PDO $pdo, int $eventId): int
{
    edv_settlement_ensure_schema($pdo);
    $created = 0;
    $now = date('Y-m-d H:i:s');
    $ins = $pdo->prepare(
        'INSERT INTO event_costs (event_id, label, category, base_cost_slug, amount_eur, paid_by, notes,
         incurred_at, reimbursed, cost_stage, cost_bucket, reimbursed_at, created_at)
         VALUES (?, ?, ?, ?, 0, NULL, NULL, ?, 0, \'actual\', \'base\', NULL, ?)'
    );
    foreach (EDV_BASE_COST_SLUGS as $slug => $label) {
        $chk = $pdo->prepare(
            'SELECT 1 FROM event_costs WHERE event_id = ? AND cost_bucket = \'base\' AND base_cost_slug = ? LIMIT 1'
        );
        $chk->execute([$eventId, $slug]);
        if ($chk->fetchColumn()) {
            continue;
        }
        $ins->execute([$eventId, $label, 'custos_base', $slug, $now, $now]);
        $created++;
    }

    return $created;
}
