<?php
/* ============================================================
   helpers.php — Shared utilities for all API endpoints
   ============================================================ */

require_once __DIR__ . '/config.php';

// ── Database connection (singleton PDO) ──
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
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
    }
    return $pdo;
}

// ── CORS headers ──
function cors(): void {
    $allowed = [
        'https://ecstaticdanceviseu.pt',
        'https://www.ecstaticdanceviseu.pt',
    ];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Vary: Origin');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ── JSON responses ──
function json_ok(array $data = []): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $message, int $status = 400): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Parse JSON request body ──
function json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// ── Generate UUID v4 ──
function generate_uuid(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

// ── Stripe API request (no Composer needed) ──
function stripe_request(string $method, string $endpoint, array $data = []): array {
    $url = 'https://api.stripe.com/v1/' . ltrim($endpoint, '/');
    $ch  = curl_init($url);

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ];

    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($data, '', '&');
    } elseif ($method === 'GET' && !empty($data)) {
        curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($data));
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        throw new RuntimeException("cURL error: $err");
    }

    return [
        'data'   => json_decode($response, true),
        'status' => $status,
    ];
}

// ── Verify Stripe webhook signature ──
function verify_stripe_webhook(string $payload, string $sig_header, string $secret): bool {
    $parts = [];
    foreach (explode(',', $sig_header) as $part) {
        [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
        $parts[$k][] = $v;
    }

    $t    = $parts['t'][0]  ?? null;
    $sigs = $parts['v1']    ?? [];

    if (!$t || empty($sigs)) {
        return false;
    }

    // Reject events older than 5 minutes to prevent replay attacks
    if (abs(time() - (int)$t) > 300) {
        return false;
    }

    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    return in_array($expected, $sigs, true);
}

// ── Send ticket confirmation email ──
function send_ticket_email(
    string $to_email,
    string $to_name,
    string $ticket_id,
    string $event_title,
    string $event_date,
    float  $amount
): bool {
    $qr_url     = 'https://api.qrserver.com/v1/create-qr-code/?data=' . urlencode($ticket_id) . '&size=300x300&margin=10';
    $date_fmt   = (new DateTime($event_date))->format('d \d\e F \d\e Y');
    $amount_fmt = $amount > 0 ? '€' . number_format($amount, 2, ',', ' ') : 'Gratuito';
    $subject    = 'O teu bilhete — ' . $event_title;
    $app_url    = APP_URL;

    ob_start(); ?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<style>
  body{font-family:Arial,sans-serif;background:#F5EFE6;margin:0;padding:20px}
  .wrap{max-width:560px;margin:0 auto;background:#1A1210;padding:40px;color:#F5EFE6}
  .eyebrow{font-size:11px;letter-spacing:3px;text-transform:uppercase;color:#B8924A;margin-bottom:20px}
  h1{font-size:28px;font-weight:300;margin:0 0 8px}
  .sub{font-size:14px;color:rgba(245,239,230,.5);margin:0 0 32px;line-height:1.6}
  .qr-wrap{text-align:center;margin:0 0 32px}
  .qr-wrap img{border:8px solid white;display:inline-block}
  .details{border-top:1px solid rgba(245,239,230,.12);padding-top:24px}
  .row{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid rgba(245,239,230,.07);font-size:13px}
  .lbl{color:rgba(245,239,230,.4)}
  .val{color:#F5EFE6;text-align:right}
  .note{margin-top:28px;font-size:12px;color:rgba(245,239,230,.3);line-height:1.7}
  .link{color:#B8924A;text-decoration:none}
  .id-mono{font-family:monospace;font-size:11px;word-break:break-all}
</style>
</head>
<body>
<div class="wrap">
  <p class="eyebrow">Ecstatic Dance Viseu</p>
  <h1>O teu lugar<br>está confirmado.</h1>
  <p class="sub">Guarda este email e apresenta o QR code na entrada.</p>
  <div class="qr-wrap">
    <img src="<?= htmlspecialchars($qr_url) ?>" alt="QR Code" width="220" height="220" />
  </div>
  <div class="details">
    <div class="row"><span class="lbl">Evento</span><span class="val"><?= htmlspecialchars($event_title) ?></span></div>
    <div class="row"><span class="lbl">Data</span><span class="val"><?= htmlspecialchars($date_fmt) ?></span></div>
    <div class="row"><span class="lbl">Nome</span><span class="val"><?= htmlspecialchars($to_name) ?></span></div>
    <div class="row"><span class="lbl">Valor pago</span><span class="val"><?= htmlspecialchars($amount_fmt) ?></span></div>
    <div class="row"><span class="lbl">Referência</span><span class="val id-mono"><?= htmlspecialchars($ticket_id) ?></span></div>
  </div>
  <p class="note">
    Este bilhete é pessoal e intransmissível. Apresenta o QR code na entrada — descalço, sóbrio, presente.<br><br>
    Dúvidas? <a class="link" href="mailto:info@ecstaticdanceviseu.pt">info@ecstaticdanceviseu.pt</a><br><br>
    <a class="link" href="<?= htmlspecialchars($app_url) ?>"><?= htmlspecialchars($app_url) ?></a>
  </p>
</div>
</body>
</html>
<?php
    $html = ob_get_clean();

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= 'From: ' . FROM_NAME . ' <' . FROM_EMAIL . ">\r\n";
    $headers .= "Reply-To: info@ecstaticdanceviseu.pt\r\n";
    $headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";

    $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    return mail($to_email, $encoded_subject, $html, $headers);
}

// ── Sanitise string input ──
function sanitise(string $val, int $max = 255): string {
    return mb_substr(trim(strip_tags($val)), 0, $max);
}
