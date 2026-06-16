<?php
declare(strict_types=1);

/**
 * Participantes por evento — bilhetes + reservas por confirmar + facilitadores.
 * Edição de contactos (nome/email/telemóvel), marcação de presença e
 * mensagem WhatsApp pré-preenchida por pessoa (editável; envio via WAHA).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/participants.php';
require_once __DIR__ . '/../api/whatsapp.php';
require_admin_session();

$pdo = db();
$flash = '';
$flashError = false;

$selectedEvent = (int) ($_POST['event_id'] ?? ($_GET['event_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update_contact') {
        $kind = (string) ($_POST['kind'] ?? 'ticket');
        $id = (string) ($_POST['person_id'] ?? '');
        $name = (string) ($_POST['name'] ?? '');
        $email = (string) ($_POST['email'] ?? '');
        $phone = (string) ($_POST['phone'] ?? '');
        $result = $kind === 'booking'
            ? edv_booking_update_contact($pdo, $id, $name, $email, $phone)
            : edv_participant_update_contact($pdo, $id, $name, $email, $phone);
        $flash = $result['ok'] ? 'Contacto atualizado.' : ($result['error'] ?? 'Não foi possível atualizar.');
        $flashError = !$result['ok'];
    }

    if ($action === 'set_presence') {
        $ticketId = (string) ($_POST['ticket_id'] ?? '');
        $present = (string) ($_POST['present'] ?? '') === '1';
        if ($ticketId !== '') {
            edv_attendance_sync_for_ticket($ticketId, $present);
            $flash = $present ? 'Presença marcada.' : 'Presença removida.';
        }
    }

    if ($action === 'add_person') {
        $result = edv_participant_create(
            $pdo,
            $selectedEvent,
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['phone'] ?? ''),
            (string) ($_POST['role'] ?? 'participant'),
            (float) str_replace(',', '.', (string) ($_POST['amount'] ?? '0')),
            (string) ($_POST['mark_present'] ?? '') === '1'
        );
        $flash = $result['ok'] ? 'Pessoa adicionada.' : ($result['error'] ?? 'Não foi possível adicionar.');
        $flashError = !$result['ok'];
    }

    if ($action === 'send_wa') {
        $phone = (string) ($_POST['wa_phone'] ?? '');
        $text = trim((string) ($_POST['wa_text'] ?? ''));
        $chatId = edv_wa_chat_id($phone);
        if ($chatId === null) {
            $flash = 'Telemóvel inválido para WhatsApp — edita o número primeiro.';
            $flashError = true;
        } elseif ($text === '') {
            $flash = 'Mensagem vazia.';
            $flashError = true;
        } elseif (!edv_waha_enabled()) {
            $flash = 'WAHA não configurado (EDV_WAHA_API_KEY em falta no Coolify).';
            $flashError = true;
        } else {
            $res = edv_waha_send_text($text, $chatId);
            $flash = $res['ok']
                ? 'Mensagem enviada no WhatsApp ✓'
                : 'Falha no envio: ' . ($res['error'] ?? 'erro desconhecido');
            $flashError = !$res['ok'];
        }
    }
}

$eventsStmt = $pdo->query(
    'SELECT e.*,
            (SELECT COUNT(*) FROM tickets t
             WHERE t.event_id = e.id AND t.payment_status IN (\'paid\', \'free\')) AS sold
     FROM events e
     ORDER BY e.date DESC
     LIMIT 40'
);
$events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

if ($selectedEvent <= 0 && !empty($events)) {
    $selectedEvent = (int) $events[0]['id'];
}
$sel = null;
foreach ($events as $ev) {
    if ((int) $ev['id'] === $selectedEvent) {
        $sel = $ev;
        break;
    }
}

$rows = $selectedEvent > 0 ? edv_participants_list_for_event($pdo, $selectedEvent) : [];

$facilitators = array_values(array_filter($rows, static fn (array $r): bool => $r['role'] === 'facilitator'));
$confirmed = array_values(array_filter(
    $rows,
    static fn (array $r): bool => $r['kind'] === 'ticket' && $r['role'] !== 'facilitator'
));
$pending = array_values(array_filter($rows, static fn (array $r): bool => $r['kind'] === 'booking'));
$presentCount = count(array_filter($rows, static fn (array $r): bool => (bool) $r['checked_in']));

function part_h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Linha da tabela (partilhada pelas 3 secções). */
function part_row(array $r, array $sel, int $selectedEvent): void
{
    $isBooking = $r['kind'] === 'booking';
    $email = edv_is_placeholder_presence_email((string) $r['email']) ? '' : (string) $r['email'];
    $waMsg = edv_participant_wa_message($r, $sel);
    $hasPhone = edv_wa_chat_id((string) $r['phone']) !== null;
    ?>
    <tr>
      <td>
        <div class="pname"><?= part_h((string) $r['name']) ?></div>
        <?php if ($isBooking && $r['payment_ref'] !== null): ?>
          <div class="psub mono">ref <?= part_h((string) $r['payment_ref']) ?>
            · <?= $r['step2_at'] !== null ? 'comprovativo enviado' : 'à espera do passo 2' ?></div>
        <?php endif; ?>
      </td>
      <td>
        <div class="psub"><?= $email !== '' ? part_h($email) : '<em>sem email</em>' ?></div>
        <div class="psub mono"><?= $r['phone'] !== '' ? part_h((string) $r['phone']) : '<em>sem telemóvel</em>' ?></div>
        <details class="edit-box">
          <summary>✎ editar</summary>
          <form method="post" class="edit-form">
            <input type="hidden" name="action" value="update_contact" />
            <input type="hidden" name="event_id" value="<?= $selectedEvent ?>" />
            <input type="hidden" name="kind" value="<?= part_h((string) $r['kind']) ?>" />
            <input type="hidden" name="person_id" value="<?= part_h((string) $r['id']) ?>" />
            <input type="text" name="name" value="<?= part_h((string) $r['name']) ?>" placeholder="Nome" required />
            <input type="email" name="email" value="<?= part_h($email) ?>" placeholder="email (opcional)" />
            <input type="text" name="phone" value="<?= part_h((string) $r['phone']) ?>" placeholder="telemóvel" />
            <button type="submit" class="btn btn-save">Guardar</button>
          </form>
        </details>
      </td>
      <td><?= $r['amount'] > 0 ? number_format((float) $r['amount'], 2, ',', ' ') . ' €' : '—' ?></td>
      <td>
        <?php if ($isBooking): ?>
          <span class="badge badge-pending">por confirmar</span>
        <?php elseif ($r['role'] === 'facilitator'): ?>
          <span class="badge badge-fac">facilitação</span>
        <?php elseif ($r['payment_status'] === 'free'): ?>
          <span class="badge badge-free">grátis</span>
        <?php else: ?>
          <span class="badge badge-paid">pago</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if (!$isBooking): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="set_presence" />
            <input type="hidden" name="event_id" value="<?= $selectedEvent ?>" />
            <input type="hidden" name="ticket_id" value="<?= part_h((string) $r['id']) ?>" />
            <input type="hidden" name="present" value="<?= $r['checked_in'] ? '0' : '1' ?>" />
            <button type="submit" class="btn btn-presence<?= $r['checked_in'] ? ' is-on' : '' ?>"
                    title="<?= $r['checked_in'] ? 'Remover presença' : 'Marcar presença' ?>">
              <?= $r['checked_in'] ? '✓ presente' : 'marcar' ?>
            </button>
          </form>
        <?php else: ?>
          <span class="psub">—</span>
        <?php endif; ?>
      </td>
      <td>
        <button type="button" class="btn btn-wa" <?= $hasPhone ? '' : 'disabled title="Sem telemóvel válido — edita primeiro"' ?>
                data-phone="<?= part_h((string) $r['phone']) ?>"
                data-name="<?= part_h((string) $r['name']) ?>"
                data-msg="<?= part_h($waMsg) ?>"
                onclick="openWa(this)">WhatsApp</button>
      </td>
    </tr>
    <?php
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex,nofollow" />
  <title>Participantes — Admin</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root { --dark:#0E0B09; --dark-m:#1A1210; --bone:#F5EFE6; --gold:#D4A85A; --gold-l:#B8924A; }
    body { background: var(--dark); color: var(--bone); font-family: Arial, sans-serif; font-size: 14px; }
    <?php require __DIR__ . '/_topbar-styles.php'; ?>
    .main { max-width: 1100px; margin: 0 auto; padding: 1.2rem 1rem 2.5rem; }
    .head h1 { font-weight: 300; font-size: 1.5rem; }
    .head p { color: rgba(245,239,230,.5); font-size: .82rem; margin-top: .35rem; line-height: 1.55; max-width: 52rem; }
    .toolbar { display: flex; flex-wrap: wrap; gap: .65rem; align-items: center; margin: 1.1rem 0; }
    .toolbar select, .toolbar a {
      background: rgba(245,239,230,.06); border: 1px solid rgba(245,239,230,.16); color: var(--bone);
      padding: .48rem .6rem; border-radius: 8px; font-size: .82rem; font-family: inherit;
    }
    .toolbar a { text-decoration: none; letter-spacing: .06em; text-transform: uppercase; font-size: .68rem; }
    .stats { display: flex; flex-wrap: wrap; gap: 1.5rem; margin-bottom: 1rem; font-size: .85rem; color: rgba(245,239,230,.55); }
    .stats strong { color: var(--gold-l); font-weight: 400; }
    h2.sec { font-size: .8rem; letter-spacing: .12em; text-transform: uppercase; color: var(--gold-l);
      border-bottom: 1px solid rgba(245,239,230,.1); padding-bottom: .45rem; margin: 1.6rem 0 .6rem; font-weight: 400; }
    table { width: 100%; border-collapse: collapse; font-size: .82rem; }
    th, td { padding: .55rem .5rem; border-bottom: 1px solid rgba(245,239,230,.08); text-align: left; vertical-align: top; }
    th { font-size: .62rem; letter-spacing: .1em; text-transform: uppercase; color: rgba(245,239,230,.4); }
    .mono { font-family: ui-monospace, monospace; font-size: .72rem; }
    .pname { font-size: .88rem; }
    .psub { font-size: .74rem; color: rgba(245,239,230,.55); margin-top: .15rem; }
    .psub em { color: rgba(245,239,230,.32); font-style: italic; }
    .empty { padding: 1.4rem; text-align: center; color: rgba(245,239,230,.4); font-size: .82rem; }
    .flash { background: rgba(45,106,79,.18); border: 1px solid rgba(45,106,79,.36); padding: .65rem .85rem; margin-bottom: .9rem; border-radius: 8px; font-size: .82rem; }
    .flash-error { background: rgba(139,48,48,.2); border-color: rgba(180,70,70,.45); }
    .btn { border: 1px solid rgba(245,239,230,.18); background: rgba(245,239,230,.06); color: var(--bone); cursor: pointer;
      padding: .44rem .7rem; border-radius: 8px; font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; }
    .btn:disabled { opacity: .35; cursor: not-allowed; }
    .btn-wa { border-color: rgba(37,211,102,.4); color: #6bcf9a; }
    .btn-presence.is-on { border-color: rgba(45,106,79,.55); background: rgba(45,106,79,.22); color: #8fd9ad; }
    .badge { font-size: .62rem; letter-spacing: .06em; text-transform: uppercase; padding: .2rem .5rem; border-radius: 999px; white-space: nowrap; }
    .badge-paid { background: rgba(45,106,79,.25); color: #8fd9ad; }
    .badge-free { background: rgba(184,146,74,.22); color: var(--gold); }
    .badge-fac { background: rgba(184,146,74,.32); color: var(--gold); }
    .badge-pending { background: rgba(139,108,48,.25); color: #d9b86b; }
    .edit-box { margin-top: .3rem; }
    .edit-box summary { cursor: pointer; font-size: .68rem; color: rgba(245,239,230,.45); list-style: none; }
    .edit-box summary::-webkit-details-marker { display: none; }
    .edit-form { display: flex; flex-direction: column; gap: .35rem; margin-top: .45rem; max-width: 15rem; }
    .edit-form input {
      background: rgba(245,239,230,.06); border: 1px solid rgba(245,239,230,.16); color: var(--bone);
      padding: .35rem .45rem; border-radius: 6px; font-size: .76rem; font-family: inherit;
    }
    .btn-save { align-self: flex-start; padding: .32rem .55rem; font-size: .62rem; }
    .add-box { background: rgba(245,239,230,.04); border: 1px solid rgba(245,239,230,.1); border-radius: 10px;
      padding: 1rem; margin-top: 1.8rem; }
    .add-box h3 { font-size: .8rem; letter-spacing: .1em; text-transform: uppercase; color: var(--gold-l); font-weight: 400; margin-bottom: .7rem; }
    .add-form { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
    .add-form input, .add-form select {
      background: rgba(245,239,230,.06); border: 1px solid rgba(245,239,230,.16); color: var(--bone);
      padding: .45rem .55rem; border-radius: 7px; font-size: .8rem; font-family: inherit;
    }
    .add-form label { font-size: .74rem; color: rgba(245,239,230,.55); display: flex; align-items: center; gap: .3rem; }
    /* Modal WhatsApp */
    dialog#waModal { background: var(--dark-m); color: var(--bone); border: 1px solid rgba(245,239,230,.16);
      border-radius: 12px; padding: 1.2rem; width: min(34rem, 92vw); }
    dialog#waModal::backdrop { background: rgba(0,0,0,.65); }
    #waModal h3 { font-weight: 300; font-size: 1.05rem; margin-bottom: .2rem; }
    #waModal .psub { margin-bottom: .7rem; }
    #waModal textarea { width: 100%; min-height: 14rem; background: rgba(245,239,230,.05);
      border: 1px solid rgba(245,239,230,.16); color: var(--bone); border-radius: 8px;
      padding: .6rem .7rem; font-size: .85rem; font-family: inherit; line-height: 1.5; resize: vertical; }
    #waModal .actions { display: flex; justify-content: flex-end; gap: .5rem; margin-top: .8rem; }
    #waModal .btn-send { border-color: rgba(37,211,102,.5); background: rgba(37,211,102,.12); color: #6bcf9a; }
  </style>
</head>
<body class="has-bottom-tabs">
<?php
$__adminNav = 'participants';
require __DIR__ . '/_topbar.php';
?>

<main class="main">
  <div class="head">
    <h1>Participantes por evento</h1>
    <p>
      Lista única: bilhetes confirmados, reservas do /links por confirmar e facilitadores.
      Edita contactos, marca presenças e envia uma mensagem WhatsApp por pessoa
      (pré-preenchida, editável — sai do número ligado ao WAHA).
    </p>
  </div>

  <?php if ($flash !== ''): ?>
    <div class="flash<?= $flashError ? ' flash-error' : '' ?>"><?= part_h($flash) ?></div>
  <?php endif; ?>

  <form method="get" class="toolbar">
    <select name="event_id" onchange="this.form.submit()">
      <?php foreach ($events as $ev): ?>
        <option value="<?= (int) $ev['id'] ?>" <?= (int) $ev['id'] === $selectedEvent ? 'selected' : '' ?>>
          <?= part_h((string) $ev['title']) ?> — <?= date('d/m/Y', strtotime((string) $ev['date'])) ?>
          (<?= (int) $ev['sold'] ?> bilhetes)
        </option>
      <?php endforeach; ?>
    </select>
    <?php if ($selectedEvent > 0): ?>
      <a href="/admin/attendance.php?event_id=<?= $selectedEvent ?>">Presenças</a>
      <a href="/admin/link-bookings.php">Inscrições /links</a>
    <?php endif; ?>
  </form>

  <?php if ($sel !== null): ?>
    <div class="stats">
      <span><strong><?= count($confirmed) ?></strong> bilhetes</span>
      <span><strong><?= count($facilitators) ?></strong> facilitadores</span>
      <span><strong><?= count($pending) ?></strong> por confirmar</span>
      <span><strong><?= $presentCount ?></strong> presentes</span>
      <span>capacidade <strong><?= (int) ($sel['capacity'] ?? 0) ?></strong></span>
    </div>
  <?php endif; ?>

  <?php if ($facilitators !== []): ?>
    <h2 class="sec">Facilitação</h2>
    <div style="overflow-x:auto"><table>
      <thead><tr><th>Nome</th><th>Contacto</th><th>Valor</th><th>Estado</th><th>Presença</th><th></th></tr></thead>
      <tbody><?php foreach ($facilitators as $r) { part_row($r, $sel ?? [], $selectedEvent); } ?></tbody>
    </table></div>
  <?php endif; ?>

  <h2 class="sec">Bilhetes</h2>
  <?php if ($confirmed === []): ?>
    <p class="empty">Sem bilhetes confirmados para este evento.</p>
  <?php else: ?>
    <div style="overflow-x:auto"><table>
      <thead><tr><th>Nome</th><th>Contacto</th><th>Valor</th><th>Estado</th><th>Presença</th><th></th></tr></thead>
      <tbody><?php foreach ($confirmed as $r) { part_row($r, $sel ?? [], $selectedEvent); } ?></tbody>
    </table></div>
  <?php endif; ?>

  <?php if ($pending !== []): ?>
    <h2 class="sec">Reservas por confirmar (/links)</h2>
    <div style="overflow-x:auto"><table>
      <thead><tr><th>Nome</th><th>Contacto</th><th>Valor</th><th>Estado</th><th>Presença</th><th></th></tr></thead>
      <tbody><?php foreach ($pending as $r) { part_row($r, $sel ?? [], $selectedEvent); } ?></tbody>
    </table></div>
  <?php endif; ?>

  <div class="add-box">
    <h3>Adicionar pessoa</h3>
    <form method="post" class="add-form">
      <input type="hidden" name="action" value="add_person" />
      <input type="hidden" name="event_id" value="<?= $selectedEvent ?>" />
      <input type="text" name="name" placeholder="Nome *" required style="min-width:11rem" />
      <input type="text" name="phone" placeholder="Telemóvel" />
      <input type="email" name="email" placeholder="Email (opcional)" />
      <select name="role">
        <?php foreach (EDV_TICKET_ROLES as $key => $label): ?>
          <option value="<?= part_h($key) ?>"><?= part_h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="amount" placeholder="Valor € (0 = grátis)" size="12" />
      <label><input type="checkbox" name="mark_present" value="1" /> já presente</label>
      <button type="submit" class="btn">Adicionar</button>
    </form>
  </div>
</main>

<dialog id="waModal">
  <h3>Mensagem WhatsApp</h3>
  <p class="psub">Para <strong id="waName"></strong> · <span id="waPhone" class="mono"></span> — edita à vontade antes de enviar.</p>
  <form method="post">
    <input type="hidden" name="action" value="send_wa" />
    <input type="hidden" name="event_id" value="<?= $selectedEvent ?>" />
    <input type="hidden" name="wa_phone" id="waPhoneInput" value="" />
    <textarea name="wa_text" id="waText"></textarea>
    <div class="actions">
      <button type="button" class="btn" onclick="document.getElementById('waModal').close()">Cancelar</button>
      <button type="submit" class="btn btn-send">Enviar via WAHA</button>
    </div>
  </form>
</dialog>

<script>
function openWa(btn) {
  document.getElementById('waName').textContent = btn.dataset.name;
  document.getElementById('waPhone').textContent = btn.dataset.phone;
  document.getElementById('waPhoneInput').value = btn.dataset.phone;
  document.getElementById('waText').value = btn.dataset.msg;
  document.getElementById('waModal').showModal();
}
</script>
</body>
</html>
