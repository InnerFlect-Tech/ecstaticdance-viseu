<?php
/* Shared by save-link-booking.php + complete-link-booking.php
 * Works without helpers.php: loads config.php for DB + mail. */

declare(strict_types=1);

/** Em erros fatais (ex.: mb_substr em falta), o PHP devolve HTTP 500 com corpo vazio; aqui garantimos JSON para o cliente. */
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($e['type'], $fatal, true)) {
        return;
    }
    if (headers_sent()) {
        return;
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        [
            'ok'    => false,
            'error' => 'Erro interno (PHP): ' . $e['message'] . ' — em ' . basename((string) $e['file']) . ':' . $e['line'],
        ],
        JSON_UNESCAPED_UNICODE
    );
});

$__cfg = __DIR__ . '/config.php';
if (!is_readable($__cfg)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'config.php em falta. Copia server/api/config.example.php para config.php.'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $__cfg;

require_once __DIR__ . '/ticket-pricing.php';

/* Usados em link_notify_team(); sem estes, PHP 8+ pode gerar fatal e corpo vazio (HTTP 500) após INSERT. */
if (!defined('FROM_EMAIL')) {
    define('FROM_EMAIL', 'info@ecstaticdanceviseu.pt');
}
if (!defined('FROM_NAME')) {
    define('FROM_NAME', 'Ecstatic Dance Viseu');
}

if (!defined('LINK_USE_SQLITE')) {
    define('LINK_USE_SQLITE', false);
}
if (!defined('LINK_SQLITE_PATH')) {
    define('LINK_SQLITE_PATH', __DIR__ . '/../data/link-bookings.sqlite');
}
if (!defined('LINK_USE_JSON')) {
    define('LINK_USE_JSON', false);
}
if (!defined('LINK_JSON_PATH')) {
    define('LINK_JSON_PATH', __DIR__ . '/../data/link-registrations-dev.json');
}

/** Modo de gravação do fluxo links.html: mysql | pgsql | sqlite | json. */
function link_registration_backend(): string {
    static $resolved = false;
    static $backend = '';
    if ($resolved) {
        return $backend;
    }
    $resolved = true;
    if (LINK_USE_JSON === true) {
        require_once __DIR__ . '/link-json-store.php';
        return $backend = 'json';
    }
    if (link_is_sqlite()) {
        return $backend = 'sqlite';
    }
    if (link_is_pgsql()) {
        return $backend = 'pgsql';
    }

    return $backend = 'mysql';
}

function link_is_sqlite(): bool {
    if (defined('DB_DRIVER') && is_string(DB_DRIVER) && strtolower(DB_DRIVER) === 'sqlite') {
        return true;
    }

    return LINK_USE_SQLITE === true;
}

function link_is_pgsql(): bool {
    return defined('DB_DRIVER') && is_string(DB_DRIVER) && strtolower(DB_DRIVER) === 'pgsql';
}

/** Timestamp for DATETIME / TEXT (Europe/Lisbon), portável (MySQL + SQLite). */
function link_sql_now(): string {
    $d = new DateTime('now', new DateTimeZone('Europe/Lisbon'));
    return $d->format('Y-m-d H:i:s');
}

/** Mínimo do bilhete (com desconto de regresso ou código se aplicável). */
function link_ticket_min_eur(?string $email = null, ?int $eventId = null, ?string $promoCode = null): float {
    return edv_ticket_min_eur($email, $eventId, null, null, $promoCode);
}

/** Resolve evento principal a partir do slug edv-YYYY-MM-DD. */
function link_resolve_event_id_from_slug(string $slug): ?int
{
    if (!preg_match('/edv-(\d{4}-\d{2}-\d{2})$/', $slug, $m)) {
        return null;
    }
    require_once __DIR__ . '/helpers.php';
    $q = db()->prepare(
        'SELECT id FROM events WHERE date = ? ORDER BY is_active DESC, id DESC LIMIT 1'
    );
    $q->execute([$m[1]]);
    $id = $q->fetchColumn();

    return $id !== false ? (int) $id : null;
}

function link_ticket_max_eur(): float {
    return 200.0;
}

/**
 * Cria tabela de reservas (links) se não existir — alinhada a server/setup/migration_2026_04_link_registrations.sql
 */
function link_sqlite_migrate(PDO $pdo): void {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS link_registrations (
  id TEXT NOT NULL PRIMARY KEY,
  payment_ref TEXT NOT NULL UNIQUE,
  event_slug TEXT NOT NULL DEFAULT 'edv-2026-06-27',
  name TEXT NOT NULL,
  email TEXT NOT NULL,
  phone TEXT NOT NULL,
  ticket_euros REAL NOT NULL,
  dinner_note TEXT NOT NULL DEFAULT '',
  total_euros REAL NOT NULL,
  payment_method TEXT NOT NULL CHECK (payment_method IN ('mbway','transfer','revolut')),
  heard_from TEXT NOT NULL,
  heard_other TEXT,
  step1_at TEXT NOT NULL,
  step2_type TEXT CHECK (step2_type IS NULL OR step2_type IN ('upload','email_later')),
  proof_relpath TEXT,
  proof_mime TEXT,
  step2_at TEXT,
  ticket_id TEXT,
  confirmed_at TEXT,
  receipt_email_sent_at TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
SQL
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email ON link_registrations (email);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_step1 ON link_registrations (step1_at);');
    link_registrations_ensure_columns($pdo);
}

/**
 * Colunas de confirmação / emails (migração incremental em bases existentes).
 */
function link_registrations_ensure_columns(PDO $pdo): void
{
    if (link_is_sqlite()) {
        $cols = $pdo->query('PRAGMA table_info(link_registrations)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        $add = static function (string $col, string $ddl) use ($pdo, $names): void {
            if (!in_array($col, $names, true)) {
                $pdo->exec('ALTER TABLE link_registrations ADD COLUMN ' . $ddl);
            }
        };
        $add('ticket_id', 'ticket_id TEXT');
        $add('confirmed_at', 'confirmed_at TEXT');
        $add('receipt_email_sent_at', 'receipt_email_sent_at TEXT');
        $add('promo_code', 'promo_code TEXT');
        return;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $alters = [
            'ticket_id' => 'ADD COLUMN `ticket_id` CHAR(36) NULL DEFAULT NULL AFTER `step2_at`',
            'confirmed_at' => 'ADD COLUMN `confirmed_at` DATETIME NULL DEFAULT NULL AFTER `ticket_id`',
            'receipt_email_sent_at' => 'ADD COLUMN `receipt_email_sent_at` DATETIME NULL DEFAULT NULL AFTER `confirmed_at`',
            'promo_code' => 'ADD COLUMN `promo_code` VARCHAR(32) NULL DEFAULT NULL AFTER `heard_other`',
        ];
        foreach ($alters as $col => $sql) {
            try {
                $chk = $pdo->query(
                    "SELECT 1 FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'link_registrations' AND COLUMN_NAME = " . $pdo->quote($col)
                );
                if ($chk && $chk->fetchColumn()) {
                    continue;
                }
                $pdo->exec('ALTER TABLE `link_registrations` ' . $sql);
            } catch (PDOException) {
                // coluna já existe ou sem permissão — ignorar
            }
        }
        try {
            $pdo->exec('CREATE INDEX `idx_link_ticket_id` ON `link_registrations` (`ticket_id`)');
        } catch (PDOException) {
            // índice já existe
        }
    } elseif ($driver === 'pgsql') {
        $pdo->exec('ALTER TABLE link_registrations ADD COLUMN IF NOT EXISTS ticket_id CHAR(36)');
        $pdo->exec('ALTER TABLE link_registrations ADD COLUMN IF NOT EXISTS confirmed_at TIMESTAMPTZ');
        $pdo->exec('ALTER TABLE link_registrations ADD COLUMN IF NOT EXISTS receipt_email_sent_at TIMESTAMPTZ');
        $pdo->exec('ALTER TABLE link_registrations ADD COLUMN IF NOT EXISTS promo_code VARCHAR(32)');
    }
}

/**
 * @return \PDO
 */
function link_api_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    if (link_is_sqlite()) {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('Extensão PDO sqlite não carregada no PHP.');
        }
        $path = (string) LINK_SQLITE_PATH;
        $dir  = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar o directório: ' . $dir);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON;');
        link_sqlite_migrate($pdo);
        return $pdo;
    }
    if (link_is_pgsql()) {
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('Extensão PDO pgsql não carregada no PHP.');
        }
        $host = defined('DB_HOST') ? (string) DB_HOST : 'localhost';
        $port = defined('DB_PORT') && DB_PORT !== '' ? (string) DB_PORT : '5432';
        $name = defined('DB_NAME') ? (string) DB_NAME : 'postgres';
        $sslmode = defined('DB_SSLMODE') && DB_SSLMODE !== '' ? (string) DB_SSLMODE : 'require';
        $pdo = new PDO(
            'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';sslmode=' . $sslmode,
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        link_registrations_ensure_columns($pdo);

        return $pdo;
    }
    $mysqlHost = DB_HOST;
    $mysqlPort = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $mysqlDsn = 'mysql:host=' . $mysqlHost . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    if ($mysqlPort !== '') {
        $mysqlDsn = 'mysql:host=' . $mysqlHost . ';port=' . $mysqlPort . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    }
    $pdo = new PDO(
        $mysqlDsn,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    link_registrations_ensure_columns($pdo);

    return $pdo;
}

