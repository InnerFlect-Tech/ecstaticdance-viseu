<?php
/* ============================================================
   link-bookings.php — Reservas do formulário /links (SQLite/MySQL/JSON)
   ============================================================ */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_admin_session();

require_once __DIR__ . '/../api/link-common.php';

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
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex,nofollow" />
<title>Reservas /links — Admin</title>
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
  .empty { text-align: center; padding: 3rem; color: rgba(245,239,230,.25); font-style: italic; }

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
    <h1>Reservas (formulário /links)</h1>
    <p>
      Pedidos guardados na base configurada em <code style="font-size:.78rem;color:rgba(245,239,230,.35)">server/api/config.php</code>
      (SQLite, MySQL ou JSON em desenvolvimento). Total: <strong><?= (int)$n ?></strong>.
    </p>
  </div>

  <?php if ($loadError !== ''): ?>
    <div class="banner-err">
      Não foi possível ler os registos: <?= htmlspecialchars($loadError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <?php if ($n === 0 && $loadError === ''): ?>
    <p class="empty">Ainda não há pedidos — ou a base está vazia.</p>
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
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $pm = admin_link_payment_label((string)($r['payment_method'] ?? ''));
            $heard = admin_link_heard_label($r);
            $step2 = admin_link_step2_label($r);
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
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/_scanner-modal.php'; ?>
<?php require __DIR__ . '/_scanner-script.php'; ?>

</body>
</html>
