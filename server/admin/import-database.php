<?php
declare(strict_types=1);
/**
 * import-database.php — Restore from SQL backup (export-database.php) or replace SQLite file.
 */

require_once __DIR__ . '/auth.php';
require_admin_session();

require_once __DIR__ . '/../api/helpers.php';
require_once __DIR__ . '/database-backup-lib.php';

const ADMIN_IMPORT_MAX_BYTES = 15 * 1024 * 1024;

$flash = '';
$flashOk = false;
$driver = db_driver();
$isSqliteMain = $driver === 'sqlite';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirm = (string) ($_POST['confirm_replace'] ?? '') === '1';
    if (!$confirm) {
        $flash = 'Marca a confirmação antes de importar.';
    } elseif (!isset($_FILES['backup_file']) || !is_array($_FILES['backup_file'])) {
        $flash = 'Escolhe um ficheiro .sql ou .sqlite.';
    } else {
        $file = $_FILES['backup_file'];
        $err  = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            $flash = $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE
                ? 'Ficheiro demasiado grande (máx. ~15 MB).'
                : 'Erro no upload (código ' . $err . ').';
        } else {
            $tmp  = (string) ($file['tmp_name'] ?? '');
            $name = (string) ($file['name'] ?? '');
            $size = (int) ($file['size'] ?? 0);
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                $flash = 'Upload inválido.';
            } elseif ($size <= 0 || $size > ADMIN_IMPORT_MAX_BYTES) {
                $flash = 'Tamanho inválido (máx. 15 MB).';
            } else {
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if ($ext === 'sqlite' || $ext === 'db') {
                    if (!$isSqliteMain) {
                        $flash = 'Ficheiros .sqlite só podem ser importados quando a base principal é SQLite.';
                    } else {
                        $result = admin_backup_import_sqlite_file($tmp);
                        $flashOk = $result['ok'];
                        $flash   = $result['message'];
                    }
                } elseif ($ext === 'sql') {
                    $sql = file_get_contents($tmp);
                    if (!is_string($sql) || trim($sql) === '') {
                        $flash = 'Ficheiro SQL vazio.';
                    } else {
                        $result = admin_backup_import_uploaded_sql($sql);
                        $flashOk = $result['ok'];
                        $flash   = $result['message'];
                    }
                } else {
                    $flash = 'Formato não suportado. Usa .sql (backup da app) ou .sqlite (cópia do ficheiro da base).';
                }
            }
        }
    }
}

