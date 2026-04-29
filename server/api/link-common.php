<?php
/* Shared by save-link-booking.php + complete-link-booking.php
 * Works without helpers.php: loads config.php for DB + mail. */

declare(strict_types=1);

$__cfg = __DIR__ . '/config.php';
if (!is_readable($__cfg)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'config.php em falta. Copia server/api/config.example.php para config.php.'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $__cfg;

if (!defined('LINK_USE_SQLITE')) {
    define('LINK_USE_SQLITE', false);
}
if (!defined('LINK_SQLITE_PATH')) {
    define('LINK_SQLITE_PATH', __DIR__ . '/../data/link-bookings.sqlite');
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
    return mb_substr(trim(strip_tags($val)), 0, $max);
}

function link_generate_payment_ref(): string {
    $chars  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $suffix = '';
    for ($i = 0; $i < 6; $i++) {
        $suffix .= $chars[random_int(0, strlen($chars) - 1)];
    }
    $d = new DateTime('now', new DateTimeZone('Europe/Lisbon'));
    return 'EDV-' . $d->format('Ymd') . '-' . $suffix;
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

function link_notify_team(string $subject_line, string $body): void {
    if (!function_exists('mail')) {
        return;
    }
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= 'From: ' . FROM_NAME . ' <' . FROM_EMAIL . ">\r\n";
    $enc = '=?UTF-8?B?' . base64_encode('EDV — ' . $subject_line) . '?=';
    foreach ([link_org_hello(), link_org_info()] as $to) {
        @mail($to, $enc, $body, $headers);
    }
}
