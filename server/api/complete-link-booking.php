<?php
/* POST /api/complete-link-booking.php
 * Passo 2: multipart (registration_id + proof file) OU JSON { registration_id, email_later: true } */

declare(strict_types=1);

require_once __DIR__ . '/link-common.php';

link_api_cors();
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    link_json_err('Method not allowed', 405);
}

$rid  = '';
$email_later = false;
$file_saved = null;
$mime = null;

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw, true);
    if (!is_array($d)) {
        link_json_err('JSON inválido.');
    }
    $rid = link_sanitise((string)($d['registration_id'] ?? ''), 36);
    $email_later = !empty($d['email_later']);
} elseif (str_contains($contentType, 'multipart/form-data')) {
    $rid = link_sanitise((string)($_POST['registration_id'] ?? ''), 36);
    $email_later = isset($_POST['email_later']) && (string)$_POST['email_later'] === '1';
    if (isset($_FILES['proof']) && is_array($_FILES['proof']) && $_FILES['proof']['error'] === UPLOAD_ERR_OK) {
        $f = $_FILES['proof'];
        if ($f['size'] > 5 * 1024 * 1024) {
            link_json_err('Ficheiro demasiado grande (máx. 5 MB).');
        }
        if (!class_exists('finfo')) {
            link_json_err('Servidor sem extensão fileinfo (contacta o hosting).', 500);
        }
        $finfo     = new finfo(FILEINFO_MIME_TYPE);
        $detected  = $finfo->file($f['tmp_name']) ?: '';
        $ext_by_mime = [
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'application/pdf' => 'pdf',
        ];
        if (!isset($ext_by_mime[$detected])) {
            link_json_err('Formato inválido. Usa PDF ou imagem (JPG, PNG, WebP).');
        }
        $ext = $ext_by_mime[$detected];
        $dest_name = $rid . '_proof_' . time() . '.' . $ext;
        $dir = link_proofs_dir();
        if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $dest_name)) {
            link_json_err('Não foi possível guardar o ficheiro.', 500);
        }
        $file_saved = 'link-proofs/' . $dest_name;
        $mime = $detected;
    } elseif (isset($_FILES['proof']) && $_FILES['proof']['error'] !== UPLOAD_ERR_NO_FILE) {
        link_json_err('Erro no envio do ficheiro.');
    }
} else {
    link_json_err('Content-Type inválido.');
}

if (strlen($rid) < 32) {
    link_json_err('registration_id em falta.');
}

try {
    $pdo = link_api_db();
} catch (Throwable $e) {
    link_json_err('Base de dados: ' . $e->getMessage(), 500);
}
$now = link_sql_now();

$q = $pdo->prepare('SELECT * FROM link_registrations WHERE id = ?');
$q->execute([$rid]);
$row = $q->fetch();
if (!$row) {
    link_json_err('Registo não encontrado.', 404);
}
if (!empty($row['step2_at'])) {
    link_json_err('Este pedido já foi concluído (passo 2).', 409);
}

if ($email_later && !$file_saved) {
    $u = $pdo->prepare(
        'UPDATE link_registrations SET
            step2_type = ?,
            proof_relpath = NULL,
            proof_mime = NULL,
            step2_at = ?,
            updated_at = ?
         WHERE id = ?'
    );
    $u->execute(['email_later', $now, $now, $rid]);
    $info = link_org_info();
    $line = "Passo 2 — comprovativo depois por email\nRef: {$row['payment_ref']}\nID: $rid\n"
        . "O participante indicou que envia o comprovativo para $info em seguida.\n";
    link_notify_team("Comprovativo depois — {$row['payment_ref']}", $line);
    link_json_ok(['status' => 'email_later', 'message' => 'Combinado. Envia o comprovativo para ' . $info . ' quando tiveres.']);
}

if ($file_saved) {
    $u = $pdo->prepare(
        'UPDATE link_registrations SET
            step2_type = ?,
            proof_relpath = ?,
            proof_mime = ?,
            step2_at = ?,
            updated_at = ?
         WHERE id = ?'
    );
    $u->execute(['upload', $file_saved, $mime, $now, $now, $rid]);
    $line = "Passo 2 — comprovativo carregado\nRef: {$row['payment_ref']}\nID: $rid\nFicheiro: $file_saved\n";
    link_notify_team("Comprovativo $rid", $line);
    link_json_ok(['status' => 'uploaded', 'message' => 'Obrigado! Recebemos o comprovativo.']);
}

link_json_err('Envia o comprovativo (ficheiro) ou escolhe enviar depois por email.');