function imp_h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex,nofollow" />
  <title>Importar backup — Admin</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root { --dark:#0E0B09; --dark-m:#1A1210; --bone:#F5EFE6; --gold:#D4A85A; --ok:#2d6a4f; --bad:#c45d4a; }
    body { background: var(--dark); color: var(--bone); font-family: Arial, sans-serif; font-size: 14px; }
    <?php require __DIR__ . '/_topbar-styles.php'; ?>
    .main { max-width: 640px; margin: 0 auto; padding: 1.2rem 1rem 2.5rem; }
    .head h1 { font-weight: 300; font-size: 1.5rem; }
    .head p { color: rgba(245,239,230,.55); font-size: .82rem; margin-top: .35rem; line-height: 1.5; }
    .panel { background: var(--dark-m); border: 1px solid rgba(245,239,230,.08); border-radius: 10px; padding: 1rem; margin-top: 1rem; }
    .warn { background: rgba(196,93,74,.12); border: 1px solid rgba(196,93,74,.4); padding: .75rem .9rem; border-radius: 8px; font-size: .82rem; line-height: 1.55; margin-top: 1rem; }
    .flash { padding: .65rem .85rem; margin-top: 1rem; border-radius: 8px; font-size: .82rem; line-height: 1.5; }
    .flash.ok { background: rgba(45,106,79,.18); border: 1px solid rgba(45,106,79,.36); }
    .flash.bad { background: rgba(196,93,74,.15); border: 1px solid rgba(196,93,74,.35); }
    label { display: block; font-size: .72rem; letter-spacing: .08em; text-transform: uppercase; color: rgba(245,239,230,.4); margin: .85rem 0 .35rem; }
    input[type="file"] { width: 100%; font-size: .82rem; color: var(--bone); }
    .confirm { display: flex; gap: .5rem; align-items: flex-start; margin-top: 1rem; font-size: .82rem; line-height: 1.45; color: rgba(245,239,230,.75); }
    .confirm input { margin-top: .2rem; }
    .btn { margin-top: 1rem; border: 1px solid rgba(212,168,90,.45); background: rgba(212,168,90,.12); color: var(--bone); cursor: pointer;
      padding: .5rem 1rem; border-radius: 8px; font-size: .72rem; text-transform: uppercase; letter-spacing: .08em; }
    .btn:hover { border-color: var(--gold); }
    .btn-secondary { border-color: rgba(245,239,230,.18); background: rgba(245,239,230,.06); text-decoration: none; display: inline-block; margin-right: .5rem; }
    .help { font-size: .78rem; color: rgba(245,239,230,.5); line-height: 1.55; margin-top: .75rem; }
    .help a { color: var(--gold); }
    ul { margin: .5rem 0 0 1.1rem; }
  </style>
</head>
<body class="has-bottom-tabs">
<?php
$__adminNav = 'tickets';
require __DIR__ . '/_topbar.php';
?>

<main class="main">
  <div class="head">
    <h1>Importar backup</h1>
    <p>Restaura dados exportados em <strong>localhost</strong> para esta instalação (ex. Coolify). Motor actual: <code><?= imp_h($driver) ?></code>.</p>
  </div>

  <div class="warn">
    <strong>Atenção:</strong> a importação <strong>substitui</strong> tabelas e dados existentes (DROP + CREATE + INSERT no .sql).
    Faz um <a href="/admin/export-database.php">backup</a> desta instalação antes de continuar se já houver bilhetes ou reservas reais.
  </div>

  <?php if ($flash !== ''): ?>
    <div class="flash <?= $flashOk ? 'ok' : 'bad' ?>"><?= imp_h($flash) ?></div>
  <?php endif; ?>

  <div class="panel">
    <form method="post" enctype="multipart/form-data">
      <label for="backup_file">Ficheiro de backup</label>
      <input type="file" id="backup_file" name="backup_file" accept=".sql,.sqlite,.db" required />

      <label class="confirm">
        <input type="checkbox" name="confirm_replace" value="1" required />
        <span>Percebo que isto substitui os dados actuais desta base.</span>
      </label>

      <button type="submit" class="btn">Importar</button>
    </form>

    <p class="help">
      <strong>.sql</strong> — ficheiro descarregado em <a href="/admin/export-database.php">Exportar backup SQL</a> (recomendado; mesmo formato em dev e produção).<br />
      <?php if ($isSqliteMain): ?>
      <strong>.sqlite</strong> — cópia directa de <code>server/data/events-tickets.sqlite</code> (substitui o ficheiro inteiro; cria <code>.bak-…</code> antes).<br />
      <?php endif; ?>
      Fluxo típico: localhost → exportar SQL → abrir <code>https://ecstaticdanceviseu.pt/admin/import-database.php</code> → importar.
    </p>
    <ul class="help">
      <li>Exporta e importa no mesmo tipo de base (SQLite ↔ SQLite, MySQL ↔ MySQL).</li>
      <li>Após importar .sqlite, recarrega o admin para ver os dados novos.</li>
    </ul>
  </div>

  <p style="margin-top:1rem;">
    <a href="/admin/export-database.php" class="btn btn-secondary">Exportar backup SQL</a>
    <a href="/admin/" class="btn btn-secondary">Voltar ao admin</a>
  </p>
</main>
</body>
</html>
