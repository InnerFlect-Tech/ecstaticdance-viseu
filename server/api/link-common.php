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

/** Modo de gravação do fluxo links.html: mysql (produção) | sqlite (local típico) | json (local sem PDO sqlite). */
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

    return $backend = 'mysql';
}

function link_is_sqlite(): bool {
    return LINK_USE_SQLITE === true;
}

/** Timestamp for DATETIME / TEXT (Europe/Lisbon), portável (MySQL + SQLite). */
function link_sql_now(): string {
    $d = new DateTime('now', new DateTimeZone('Europe/Lisbon'));
    return $d->format('Y-m-d H:i:s');
}

/** Mínimo do bilhete (sliding): early bird 20€ até fim de 3 mai 2026 (Lisboa); depois 30€. Igual a create-checkout.php */
function link_ticket_min_eur(): float {
    $tz = new DateTimeZone('Europe/Lisbon');
    $now = new DateTime('now', $tz);
    $early_end = new DateTime('2026-05-04 00:00:00', $tz);

    return ($now < $early_end) ? 20.0 : 30.0;
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
  event_slug TEXT NOT NULL DEFAULT 'edv-2026-05-23',
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
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
SQL
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email ON link_registrations (email);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_step1 ON link_registrations (step1_at);');
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
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
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

function link_proofs_dir(): string {
    $dir = dirname(__DIR__) . '/uploads/link-proofs';
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
