<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/discount-codes.php';
require_admin_session();

function dc_h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$pdo = db();
edv_discount_codes_ensure_schema($pdo);
$flash = '';
$previewRecipients = [];
$previewExcluded = [];
$previewEventId = 0;
$previewMin = 15.0;

$events = $pdo->query(
    'SELECT id, title, date, is_active, returning_min_eur
     FROM events ORDER BY date DESC LIMIT 30'
)->fetchAll();

$selectedCampaignId = (int) ($_GET['campaign_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'prepare_recipients') {
        $previewEventId = (int) ($_POST['event_id'] ?? 0);
        $previewMin = max(0.0, (float) ($_POST['min_eur'] ?? 15));
        if ($previewEventId <= 0) {
            $flash = 'Selecciona um evento.';
        } else {
            $analysis = edv_discount_recipient_analysis_for_event($previewEventId);
            $previewRecipients = $analysis['recipients'];
            $previewExcluded = $analysis['excluded'];
            if ($previewRecipients === []) {
                $flash = 'Nenhum destinatário novo encontrado (presenças anteriores sem código já criado).';
            }
        }
    } elseif ($action === 'generate_campaign') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $minEur = max(0.0, (float) ($_POST['min_eur'] ?? 15));
        $label = trim((string) ($_POST['label'] ?? ''));
        $recipientsJson = (string) ($_POST['recipients_json'] ?? '');
        $parsed = edv_discount_decode_recipients_json($recipientsJson);
        $normalized = edv_discount_normalize_recipient_list($parsed);
        $recipients = $normalized['recipients'];
        $skipped = $normalized['skipped'];
        if ($eventId <= 0 || $recipients === []) {
            $flash = 'Lista de destinatários inválida ou vazia.';
            if ($skipped !== []) {
                $flash .= ' (' . count($skipped) . ' ignorados.)';
            }
        } else {
            $ev = $pdo->prepare('SELECT date FROM events WHERE id = ?');
            $ev->execute([$eventId]);
            $eventDate = $ev->fetchColumn();
            $validUntil = $eventDate !== false ? (string) $eventDate : null;

            $pdo->beginTransaction();
            try {
                $generated = 0;
                $insCode = $pdo->prepare(
                    'INSERT INTO discount_codes
                     (campaign_id, event_id, code, min_eur, email, name, max_uses, use_count, valid_until, is_active, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, 1, 0, ?, 1, ?)'
                );
                $insCamp = $pdo->prepare(
                    'INSERT INTO discount_campaigns (event_id, label, min_eur, status, recipient_count, codes_generated, emails_sent, created_at)
                     VALUES (?, ?, ?, \'ready\', 0, 0, 0, ?)'
                );
                $insCamp->execute([
                    $eventId,
                    $label !== '' ? $label : null,
                    $minEur,
                    date('Y-m-d H:i:s'),
                ]);
                $campaignId = (int) $pdo->lastInsertId();
                foreach ($recipients as $r) {
                    $email = $r['email'];
                    $name = $r['name'];
                    $code = edv_generate_promo_code($pdo);
                    $insCode->execute([
                        $campaignId,
                        $eventId,
                        $code,
                        $minEur,
                        $email,
                        $name !== '' ? $name : null,
                        $validUntil,
                        date('Y-m-d H:i:s'),
                    ]);
                    $generated++;
                }
                $pdo->prepare(
                    'UPDATE discount_campaigns SET recipient_count = ?, codes_generated = ? WHERE id = ?'
                )->execute([$generated, $generated, $campaignId]);
                $pdo->commit();
                $selectedCampaignId = $campaignId;
                $flash = "Campanha criada com {$generated} códigos.";
                if ($skipped !== []) {
                    $flash .= ' ' . count($skipped) . ' entrada(s) ignorada(s) (email inválido ou duplicado).';
                }
            } catch (Throwable $e) {
                $pdo->rollBack();
                $flash = 'Erro ao gerar códigos: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'create_manual_code') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $minEur = max(0.0, (float) ($_POST['min_eur'] ?? 15));
        $email = edv_normalize_email(trim((string) ($_POST['email'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $codeRaw = edv_normalize_promo_code((string) ($_POST['code'] ?? ''));
        if ($eventId <= 0) {
            $flash = 'Evento obrigatório.';
        } else {
            $code = $codeRaw !== '' ? $codeRaw : edv_generate_promo_code($pdo);
            try {
                $pdo->prepare(
                    'INSERT INTO discount_codes
                     (campaign_id, event_id, code, min_eur, email, name, max_uses, use_count, is_active, created_at)
                     VALUES (NULL, ?, ?, ?, ?, ?, 1, 0, 1, ?)'
                )->execute([
                    $eventId,
                    $code,
                    $minEur,
                    $email !== '' ? $email : null,
                    $name !== '' ? $name : null,
                    date('Y-m-d H:i:s'),
                ]);
                $flash = "Código {$code} criado.";
            } catch (PDOException) {
                $flash = 'Código duplicado ou erro ao gravar.';
            }
        }
    } elseif ($action === 'send_campaign_emails') {
        $campaignId = (int) ($_POST['campaign_id'] ?? 0);
        if ($campaignId <= 0) {
            $flash = 'Campanha inválida.';
        } else {
            $camp = $pdo->prepare(
                'SELECT dc.*, e.title, e.date
                 FROM discount_campaigns dc
                 INNER JOIN events e ON e.id = dc.event_id
                 WHERE dc.id = ?'
            );
            $camp->execute([$campaignId]);
            $campRow = $camp->fetch(PDO::FETCH_ASSOC);
            if (!is_array($campRow)) {
                $flash = 'Campanha não encontrada.';
            } else {
                $codes = $pdo->prepare(
                    'SELECT * FROM discount_codes
                     WHERE campaign_id = ? AND sent_at IS NULL AND email IS NOT NULL AND email != \'\'
                     ORDER BY id ASC LIMIT 25'
                );
                $codes->execute([$campaignId]);
                $batch = $codes->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $sent = 0;
                foreach ($batch as $codeRow) {
                    if (edv_send_discount_code_email($codeRow, $campRow)) {
                        $pdo->prepare('UPDATE discount_codes SET sent_at = ? WHERE id = ?')
                            ->execute([date('Y-m-d H:i:s'), (int) $codeRow['id']]);
                        $sent++;
                    }
                }
                $totalSent = $pdo->prepare(
                    'SELECT COUNT(*) FROM discount_codes WHERE campaign_id = ? AND sent_at IS NOT NULL'
                );
                $totalSent->execute([$campaignId]);
                $countSent = (int) $totalSent->fetchColumn();
                $pdo->prepare('UPDATE discount_campaigns SET emails_sent = ? WHERE id = ?')
                    ->execute([$countSent, $campaignId]);
                $remaining = count($batch) - $sent;
                $flash = "Enviados {$sent} emails neste lote." . ($remaining > 0 ? " {$remaining} falharam." : '');
                if ($countSent >= (int) $campRow['codes_generated']) {
                    $pdo->prepare('UPDATE discount_campaigns SET status = \'done\' WHERE id = ?')
                        ->execute([$campaignId]);
                }
                $selectedCampaignId = $campaignId;
            }
        }
    } elseif ($action === 'delete_campaign') {
        $campaignId = (int) ($_POST['campaign_id'] ?? 0);
        $result = edv_delete_discount_campaign($pdo, $campaignId);
        $flash = $result['message'];
        if ($result['ok']) {
            $selectedCampaignId = 0;
        }
    } elseif ($action === 'toggle_code') {
        $codeId = (int) ($_POST['code_id'] ?? 0);
        $q = $pdo->prepare('SELECT code, is_active FROM discount_codes WHERE id = ?');
        $q->execute([$codeId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $flash = 'Código não encontrado.';
        } else {
            $newState = ((int) $row['is_active']) === 1 ? 0 : 1;
            $pdo->prepare('UPDATE discount_codes SET is_active = ? WHERE id = ?')
                ->execute([$newState, $codeId]);
            $flash = 'Código ' . $row['code'] . ($newState === 1 ? ' reactivado.' : ' desactivado — deixa de funcionar no checkout.');
        }
    } elseif ($action === 'update_code') {
        $codeId = (int) ($_POST['code_id'] ?? 0);
        $minEur = max(0.0, (float) ($_POST['min_eur'] ?? 0));
        $email = edv_normalize_email(trim((string) ($_POST['email'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $validUntil = trim((string) ($_POST['valid_until'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flash = 'Email inválido.';
        } elseif ($validUntil !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil)) {
            $flash = 'Data de validade inválida (usa AAAA-MM-DD).';
        } else {
            $upd = $pdo->prepare(
                'UPDATE discount_codes SET min_eur = ?, email = ?, name = ?, valid_until = ? WHERE id = ?'
            );
            $upd->execute([
                $minEur,
                $email !== '' ? $email : null,
                $name !== '' ? $name : null,
                $validUntil !== '' ? $validUntil : null,
                $codeId,
            ]);
            $flash = $upd->rowCount() > 0 ? 'Código actualizado.' : 'Código não encontrado (ou sem alterações).';
        }
    } elseif ($action === 'delete_code') {
        $codeId = (int) ($_POST['code_id'] ?? 0);
        $q = $pdo->prepare(
            'SELECT dc.code, dc.use_count,
                    (SELECT COUNT(*) FROM discount_code_uses u WHERE u.discount_code_id = dc.id) AS uses_logged
             FROM discount_codes dc WHERE dc.id = ?'
        );
        $q->execute([$codeId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $flash = 'Código não encontrado.';
        } elseif ((int) $row['use_count'] > 0 || (int) $row['uses_logged'] > 0) {
            $flash = 'Não é possível apagar: o código ' . $row['code'] . ' já foi usado. Desactiva-o em vez de apagar.';
        } else {
            $pdo->prepare('DELETE FROM discount_codes WHERE id = ?')->execute([$codeId]);
            $flash = 'Código ' . $row['code'] . ' apagado.';
        }
    } elseif ($action === 'resend_code_email') {
        $codeId = (int) ($_POST['code_id'] ?? 0);
        $q = $pdo->prepare(
            'SELECT dc.*, e.title, e.date
             FROM discount_codes dc
             INNER JOIN events e ON e.id = dc.event_id
             WHERE dc.id = ?'
        );
        $q->execute([$codeId]);
        $codeRow = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($codeRow)) {
            $flash = 'Código não encontrado.';
        } elseif (trim((string) ($codeRow['email'] ?? '')) === '') {
            $flash = 'Este código não tem email associado — edita-o primeiro e adiciona um email.';
        } else {
            if (edv_send_discount_code_email($codeRow, $codeRow)) {
                $pdo->prepare('UPDATE discount_codes SET sent_at = ? WHERE id = ?')
                    ->execute([date('Y-m-d H:i:s'), $codeId]);
                $flash = 'Email reenviado para ' . $codeRow['email'] . ' com o código ' . $codeRow['code'] . '.';
            } else {
                $flash = 'mail() devolveu falha — o envio não foi aceite pelo servidor.';
            }
        }
    } elseif ($action === 'send_test_code_email') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $to = strtolower(trim((string) ($_POST['to'] ?? '')));
        $event = null;
        foreach ($events as $ev) {
            if ((int) $ev['id'] === $eventId) {
                $event = $ev;
                break;
            }
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $flash = 'Indica um email válido para o teste.';
        } elseif ($event === null) {
            $flash = 'Escolhe um evento para o teste.';
        } else {
            $testCode = [
                'email' => $to,
                'name' => 'Teste de Entrega',
                'code' => 'EDV-TESTE0', // não existe na BD — nunca valida no checkout
                'min_eur' => (float) ($event['returning_min_eur'] ?? 15),
            ];
            if (edv_send_discount_code_email($testCode, ['title' => '[TESTE] ' . $event['title'], 'date' => $event['date']])) {
                $flash = "Email de teste aceite pelo servidor para {$to} (código fictício EDV-TESTE0). "
                    . 'Confirma na caixa de entrada e no spam — mail() aceitar não garante entrega.';
            } else {
                $flash = 'mail() devolveu falha — o servidor não aceitou o envio.';
            }
        }
    }
}

$campaigns = $pdo->query(
    'SELECT dc.*, e.title AS event_title, e.date AS event_date
     FROM discount_campaigns dc
     INNER JOIN events e ON e.id = dc.event_id
     ORDER BY dc.created_at DESC
     LIMIT 20'
)->fetchAll();

$campaignCodes = [];
$selectedCampaign = null;
if ($selectedCampaignId > 0) {
    foreach ($campaigns as $c) {
        if ((int) $c['id'] === $selectedCampaignId) {
            $selectedCampaign = $c;
            break;
        }
    }
    if ($selectedCampaign) {
        $q = $pdo->prepare(
            'SELECT dc.*,
                    (SELECT COUNT(*) FROM discount_code_uses u WHERE u.discount_code_id = dc.id) AS uses_logged
             FROM discount_codes dc
             WHERE dc.campaign_id = ?
             ORDER BY dc.name ASC, dc.email ASC'
        );
        $q->execute([$selectedCampaignId]);
        $campaignCodes = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

// ——— Todos os códigos (pesquisa + últimos 50) ———
$codeQuery = trim((string) ($_REQUEST['code_q'] ?? ''));
$allCodesSql =
    'SELECT dc.*, e.title AS event_title, e.date AS event_date,
            (SELECT COUNT(*) FROM discount_code_uses u WHERE u.discount_code_id = dc.id) AS uses_logged
     FROM discount_codes dc
     INNER JOIN events e ON e.id = dc.event_id';
$allCodesParams = [];
if ($codeQuery !== '') {
    $needle = '%' . strtoupper($codeQuery) . '%';
    $allCodesSql .= ' WHERE UPPER(dc.code) LIKE ? OR UPPER(COALESCE(dc.email, \'\')) LIKE ? OR UPPER(COALESCE(dc.name, \'\')) LIKE ?';
    $allCodesParams = [$needle, $needle, $needle];
}
$allCodesSql .= ' ORDER BY dc.created_at DESC, dc.id DESC LIMIT 50';
$allCodesStmt = $pdo->prepare($allCodesSql);
$allCodesStmt->execute($allCodesParams);
$allCodes = $allCodesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/**
 * Estado visual de um código: usado / inactivo / expirado / activo.
 * @return array{0: string, 1: string} [label, tag css class]
 */
function dc_code_status(array $row): array
{
    if ((int) ($row['use_count'] ?? 0) >= (int) ($row['max_uses'] ?? 1)) {
        return ['usado', 'ok'];
    }
    if ((int) ($row['is_active'] ?? 0) !== 1) {
        return ['inactivo', 'warn'];
    }
    $validUntil = (string) ($row['valid_until'] ?? '');
    if ($validUntil !== '' && substr($validUntil, 0, 10) < db_today_string()) {
        return ['expirado', 'warn'];
    }
    return ['activo', 'pending'];
}

$recentUses = $pdo->query(
    'SELECT u.*, dc.code, dc.min_eur, t.name AS buyer_name, e.title AS event_title
     FROM discount_code_uses u
     INNER JOIN discount_codes dc ON dc.id = u.discount_code_id
     INNER JOIN tickets t ON t.id = u.ticket_id
     INNER JOIN events e ON e.id = t.event_id
     ORDER BY u.used_at DESC
     LIMIT 30'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex,nofollow" />
  <title>Códigos de desconto — Admin</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root { --dark:#0E0B09; --dark-m:#1A1210; --bone:#F5EFE6; --gold:#D4A85A; --ok:#2d6a4f; }
    body { background: var(--dark); color: var(--bone); font-family: Arial, sans-serif; font-size: 14px; }
    <?php require __DIR__ . '/_topbar-styles.php'; ?>
    .main { max-width: 1180px; margin: 0 auto; padding: 1.2rem 1rem 2.5rem; }
    .head h1 { font-weight: 300; font-size: 1.5rem; }
    .head p { color: rgba(245,239,230,.55); font-size: .82rem; margin-top: .25rem; }
    .flash { background: rgba(45,106,79,.18); border: 1px solid rgba(45,106,79,.36); padding: .65rem .85rem; margin: .9rem 0; border-radius: 8px; font-size: .82rem; }
    .grid { display: grid; gap: 1rem; }
    @media (min-width: 980px) { .grid.cols2 { grid-template-columns: 1fr 1fr; } }
    .panel { background: var(--dark-m); border: 1px solid rgba(245,239,230,.08); border-radius: 10px; padding: .95rem; margin-bottom: 1rem; }
    .panel h2 { font-size: .68rem; letter-spacing: .14em; text-transform: uppercase; color: rgba(245,239,230,.38); margin-bottom: .8rem; }
    input, select, textarea { width: 100%; background: rgba(245,239,230,.06); border: 1px solid rgba(245,239,230,.16); color: var(--bone); padding: .48rem .56rem; font-size: .82rem; border-radius: 8px; }
    .field { margin-bottom: .62rem; }
    .field label { display: block; font-size: .62rem; letter-spacing: .1em; text-transform: uppercase; color: rgba(245,239,230,.42); margin-bottom: .25rem; }
    .btn { border: 1px solid rgba(245,239,230,.18); background: rgba(245,239,230,.06); color: var(--bone); cursor: pointer; padding: .5rem .8rem; border-radius: 8px; font-size: .72rem; text-transform: uppercase; letter-spacing: .08em; }
    .btn:hover { border-color: rgba(245,239,230,.35); }
    .btn-gold { border-color: rgba(212,168,90,.45); background: rgba(212,168,90,.12); color: var(--gold); }
    table { width: 100%; border-collapse: collapse; font-size: .78rem; }
    th, td { text-align: left; padding: .45rem .35rem; border-bottom: 1px solid rgba(245,239,230,.08); vertical-align: top; }
    th { color: rgba(245,239,230,.45); font-weight: 600; font-size: .62rem; letter-spacing: .08em; text-transform: uppercase; }
    .mono { font-family: monospace; letter-spacing: .06em; color: var(--gold); }
    .help { font-size: .75rem; color: rgba(245,239,230,.5); line-height: 1.45; margin-top: .5rem; }
    .tag { display: inline-block; padding: .15rem .45rem; border-radius: 999px; font-size: .62rem; background: rgba(245,239,230,.08); }
    .tag.ok { background: rgba(45,106,79,.2); color: #6bcf9a; }
    .tag.pending { background: rgba(212,168,90,.15); color: var(--gold); }
    .tag.warn { background: rgba(180,80,60,.18); color: #e8a090; }
    .scroll-table { max-height: 360px; overflow: auto; }
    .btn-danger { border-color: rgba(180,80,60,.45); background: rgba(180,80,60,.12); color: #e8a090; }
    .actions-row { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin-bottom: .75rem; }
  </style>
</head>
<body class="has-bottom-tabs">
<?php $__adminNav = 'codes'; require __DIR__ . '/_topbar.php'; ?>

<main class="main">
  <div class="head">
    <h1>Códigos de desconto</h1>
    <p>Gera códigos para a comunidade, envia por email e regista utilizações no checkout.</p>
  </div>

  <?php if ($flash !== ''): ?><div class="flash"><?= dc_h($flash) ?></div><?php endif; ?>

  <div class="grid cols2">
    <section class="panel">
      <h2>Campanha — quem já dançou</h2>
      <form method="post">
        <input type="hidden" name="action" value="prepare_recipients" />
        <div class="field">
          <label>Evento destino</label>
          <select name="event_id" required>
            <option value="">— escolher —</option>
            <?php foreach ($events as $ev): ?>
              <option value="<?= (int) $ev['id'] ?>" <?= (int) $ev['id'] === $previewEventId ? 'selected' : '' ?>>
                <?= dc_h((string) $ev['title']) ?> · <?= dc_h(date('d/m/Y', strtotime((string) $ev['date']))) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Preço mínimo com código (€)</label>
          <input type="number" min="0" step="0.01" name="min_eur" value="<?= number_format($previewMin, 2, '.', '') ?>" required />
        </div>
        <button class="btn" type="submit">1. Preparar lista (sem duplicados)</button>
      </form>
      <p class="help">Lista emails únicos de <code>event_attendance</code> em edições anteriores à data do evento. Exclui placeholders e quem já tem código para este evento.</p>

      <?php if ($previewRecipients !== []): ?>
        <form method="post" style="margin-top:.85rem;">
          <input type="hidden" name="action" value="generate_campaign" />
          <input type="hidden" name="event_id" value="<?= (int) $previewEventId ?>" />
          <input type="hidden" name="min_eur" value="<?= number_format($previewMin, 2, '.', '') ?>" />
          <input type="hidden" name="recipients_json" value="b64:<?= dc_h(base64_encode(json_encode($previewRecipients, JSON_UNESCAPED_UNICODE))) ?>" />
          <div class="field">
            <label>Nome da campanha (opcional)</label>
            <input type="text" name="label" placeholder="Comunidade junho 2026" />
          </div>
          <div class="scroll-table" style="margin:.6rem 0;">
            <table>
              <thead><tr><th>Nome</th><th>Email</th><th>Última edição</th></tr></thead>
              <tbody>
                <?php foreach ($previewRecipients as $r): ?>
                  <tr>
                    <td><?= dc_h($r['name'] !== '' ? $r['name'] : '—') ?></td>
                    <td><?= dc_h($r['email']) ?></td>
                    <td><?= dc_h($r['last_event_date'] !== '' ? date('d/m/Y', strtotime($r['last_event_date'])) : '—') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="help"><strong><?= count($previewRecipients) ?></strong> destinatários · cada um receberá um código único ligado ao email.</p>
          <button class="btn btn-gold" type="submit">2. Gerar códigos</button>
        </form>
        <?php if ($previewExcluded !== []): ?>
          <div style="margin-top:.85rem;">
            <p class="help"><strong><?= count($previewExcluded) ?></strong> presença(s) não incluída(s) na lista:</p>
            <div class="scroll-table">
              <table>
                <thead><tr><th>Nome</th><th>Email</th><th>Motivo</th></tr></thead>
                <tbody>
                  <?php foreach ($previewExcluded as $x): ?>
                    <tr>
                      <td><?= dc_h($x['name'] !== '' ? $x['name'] : '—') ?></td>
                      <td><?= dc_h($x['email']) ?></td>
                      <td><span class="tag warn"><?= dc_h($x['reason']) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>Código manual</h2>
      <form method="post">
        <input type="hidden" name="action" value="create_manual_code" />
        <div class="field">
          <label>Evento</label>
          <select name="event_id" required>
            <?php foreach ($events as $ev): ?>
              <option value="<?= (int) $ev['id'] ?>"><?= dc_h((string) $ev['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label>Preço mínimo (€)</label><input type="number" min="0" step="0.01" name="min_eur" value="20" /></div>
        <div class="field"><label>Email (opcional — restringe uso)</label><input type="email" name="email" /></div>
        <div class="field"><label>Nome</label><input type="text" name="name" /></div>
        <div class="field"><label>Código (vazio = auto)</label><input type="text" name="code" placeholder="EDV-XXXXXX" /></div>
        <button class="btn" type="submit">Criar código</button>
      </form>
    </section>
  </div>

  <section class="panel">
    <h2>Campanhas</h2>
    <?php if ($campaigns === []): ?>
      <p class="help">Ainda não há campanhas.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Campanha</th><th>Evento</th><th>Preço mín.</th><th>Códigos</th><th>Emails</th><th>Estado</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($campaigns as $c): ?>
            <tr>
              <td><?= dc_h((string) ($c['label'] ?? '—')) ?></td>
              <td><?= dc_h((string) $c['event_title']) ?></td>
              <td><?= number_format((float) $c['min_eur'], 0, ',', ' ') ?>€</td>
              <td><?= (int) $c['codes_generated'] ?></td>
              <td><?= (int) $c['emails_sent'] ?> / <?= (int) $c['codes_generated'] ?></td>
              <td><span class="tag <?= (string) $c['status'] === 'done' ? 'ok' : 'pending' ?>"><?= dc_h((string) $c['status']) ?></span></td>
              <td><a href="?campaign_id=<?= (int) $c['id'] ?>" style="color:var(--gold)">Ver</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <?php if ($selectedCampaign): ?>
    <?php
      $codesListed = count($campaignCodes);
      $codesExpected = (int) $selectedCampaign['codes_generated'];
    ?>
    <section class="panel">
      <h2>Códigos — <?= dc_h((string) ($selectedCampaign['label'] ?? 'Campanha #' . $selectedCampaign['id'])) ?></h2>
      <p class="help" style="margin-bottom:.6rem;">
        <strong><?= $codesListed ?></strong> códigos na lista
        <?php if ($codesListed !== $codesExpected): ?>
          · <span class="tag warn">registo indica <?= $codesExpected ?> — apaga a campanha e gera de novo se faltar alguém</span>
        <?php endif; ?>
      </p>
      <div class="actions-row">
        <form method="post">
          <input type="hidden" name="action" value="send_campaign_emails" />
          <input type="hidden" name="campaign_id" value="<?= (int) $selectedCampaign['id'] ?>" />
          <button class="btn btn-gold" type="submit">3. Enviar lote de emails (até 25)</button>
        </form>
        <form method="post" onsubmit="return confirm('Apagar esta campanha e todos os códigos? Só é possível se nenhum código tiver sido usado.');">
          <input type="hidden" name="action" value="delete_campaign" />
          <input type="hidden" name="campaign_id" value="<?= (int) $selectedCampaign['id'] ?>" />
          <button class="btn btn-danger" type="submit">Apagar campanha</button>
        </form>
      </div>
      <p class="help">Cada clique envia até 25 emails pendentes. Repete até todos estarem enviados. Para corrigir a lista ou valores, apaga a campanha e volta ao passo 1.</p>
      <div class="scroll-table">
        <table>
          <thead><tr><th>Nome</th><th>Email</th><th>Código</th><th>Enviado</th><th>Usado</th></tr></thead>
          <tbody>
            <?php foreach ($campaignCodes as $row): ?>
              <tr>
                <td><?= dc_h((string) ($row['name'] ?? '—')) ?></td>
                <td><?= dc_h((string) ($row['email'] ?? '')) ?></td>
                <td class="mono"><?= dc_h((string) $row['code']) ?></td>
                <td><?= !empty($row['sent_at']) ? dc_h(date('d/m H:i', strtotime((string) $row['sent_at']))) : '—' ?></td>
                <td><?= (int) ($row['use_count'] ?? 0) ?> <?= (int) ($row['uses_logged'] ?? 0) > 0 ? '✓' : '' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>

  <section class="panel">
    <h2>Todos os códigos</h2>
    <form method="get" class="actions-row">
      <input type="text" name="code_q" value="<?= dc_h($codeQuery) ?>" placeholder="Pesquisar por código, email ou nome…" style="max-width:22rem;" />
      <button class="btn" type="submit">Pesquisar</button>
      <?php if ($codeQuery !== ''): ?>
        <a href="discount-codes.php" style="color:rgba(245,239,230,.55);font-size:.74rem;">limpar</a>
      <?php endif; ?>
    </form>
    <?php if ($allCodes === []): ?>
      <p class="help"><?= $codeQuery !== '' ? 'Nenhum código corresponde à pesquisa.' : 'Ainda não há códigos.' ?></p>
    <?php else: ?>
      <p class="help" style="margin-bottom:.6rem;">
        <?= count($allCodes) ?> código(s)<?= count($allCodes) === 50 ? ' (máx. 50 — afina a pesquisa para ver mais antigos)' : '' ?> ·
        manuais e de campanha, mais recentes primeiro.
      </p>
      <div class="scroll-table">
        <table>
          <thead><tr><th>Código</th><th>Pessoa</th><th>Evento</th><th>Mín.</th><th>Válido até</th><th>Enviado</th><th>Estado</th><th>Acções</th></tr></thead>
          <tbody>
            <?php foreach ($allCodes as $row): ?>
              <?php
                [$statusLabel, $statusClass] = dc_code_status($row);
                $rowUsed = (int) ($row['use_count'] ?? 0) > 0 || (int) ($row['uses_logged'] ?? 0) > 0;
                $rowEmail = trim((string) ($row['email'] ?? ''));
              ?>
              <tr>
                <td class="mono"><?= dc_h((string) $row['code']) ?><?php if (empty($row['campaign_id'])): ?><br><span class="tag" style="font-size:.58rem;">manual</span><?php endif; ?></td>
                <td>
                  <?= dc_h((string) ($row['name'] ?? '') !== '' ? (string) $row['name'] : '—') ?><br>
                  <span style="opacity:.55;font-size:.72rem"><?= $rowEmail !== '' ? dc_h($rowEmail) : 'sem email (uso livre)' ?></span>
                </td>
                <td><?= dc_h((string) $row['event_title']) ?></td>
                <td><?= number_format((float) $row['min_eur'], 0, ',', ' ') ?>€</td>
                <td><?= !empty($row['valid_until']) ? dc_h(date('d/m/Y', strtotime((string) $row['valid_until']))) : '—' ?></td>
                <td><?= !empty($row['sent_at']) ? dc_h(date('d/m H:i', strtotime((string) $row['sent_at']))) : '—' ?></td>
                <td><span class="tag <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                <td>
                  <div style="display:flex;flex-wrap:wrap;gap:.3rem;">
                    <form method="post">
                      <input type="hidden" name="action" value="toggle_code" />
                      <input type="hidden" name="code_id" value="<?= (int) $row['id'] ?>" />
                      <input type="hidden" name="code_q" value="<?= dc_h($codeQuery) ?>" />
                      <button class="btn" type="submit" style="padding:.3rem .5rem;font-size:.62rem;">
                        <?= ((int) $row['is_active']) === 1 ? 'Desactivar' : 'Activar' ?>
                      </button>
                    </form>
                    <?php if ($rowEmail !== ''): ?>
                      <form method="post">
                        <input type="hidden" name="action" value="resend_code_email" />
                        <input type="hidden" name="code_id" value="<?= (int) $row['id'] ?>" />
                        <input type="hidden" name="code_q" value="<?= dc_h($codeQuery) ?>" />
                        <button class="btn btn-gold" type="submit" style="padding:.3rem .5rem;font-size:.62rem;">
                          <?= !empty($row['sent_at']) ? 'Reenviar' : 'Enviar' ?> email
                        </button>
                      </form>
                    <?php endif; ?>
                    <?php if (!$rowUsed): ?>
                      <form method="post" onsubmit="return confirm('Apagar o código <?= dc_h((string) $row['code']) ?>?');">
                        <input type="hidden" name="action" value="delete_code" />
                        <input type="hidden" name="code_id" value="<?= (int) $row['id'] ?>" />
                        <input type="hidden" name="code_q" value="<?= dc_h($codeQuery) ?>" />
                        <button class="btn btn-danger" type="submit" style="padding:.3rem .5rem;font-size:.62rem;">Apagar</button>
                      </form>
                    <?php endif; ?>
                  </div>
                  <details style="margin-top:.35rem;">
                    <summary style="cursor:pointer;font-size:.66rem;color:rgba(245,239,230,.5);">Editar</summary>
                    <form method="post" style="margin-top:.45rem;display:grid;gap:.35rem;max-width:15rem;">
                      <input type="hidden" name="action" value="update_code" />
                      <input type="hidden" name="code_id" value="<?= (int) $row['id'] ?>" />
                      <input type="hidden" name="code_q" value="<?= dc_h($codeQuery) ?>" />
                      <input type="text" name="name" value="<?= dc_h((string) ($row['name'] ?? '')) ?>" placeholder="Nome" />
                      <input type="email" name="email" value="<?= dc_h($rowEmail) ?>" placeholder="Email (vazio = uso livre)" />
                      <input type="number" name="min_eur" min="0" step="0.01" value="<?= number_format((float) $row['min_eur'], 2, '.', '') ?>" title="Preço mínimo (€)" />
                      <input type="date" name="valid_until" value="<?= dc_h(substr((string) ($row['valid_until'] ?? ''), 0, 10)) ?>" title="Válido até (vazio = sem limite)" />
                      <button class="btn" type="submit" style="padding:.35rem .55rem;font-size:.64rem;">Guardar</button>
                    </form>
                  </details>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2>Teste de email de código</h2>
    <form method="post" class="actions-row">
      <input type="hidden" name="action" value="send_test_code_email" />
      <input type="email" name="to" value="<?= dc_h((string) ($_POST['to'] ?? 'daniel@innerflect.tech')) ?>" required style="max-width:18rem;" placeholder="email de teste" />
      <select name="event_id" style="max-width:18rem;">
        <?php foreach ($events as $ev): ?>
          <option value="<?= (int) $ev['id'] ?>"><?= dc_h((string) $ev['title']) ?> · <?= dc_h(date('d/m/Y', strtotime((string) $ev['date']))) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-gold" type="submit">Enviar teste</button>
    </form>
    <p class="help">Envia o template real do email de código para o endereço indicado, com o código fictício <code style="color:var(--gold)">EDV-TESTE0</code> (não existe na BD, nunca valida no checkout). Nada é gravado. Útil para verificar entregabilidade antes de disparar uma campanha.</p>
  </section>

  <section class="panel">
    <h2>Utilizações registadas</h2>
    <?php if ($recentUses === []): ?>
      <p class="help">Nenhuma compra com código ainda.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Data</th><th>Código</th><th>Comprador</th><th>Valor</th><th>Preço mín. código</th><th>Evento</th></tr></thead>
        <tbody>
          <?php foreach ($recentUses as $u): ?>
            <tr>
              <td><?= dc_h(date('d/m/Y H:i', strtotime((string) $u['used_at']))) ?></td>
              <td class="mono"><?= dc_h((string) $u['code']) ?></td>
              <td><?= dc_h((string) $u['buyer_name']) ?><br><span style="opacity:.55;font-size:.72rem"><?= dc_h((string) $u['email']) ?></span></td>
              <td><?= number_format((float) $u['amount_paid'], 2, ',', ' ') ?>€</td>
              <td><?= number_format((float) $u['min_eur'], 0, ',', ' ') ?>€</td>
              <td><?= dc_h((string) $u['event_title']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