function link_api_cors(): void {
    $allowed = [
        'https://ecstaticdanceviseu.pt',
        'https://www.ecstaticdanceviseu.pt',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Accept');
        header('Vary: Origin');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function link_json_ok(array $data = []): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function link_json_err(string $message, int $status = 400): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function link_sanitise(string $val, int $max = 255): string {
    $t = trim(strip_tags($val));
    if (function_exists('mb_substr')) {
        return mb_substr($t, 0, $max);
    }
    return strlen($t) <= $max ? $t : substr($t, 0, $max);
}

/** Código curto para descrição bancária: 3 letras + 3 algarismos (ex. KQP391). */
function link_generate_payment_ref(): string {
    $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $a = '';
    for ($i = 0; $i < 3; $i++) {
        $a .= $letters[random_int(0, strlen($letters) - 1)];
    }
    $n = str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
    return $a . $n;
}

function edv_uploads_base_dir(): string
{
    $root = getenv('EDV_SERVER_ROOT');
    if (is_string($root) && trim($root) !== '') {
        return rtrim(trim($root), '/') . '/uploads';
    }

    return dirname(__DIR__) . '/uploads';
}

function link_proofs_dir(): string {
    $dir = edv_uploads_base_dir() . '/link-proofs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function link_org_hello(): string {
    return defined('ORG_NOTIFY_EMAIL') && is_string(ORG_NOTIFY_EMAIL) && ORG_NOTIFY_EMAIL !== ''
        ? ORG_NOTIFY_EMAIL
        : 'hello@ecstaticdanceviseu.pt';
}

function link_org_info(): string {
    return defined('ORG_INFO_EMAIL') && is_string(ORG_INFO_EMAIL) && ORG_INFO_EMAIL !== ''
        ? ORG_INFO_EMAIL
        : 'info@ecstaticdanceviseu.pt';
}

function link_uuid_v4(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/**
 * Todas as linhas da tabela link_registrations (fluxo links.html), mais recentes primeiro.
 * Suporta SQLite, MySQL ou armazenamento JSON (dev).
 *
 * @return list<array<string,mixed>>
 */
function link_registrations_all(): array {
    $backend = link_registration_backend();
    if ($backend === 'json') {
        require_once __DIR__ . '/link-json-store.php';
        return link_json_all_registrations_ordered();
    }
    $pdo = link_api_db();
    $stmt = $pdo->query('SELECT * FROM link_registrations ORDER BY step1_at DESC');

    return $stmt ? $stmt->fetchAll() : [];
}

/**
 * Para o painel admin: onde estão a ser lidas as inscrições /links (deve coincidir com o que o API usa em produção).
 *
 * @return array{mode:'json'|'sqlite'|'mysql'|'pgsql', detail:string}
 */
function link_registrations_storage_info(): array {
    if (LINK_USE_JSON === true) {
        return ['mode' => 'json', 'detail' => (string) LINK_JSON_PATH];
    }
    if (link_is_sqlite()) {
        return ['mode' => 'sqlite', 'detail' => (string) LINK_SQLITE_PATH];
    }
    if (link_is_pgsql()) {
        $db = defined('DB_NAME') && DB_NAME !== '' ? (string) DB_NAME : '?';
        $host = defined('DB_HOST') ? (string) DB_HOST : '';
        return ['mode' => 'pgsql', 'detail' => $host !== '' ? $db . '@' . $host : $db];
    }
    $db = defined('DB_NAME') && DB_NAME !== '' ? (string) DB_NAME : '?';
    $host = defined('DB_HOST') ? (string) DB_HOST : '';

    return ['mode' => 'mysql', 'detail' => $host !== '' ? $db . '@' . $host : $db];
}

function link_notify_team(string $subject_line, string $body): void {
    try {
        if (!function_exists('mail')) {
            return;
        }
        $fromName = defined('FROM_NAME') && is_string(FROM_NAME) ? FROM_NAME : 'Ecstatic Dance Viseu';
        $fromEmail = defined('FROM_EMAIL') && is_string(FROM_EMAIL) ? FROM_EMAIL : 'info@ecstaticdanceviseu.pt';
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= 'From: ' . $fromName . ' <' . $fromEmail . ">\r\n";
        $enc = '=?UTF-8?B?' . base64_encode('EDV — ' . $subject_line) . '?=';
        foreach ([link_org_hello(), link_org_info()] as $to) {
            if ($to === '') {
                continue;
            }
            @mail($to, $enc, $body, $headers);
        }
    } catch (Throwable $e) {
        // O pedido já foi gravado; não rebentar a API por falha opcional de mail / headers.
    }
}
