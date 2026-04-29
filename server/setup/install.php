<?php
/* ============================================================
   install.php — One-time database setup
   Access: https://ecstaticdanceviseu.pt/setup/install.php?token=YOUR_INSTALL_TOKEN
   DELETE THIS FILE after running it on the live server.
   ============================================================ */

require_once __DIR__ . '/../api/helpers.php';

header('Content-Type: text/html; charset=UTF-8');

// Token guard
$token = $_GET['token'] ?? '';
if (!hash_equals(INSTALL_TOKEN, $token)) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;color:#c00">403 — Token inválido.</p>');
}

$steps   = [];
$errors  = [];
$success = true;

// ── Step 1: Run schema.sql ──
$sql_file = __DIR__ . '/schema.sql';
if (!file_exists($sql_file)) {
    $errors[] = 'schema.sql não encontrado.';
    $success  = false;
} else {
    $sql = file_get_contents($sql_file);
    // Split on semicolons (skip comments and empty statements)
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => $s !== '' && !str_starts_with(ltrim($s), '--')
    );

    try {
        foreach ($statements as $stmt) {
            if (empty(trim($stmt))) continue;
            db()->exec($stmt);
        }
        $steps[] = '✓ Tabelas criadas (ou já existiam).';
    } catch (PDOException $e) {
        $errors[]  = 'Erro ao criar tabelas: ' . htmlspecialchars($e->getMessage());
        $success   = false;
    }
}

// ── Step 2: Verify tables ──
if ($success) {
    try {
        $tables = db()->query("SHOW TABLES LIKE 'events'")->fetchColumn();
        if ($tables) {
            $steps[] = '✓ Tabela <code>events</code> verificada.';
        } else {
            $errors[] = 'Tabela <code>events</code> não foi criada.';
            $success  = false;
        }

        $tables2 = db()->query("SHOW TABLES LIKE 'tickets'")->fetchColumn();
        if ($tables2) {
            $steps[] = '✓ Tabela <code>tickets</code> verificada.';
        } else {
            $errors[] = 'Tabela <code>tickets</code> não foi criada.';
            $success  = false;
        }
    } catch (PDOException $e) {
        $errors[] = 'Erro de verificação: ' . htmlspecialchars($e->getMessage());
        $success  = false;
    }
}

// ── Step 3: Verify admin password hash in config ──
if (defined('ADMIN_PASSWORD_HASH') && str_starts_with(ADMIN_PASSWORD_HASH, '$2y$')) {
    $steps[] = '✓ Hash de admin configurado.';
} else {
    $errors[] = '⚠ <code>ADMIN_PASSWORD_HASH</code> não está configurado em <code>config.php</code>.<br>'
              . '&nbsp;&nbsp;Gera um com: <code>php -r "echo password_hash(\'suapassword\', PASSWORD_DEFAULT);"</code>';
}

// ── Step 4: Stripe key check ──
if (defined('STRIPE_SECRET_KEY') && str_starts_with(STRIPE_SECRET_KEY, 'sk_')) {
    $mode    = str_starts_with(STRIPE_SECRET_KEY, 'sk_live_') ? 'LIVE' : 'TEST';
    $steps[] = "✓ Stripe key configurada (<strong>{$mode}</strong>).";
} else {
    $errors[] = '⚠ <code>STRIPE_SECRET_KEY</code> não configurada.';
}

?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<title>Install — Ecstatic Dance Viseu</title>
<style>
  body { font-family: monospace; background: #0E0B09; color: #F5EFE6; padding: 3rem; max-width: 700px; line-height: 1.7; }
  h1 { font-size: 1.5rem; font-weight: normal; color: #B8924A; margin-bottom: 2rem; }
  .step  { color: #40916c; margin: .4rem 0; }
  .error { color: #e07050; margin: .4rem 0; }
  .warn  { color: #B8924A; margin: 1.5rem 0; padding: 1rem; border: 1px solid rgba(184,146,74,.3); font-size: .9rem; }
  hr { border: none; border-top: 1px solid rgba(245,239,230,.1); margin: 2rem 0; }
  code { background: rgba(245,239,230,.08); padding: .1em .4em; border-radius: 2px; }
  a { color: #B8924A; }
</style>
</head>
<body>
<h1>Ecstatic Dance Viseu — Setup</h1>

<?php foreach ($steps as $s): ?>
  <div class="step"><?= $s ?></div>
<?php endforeach; ?>

<?php foreach ($errors as $e): ?>
  <div class="error">✗ <?= $e ?></div>
<?php endforeach; ?>

<hr>

<?php if ($success): ?>
  <p>✓ <strong>Instalação concluída com sucesso.</strong></p>
  <div class="warn">
    ⚠ <strong>Apaga este ficheiro agora.</strong><br>
    Via SSH: <code>rm /home/cpaneluser/public_html/setup/install.php</code><br>
    Ou elimina a pasta <code>/setup/</code> no File Manager do cPanel.
  </div>
  <p>Próximos passos:</p>
  <ul style="margin-top:1rem;padding-left:1.5rem">
    <li>Cria o primeiro evento via phpMyAdmin (ver <code>schema.sql</code> para INSERT de exemplo).</li>
    <li>Configura o webhook Stripe: <code><?= htmlspecialchars(APP_URL) ?>/api/webhook.php</code></li>
    <li>Testa a reserva em modo sandbox: <a href="<?= htmlspecialchars(APP_URL) ?>/bilhetes"><?= htmlspecialchars(APP_URL) ?>/bilhetes</a></li>
    <li>Acede ao admin: <a href="<?= htmlspecialchars(APP_URL) ?>/admin/"><?= htmlspecialchars(APP_URL) ?>/admin/</a></li>
  </ul>
<?php else: ?>
  <p>✗ <strong>Instalação com erros. Corrige os problemas acima e tenta novamente.</strong></p>
<?php endif; ?>

</body>
</html>
