<?php
/* ============================================================
   link-bookings.php — Reservas do formulário /links (SQLite/MySQL/JSON)
   ============================================================ */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_admin_session();

require_once __DIR__ . '/../api/link-common.php';
require_once __DIR__ . '/../api/link-confirm.php';
require_once __DIR__ . '/../api/link-registrations-edv-2026-05-23-seed.php';
require_once __DIR__ . '/../api/attendance.php';

/**
 * @param array<string,mixed> $row
 */
function admin_link_heard_label(array $row): string {
    $h = (string)($row['heard_from'] ?? '');
    $other = trim((string)($row['heard_other'] ?? ''));
    return match ($h) {
        'instagram' => 'Instagram',
        'facebook'  => 'Facebook',
        'friends'   => 'Amigos / Friends',
        'mailing'   => 'Mailing list',
        'whatsapp'  => 'WhatsApp',
        'telegram'  => 'Telegram',
        'other'     => $other !== '' ? 'Outro: ' . $other : 'Outro',
        default     => $h,
    };
}

function admin_link_payment_label(string $m): string {
    return match ($m) {
        'mbway'    => 'MB Way',
        'transfer' => 'Multibanco / transferência',
        'revolut'  => 'Revolut',
        default    => $m,
    };
}

/**
 * @param array<string,mixed> $row
 */
function admin_link_status_label(array $row): string {
    if (!empty($row['ticket_id']) || !empty($row['confirmed_at'])) {
        return 'Confirmado';
    }
    if (!empty($row['step2_at'])) {
        return 'A verificar';
    }
    return 'Aguarda passo 2';
}

function admin_link_step2_label(array $row): string {
    if (empty($row['step2_at'])) {
        return 'Passo 2 pendente';
    }
    $t = (string)($row['step2_type'] ?? '');
    if ($t === 'upload') {
        return 'Comprovativo enviado';
    }
    if ($t === 'email_later') {
        return 'Envio por email depois';
    }
    return '—';
}

/**
 * @param string $proofRelpath
 */
function admin_link_delete_proof_file(string $proofRelpath): void {
    $clean = str_replace(['../', '..\\'], '', $proofRelpath);
    $clean = str_replace('\\', '/', $clean);
    if ($clean === '') {
        return;
    }
    $baseUploads = realpath(dirname(__DIR__) . '/uploads');
    if (!is_string($baseUploads) || $baseUploads === '') {
        return;
    }
    $target = realpath($baseUploads . '/' . $clean);
    if (!is_string($target) || $target === '') {
        return;
    }
    if (!str_starts_with($target, $baseUploads . DIRECTORY_SEPARATOR)) {
        return;
    }
    if (is_file($target)) {
        @unlink($target);
    }
}

$flashMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'confirm_registration') {
    $confirmId = link_sanitise((string) ($_POST['registration_id'] ?? ''), 36);
    if (strlen($confirmId) < 32) {
        $flashMessage = 'ID de registo inválido.';
    } else {
        $result = link_confirm_registration($confirmId);
        if (!empty($result['ok'])) {
            if (!empty($result['already'])) {
                $flashMessage = 'Este pedido já estava confirmado (bilhete ' . ($result['ticket_id'] ?? '') . ').';
            } elseif (!empty($result['error'])) {
                $flashMessage = $result['error'];
            } else {
                $flashMessage = 'Bilhete confirmado e email enviado ao participante (ID ' . ($result['ticket_id'] ?? '') . ').';
            }
        } else {
            $flashMessage = $result['error'] ?? 'Não foi possível confirmar.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'delete_registration') {
    $deleteId = link_sanitise((string)($_POST['registration_id'] ?? ''), 36);
    if (strlen($deleteId) < 32) {
        $flashMessage = 'ID de registo inválido.';
    } else {
        try {
            $backend = link_registration_backend();
            $proofRelpath = null;
            $deleted = false;
            if ($backend === 'json') {
                $res = link_json_delete_registration($deleteId);
                $deleted = !empty($res['deleted']);
                $proofRelpath = isset($res['proof_relpath']) ? (string)$res['proof_relpath'] : null;
            } else {
                $pdo = link_api_db();
                $q = $pdo->prepare('SELECT proof_relpath FROM link_registrations WHERE id = ?');
                $q->execute([$deleteId]);
                $row = $q->fetch(PDO::FETCH_ASSOC);
                $proofRelpath = is_array($row) && isset($row['proof_relpath']) ? (string)$row['proof_relpath'] : null;
                $d = $pdo->prepare('DELETE FROM link_registrations WHERE id = ?');
                $d->execute([$deleteId]);
                $deleted = $d->rowCount() > 0;
            }
            if ($deleted) {
                if (is_string($proofRelpath) && $proofRelpath !== '') {
                    admin_link_delete_proof_file($proofRelpath);
                }
                $flashMessage = 'Inscrição apagada com sucesso.';
            } else {
                $flashMessage = 'Registo não encontrado (já removido).';
            }
        } catch (Throwable $e) {
            $flashMessage = 'Erro a apagar registo: ' . $e->getMessage();
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'reseed_edv_2026_05_23') {
    if ((string) ($_POST['confirm_reseed'] ?? '') !== '1') {
        $flashMessage = 'Confirma a substituição das inscrições do evento 23/05/2026.';
    } else {
        try {
            $linkResult = edv_link_registrations_apply_2026_05_23_seed(link_api_db());
            $attResult  = edv_attendance_reseed_event_01_from_roster(db());
            $flashMessage = sprintf(
                'Inscrições #01: %d removidas, %d gravadas. Presenças: %d bilhetes (%d presentes, %d ausentes).',
                (int) $linkResult['deleted'],
                (int) $linkResult['inserted'],
                (int) ($attResult['matched'] ?? 0) + (int) ($attResult['created'] ?? 0),
                (int) ($attResult['present'] ?? 0),
                (int) ($attResult['absent'] ?? 0)
            );
            if ($attResult === null) {
                $flashMessage .= ' (evento 2026-05-23 não encontrado na base principal — cria-o em Eventos.)';
            }
        } catch (Throwable $e) {
            $flashMessage = 'Erro ao repor dados: ' . $e->getMessage();
        }
    }
}

$loadError = '';
try {
    $rows = link_registrations_all();
} catch (Throwable $e) {
    http_response_code(500);
    $rows = [];
    $loadError = $e->getMessage();
}

/** @var list<array<string,mixed>> $rows */

if (isset($_GET['export']) && (string)$_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="reservas-links-' . date('Y-m-d') . '.csv"'
    );
    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, [
            'step1_at',
            'payment_ref',
            'event_slug',
            'name',
            'email',
            'phone',
            'ticket_euros',
            'total_euros',
            'dinner_note',
            'payment_method',
            'heard_from',
            'heard_other',
            'step2_type',
            'step2_at',
            'proof_relpath',
            'proof_mime',
        ], ';');
        foreach ($rows as $r) {
            fputcsv($out, [
                (string)($r['step1_at'] ?? ''),
                (string)($r['payment_ref'] ?? ''),
                (string)($r['event_slug'] ?? ''),
                (string)($r['name'] ?? ''),
                (string)($r['email'] ?? ''),
                (string)($r['phone'] ?? ''),
                (string)($r['ticket_euros'] ?? ''),
                (string)($r['total_euros'] ?? ''),
                (string)($r['dinner_note'] ?? ''),
                (string)($r['payment_method'] ?? ''),
                (string)($r['heard_from'] ?? ''),
                (string)($r['heard_other'] ?? ''),
                (string)($r['step2_type'] ?? ''),
                (string)($r['step2_at'] ?? ''),
                (string)($r['proof_relpath'] ?? ''),
                (string)($r['proof_mime'] ?? ''),
            ], ';');
        }
        fclose($out);
    }
    exit;
}

$n = count($rows);

$linkStore = link_registrations_storage_info();
$sqlitePath = (string) ($linkStore['detail'] ?? '');
$isCoolifySqlite = $linkStore['mode'] === 'sqlite'
    && (str_contains($sqlitePath, '/app/server/data/')
        || str_contains($sqlitePath, '/var/www/edv-server/data/'));
$sqlitePersistenceHint = $isCoolifySqlite
    ? 'Coolify: volume persistente em <code>/var/www/edv-server/data</code> (ver <code>EDV_*_SQLITE_PATH</code> e <code>docs/COOLIFY.md</code>).'
    : 'Garante armazenamento persistente para o directorio da base; sem volume, os dados podem desaparecer entre redeploys.';
$linkStoreHint = match ($linkStore['mode']) {
    'json'   => 'Modo JSON (só desenvolvimento). Ficheiro: ' . htmlspecialchars($linkStore['detail'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    'sqlite' => 'SQLite — ficheiro: ' . htmlspecialchars($linkStore['detail'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . ' (' . $sqlitePersistenceHint . ')',
    'mysql'  => 'MySQL — ' . htmlspecialchars($linkStore['detail'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . ' (tabela <code>link_registrations</code>; em produção mantém <code>LINK_USE_SQLITE</code> e <code>LINK_USE_JSON</code> a <strong>false</strong>.)',
};
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex,nofollow" />
<title>Inscrições · /links — Admin</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --dark: #0E0B09; --dark-m: #1A1210; --dark-l: #2A1E1A;
    --bone: #F5EFE6;
    --terra: #8B3A2A; --terra-l: #C4593F;
    --gold: #B8924A; --gold-l: #D4A85A;
    --verde: #1E2E27; --verde-m: #2A3D35;
    --success: #2d6a4f;
  }
  body { background: var(--dark); color: var(--bone); font-family: Arial, sans-serif; font-weight: 300; font-size: 14px; }
  a { color: inherit; text-decoration: none; }

  <?php require __DIR__ . '/_topbar-styles.php'; ?>

  .main { padding: 2rem; max-width: 1400px; margin: 0 auto; }
  .page-header { margin-bottom: 2rem; }
  .page-header h1 { font-size: 1.5rem; font-weight: 300; color: var(--bone); margin-bottom: .4rem; }
  .page-header p  { font-size: .82rem; color: rgba(245,239,230,.45); line-height: 1.5; }

  .banner-err { background: rgba(196,89,63,.15); border: 1px solid rgba(196,89,63,.35);
                padding: 1rem 1.2rem; margin-bottom: 1.5rem; font-size: .88rem; line-height: 1.5; color: var(--bone); }

  .table-wrap { overflow-x: auto; border: 1px solid rgba(245,239,230,.08); }
  table { width: 100%; border-collapse: collapse; font-size: .8rem; min-width: 920px; }
  th { font-size: .62rem; letter-spacing: .14em; text-transform: uppercase; color: rgba(245,239,230,.35);
       font-weight: 400; padding: .75rem 1rem; text-align: left; border-bottom: 1px solid rgba(245,239,230,.1);
       white-space: nowrap; background: var(--dark-m); position: sticky; top: 0; z-index: 1; }
  td { padding: .7rem 1rem; border-bottom: 1px solid rgba(245,239,230,.06); color: rgba(245,239,230,.8);
        vertical-align: top; line-height: 1.45; }
  tr:hover td { background: rgba(245,239,230,.03); }
  .mono { font-family: ui-monospace, monospace; font-size: .72rem; color: rgba(245,239,230,.55); word-break: break-all; }
  .badge { display: inline-block; padding: .22rem .55rem; font-size: .62rem; letter-spacing: .08em; text-transform: uppercase; font-weight: 400; border-radius: 2px; }
  .badge-wait { background: rgba(245,239,230,.08); color: rgba(245,239,230,.5); }
  .badge-ok { background: rgba(45,106,79,.25); color: #6bcf9a; }
  .badge-mail { background: rgba(184,146,74,.15); color: var(--gold-l); }
  .proof-link { color: var(--gold-l); text-decoration: underline; font-size: .78rem; }
  .proof-link:hover { color: var(--bone); }
  .empty { text-align: center; padding: 3rem; color: rgba(245,239,230,.35); }
  .empty-hints { max-width: 36rem; margin: 1rem auto 0; text-align: left; font-style: normal;
    font-size: .8rem; line-height: 1.55; color: rgba(245,239,230,.45); list-style: disc; padding-left: 1.25rem; }
  .empty-hints li { margin-bottom: .65rem; }
  .empty-hints a { color: var(--gold-l); text-decoration: underline; }
  .empty-hints code { font-size: .72rem; color: rgba(245,239,230,.4); }
  .banner-info { background: rgba(45,106,79,.12); border: 1px solid rgba(45,106,79,.28);
    padding: .85rem 1.1rem; margin-bottom: 1.25rem; font-size: .78rem; line-height: 1.55; color: rgba(245,239,230,.82); }
  .banner-info code { font-size: .72rem; color: rgba(245,239,230,.45); }
  .banner-ok { background: rgba(45,106,79,.2); border: 1px solid rgba(45,106,79,.35);
    padding: .75rem 1rem; margin-bottom: 1rem; font-size: .82rem; color: rgba(245,239,230,.9); }
  .btn-delete { appearance:none; border:1px solid rgba(196,89,63,.35); background:rgba(196,89,63,.12);
    color:#f1d5cf; padding:.34rem .55rem; font-size:.68rem; letter-spacing:.04em; text-transform:uppercase;
    border-radius:3px; cursor:pointer; }
  .btn-delete:hover { background: rgba(196,89,63,.2); border-color: rgba(196,89,63,.55); }
  .btn-confirm { appearance:none; display:block; width:100%; margin-bottom:.45rem;
    border:1px solid rgba(45,106,79,.45); background:rgba(45,106,79,.22); color:#8fd4a8;
    padding:.4rem .55rem; font-size:.68rem; letter-spacing:.06em; text-transform:uppercase;
    border-radius:3px; cursor:pointer; }
  .btn-confirm:hover { background:rgba(45,106,79,.35); color:var(--bone); }
  .badge-confirmed { background:rgba(45,106,79,.2); color:#8fd4a8; }
  .seed-panel { background: rgba(184,146,74,.08); border: 1px solid rgba(184,146,74,.28);
    padding: .85rem 1rem; margin-bottom: 1.25rem; border-radius: 6px; font-size: .78rem; line-height: 1.55; }
  .seed-panel form { margin-top: .65rem; display: flex; flex-wrap: wrap; gap: .65rem; align-items: center; }
  .seed-panel label { font-size: .78rem; color: rgba(245,239,230,.75); display: flex; gap: .4rem; align-items: center; }
  .btn-seed { appearance:none; border:1px solid rgba(184,146,74,.45); background:rgba(184,146,74,.15); color:var(--gold-l);
    padding:.4rem .7rem; font-size:.68rem; letter-spacing:.06em; text-transform:uppercase; border-radius:3px; cursor:pointer; }
  .btn-seed:hover { background:rgba(184,146,74,.28); }

  <?php require __DIR__ . '/_scanner-styles.php'; ?>
</style>
</head>
<body class="has-bottom-tabs">

<?php
$__adminNav = 'links';
$__exportEventId = null;
$__secondaryCsvHref = $n > 0 ? '/admin/link-bookings.php?export=csv' : null;
require __DIR__ . '/_topbar.php';
?>

<div class="main">
  <div class="page-header">
    <h1>Inscrições · reservas manuais (/links)</h1>
    <p>
      Pedidos do formulário «Pedir bilhete» em <code style="font-size:.78rem;color:rgba(245,239,230,.35)">/links</code>.
      Total nesta origem: <strong><?= (int)$n ?></strong>.
      Após o passo 2, o participante recebe email automático de «pedido recebido»; quando verificares o pagamento, usa
      <strong>Confirmar e enviar bilhete</strong> para criar o QR e enviar o email final.
    </p>
  </div>

  <?php if ($loadError === ''): ?>
    <div class="seed-panel">
      <strong>Dados de produção — edição 23/05/2026</strong><br />
      Repõe as 10 inscrições reais do /links (referências BBY447, DJR787, …) e actualiza a lista em
      <a href="/admin/attendance.php" style="color:var(--gold-l)">Presenças</a> com emails e telemóveis.
      <form method="post" onsubmit="return confirm('Substituir todas as inscrições edv-2026-05-23 e bilhetes da edição #01?');">
        <input type="hidden" name="action" value="reseed_edv_2026_05_23" />
        <label><input type="checkbox" name="confirm_reseed" value="1" required /> Substituir inscrições e bilhetes #01</label>
        <button type="submit" class="btn-seed">Importar lista de produção</button>
      </form>
    </div>
    <div class="banner-info" role="status">
      <?= $linkStoreHint ?>
    </div>
  <?php endif; ?>

  <?php if ($flashMessage !== ''): ?>
    <div class="banner-ok" role="status">
      <?= htmlspecialchars($flashMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <?php if ($loadError !== ''): ?>
    <div class="banner-err">
      Não foi possível ler os registos: <?= htmlspecialchars($loadError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <?php if ($n === 0 && $loadError === ''): ?>
    <div class="empty">
      <p>Nenhum registo nesta origem de dados.</p>
      <ul class="empty-hints">
        <li>
          Se já recebeste pedidos em produção (<code>ecstaticdanceviseu.pt</code>), o painel tem de usar o <strong>mesmo</strong> modo
          de armazenamento que o servidor onde o formulário gravou (em cPanel típico: MySQL com
          <code>LINK_USE_SQLITE=false</code> e <code>LINK_USE_JSON=false</code>). Se o exemplo Docker copiou config com SQLite,
          o site pode estar noutra base do que esta instância do admin.
        </li>
        <li>Bilhetes Stripe e QR de check-in listam-se em <a href="/admin/">Check-in</a> (tabela <code>tickets</code>), não aqui.</li>
      </ul>
    </div>
  <?php elseif ($n > 0): ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Data</th>
            <th>Ref.</th>
            <th>Nome / contacto</th>
            <th>Valores</th>
            <th>Pagamento</th>
            <th>Origem</th>
            <th>Passo 2</th>
            <th>Estado</th>
            <th>Ações</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $pm = admin_link_payment_label((string)($r['payment_method'] ?? ''));
            $heard = admin_link_heard_label($r);
            $step2 = admin_link_step2_label($r);
            $status = admin_link_status_label($r);
            $canConfirm = !empty($r['step2_at']) && empty($r['ticket_id']) && empty($r['confirmed_at']);
            $isConfirmed = !empty($r['ticket_id']) || !empty($r['confirmed_at']);
            $ticket = isset($r['ticket_euros']) ? (float)$r['ticket_euros'] : 0.0;
            $total = isset($r['total_euros']) ? (float)$r['total_euros'] : 0.0;
            $dinnerNote = trim((string)($r['dinner_note'] ?? ''));
            $hasDinner = $total > $ticket + 0.01;
            ?>
          <tr>
            <td class="mono"><?= htmlspecialchars((string)($r['step1_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td>
              <span class="mono"><?= htmlspecialchars((string)($r['payment_ref'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <?php if (!empty($r['event_slug'])): ?>
                <div class="mono" style="margin-top:.35rem;font-size:.68rem"><?= htmlspecialchars((string)$r['event_slug'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <?php endif; ?>
            </td>
            <td>
              <strong><?= htmlspecialchars((string)($r['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><br />
              <span style="color:rgba(245,239,230,.55)"><?= htmlspecialchars((string)($r['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><br />
              <span class="mono"><?= htmlspecialchars((string)($r['phone'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </td>
            <td>
              Bilhete <?= number_format($ticket, 2, ',', ' ') ?> €<br />
              Total <strong><?= number_format($total, 2, ',', ' ') ?> €</strong>
              <?php if ($hasDinner): ?>
                <br /><span style="font-size:.72rem;color:rgba(245,239,230,.45)">Com jantar</span>
              <?php endif; ?>
              <?php if ($dinnerNote !== ''): ?>
                <br /><span style="font-size:.72rem;color:rgba(245,239,230,.45)"><?= htmlspecialchars($dinnerNote, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($pm, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($heard, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td>
              <?php if (empty($r['step2_at'])): ?>
                <span class="badge badge-wait"><?= htmlspecialchars($step2, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <?php elseif (($r['step2_type'] ?? '') === 'upload'): ?>
                <span class="badge badge-ok"><?= htmlspecialchars($step2, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php if (!empty($r['proof_relpath'])): ?>
                  <div style="margin-top:.5rem">
                    <?php
                      $__proof = str_replace(['../', '..\\'], '', (string)$r['proof_relpath']);
                      $__proof = str_replace('\\', '/', $__proof);
                    ?>
                    <a class="proof-link" href="/uploads/<?= htmlspecialchars($__proof, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener">Ver ficheiro</a>
                  </div>
                <?php endif; ?>
              <?php else: ?>
                <span class="badge badge-mail"><?= htmlspecialchars($step2, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <?php endif; ?>
              <?php if (!empty($r['step2_at'])): ?>
                <div class="mono" style="margin-top:.45rem;font-size:.68rem"><?= htmlspecialchars((string)$r['step2_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($isConfirmed): ?>
                <span class="badge badge-confirmed"><?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php if (!empty($r['ticket_id'])): ?>
                  <div class="mono" style="margin-top:.45rem;font-size:.65rem;word-break:break-all">
                    <a class="proof-link" href="/admin/?q=<?= urlencode((string) $r['email']) ?>">Bilhete → check-in</a>
                  </div>
                <?php endif; ?>
              <?php elseif (!empty($r['step2_at'])): ?>
                <span class="badge badge-wait"><?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <?php else: ?>
                <span class="badge badge-wait"><?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($canConfirm): ?>
                <form method="post" onsubmit="return confirm('Confirmar pagamento e enviar bilhete com QR por email ao participante?');">
                  <input type="hidden" name="action" value="confirm_registration" />
                  <input type="hidden" name="registration_id" value="<?= htmlspecialchars((string)($r['id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
                  <button type="submit" class="btn-confirm">Confirmar e enviar bilhete</button>
                </form>
              <?php endif; ?>
              <form method="post" onsubmit="return confirm('Apagar esta inscrição? Esta ação não pode ser desfeita.');">
                <input type="hidden" name="action" value="delete_registration" />
                <input type="hidden" name="registration_id" value="<?= htmlspecialchars((string)($r['id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
                <button type="submit" class="btn-delete">Apagar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>


</body>
</html>
