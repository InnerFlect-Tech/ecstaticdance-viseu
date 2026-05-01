<?php
/**
 * Boat Sunset — Tickets API
 * GET: list all tickets | POST: save tickets (full sync)
 * Requires ?password=... or X-Password header. No cache.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
  http_response_code(500);
  echo json_encode(['error' => 'Configure api/config.php first']);
  exit;
}
$config = require $configPath;

$password = $_GET['password'] ?? ($_SERVER['HTTP_X_PASSWORD'] ?? '');
if ($password !== $config['password_admin']) {
  http_response_code(401);
  echo json_encode(['error' => 'Unauthorized']);
  exit;
}

try {
  $pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
} catch (PDOException $e) {
  http_response_code(500);
  $msg = 'Database connection failed';
  if (!empty($config['debug'])) {
    $msg .= ': ' . $e->getMessage();
  }
  echo json_encode(['error' => $msg]);
  exit;
}

// Create table if not exists
$pdo->exec("
  CREATE TABLE IF NOT EXISTS boat_sunset_tickets (
    id VARCHAR(64) PRIMARY KEY,
    event_id VARCHAR(64),
    full_name VARCHAR(255),
    email VARCHAR(255),
    tier_label VARCHAR(64),
    price_thb INT,
    purchase_date_iso VARCHAR(64),
    qr_payload TEXT,
    status VARCHAR(16),
    paid TINYINT(1) DEFAULT 0,
    scanned TINYINT(1) DEFAULT 0,
    referred_by VARCHAR(255) DEFAULT ''
  )
");

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
  $stmt = $pdo->query("SELECT * FROM boat_sunset_tickets ORDER BY purchase_date_iso DESC");
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $tickets = array_map(function ($r) {
    return [
      'id' => $r['id'],
      'eventId' => $r['event_id'],
      'fullName' => $r['full_name'],
      'email' => $r['email'],
      'tierLabel' => $r['tier_label'],
      'priceTHB' => (int) $r['price_thb'],
      'purchaseDateISO' => $r['purchase_date_iso'],
      'qrPayload' => $r['qr_payload'],
      'status' => $r['status'] ?: 'valid',
      'paid' => (bool) $r['paid'],
      'scanned' => (bool) $r['scanned'],
      'referredBy' => $r['referred_by'] ?? '',
    ];
  }, $rows);
  echo json_encode($tickets);
  exit;
}

if ($method === 'POST') {
  $body = json_decode(file_get_contents('php://input'), true) ?? [];
  $postPassword = $body['password'] ?? ($_SERVER['HTTP_X_PASSWORD'] ?? '');
  if ($postPassword !== $config['password_admin']) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
  }
  if (!is_array($body) || !isset($body['tickets']) || !is_array($body['tickets'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Body must be { "tickets": [...] }']);
    exit;
  }
  $tickets = $body['tickets'];

  $pdo->beginTransaction();
  try {
    $pdo->exec("DELETE FROM boat_sunset_tickets");
    $stmt = $pdo->prepare("
      INSERT INTO boat_sunset_tickets
      (id, event_id, full_name, email, tier_label, price_thb, purchase_date_iso, qr_payload, status, paid, scanned, referred_by)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($tickets as $t) {
      $stmt->execute([
        $t['id'] ?? '',
        $t['eventId'] ?? 'boat-sunset-2025',
        $t['fullName'] ?? '',
        $t['email'] ?? '',
        $t['tierLabel'] ?? 'Regular',
        (int) ($t['priceTHB'] ?? 1600),
        $t['purchaseDateISO'] ?? date('c'),
        $t['qrPayload'] ?? '',
        $t['status'] ?? 'valid',
        !empty($t['paid']) ? 1 : 0,
        !empty($t['scanned']) ? 1 : 0,
        $t['referredBy'] ?? '',
      ]);
    }
    $pdo->commit();
    echo json_encode($tickets);
  } catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Save failed']);
  }
  exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
