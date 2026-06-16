<?php
declare(strict_types=1);

/**
 * Lista de presenças por evento (quem entrou na porta).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/attendance.php';
require_admin_session();

$pdo = db();
$flash = '';
$flashError = false;
$importResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'update_email') {
    $attendanceId = (int) ($_POST['attendance_id'] ?? 0);
    $email = edv_normalize_email(trim((string) ($_POST['email'] ?? '')));
    $result = edv_attendance_update_email($pdo, $attendanceId, $email);
    if ($result['ok']) {
        $flash = 'Email atualizado.';
    } else {
        $flash = $result['error'] ?? 'Não foi possível atualizar o email.';
        $flashError = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'import_event_01') {
    $importResult = edv_attendance_import_event_01_roster($pdo);
    if ($importResult === null) {
        $flash = 'Evento #01 não encontrado (procura data 2026-05-23 ou título com #01).';
    } else {
        $flash = sprintf(
            'Lista #01 importada: %d presentes, %d ausentes, %d bilhetes ligados, %d criados.',
            (int) $importResult['present'],
            (int) $importResult['absent'],
            (int) $importResult['matched'],
            (int) $importResult['created']
        );
        if (!empty($importResult['messages'])) {
            $flash .= ' ' . implode(' ', array_slice($importResult['messages'], 0, 3));
        }
    }
}

$eventsStmt = $pdo->query(
    'SELECT e.id, e.title, e.date, e.is_active, e.returning_min_eur,
            (SELECT COUNT(*) FROM event_attendance ea WHERE ea.event_id = e.id) AS attended,
            (SELECT COUNT(*) FROM tickets t
             WHERE t.event_id = e.id AND t.payment_status IN (\'paid\', \'free\')) AS sold
     FROM events e
     ORDER BY e.date DESC
     LIMIT 40'
);
$events = $eventsStmt->fetchAll();

$selectedEvent = (int) ($_GET['event_id'] ?? 0);
if ($selectedEvent <= 0 && !empty($events)) {
    $selectedEvent = (int) $events[0]['id'];
}

if ($importResult !== null && isset($importResult['event_id'])) {
    $selectedEvent = (int) $importResult['event_id'];
} elseif (isset($_POST['event_id'])) {
    $selectedEvent = (int) $_POST['event_id'];
}

$event01Id = edv_attendance_find_event_01_id($pdo);
$isEvent01 = $event01Id !== null && $selectedEvent === $event01Id;

if ($isEvent01 && $_SERVER['REQUEST_METHOD'] !== 'POST' && $importResult === null) {
    $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM event_attendance WHERE event_id = ?');
    $cntStmt->execute([$event01Id]);
    if ((int) $cntStmt->fetchColumn() < 8) {
        edv_attendance_import_event_01_roster($pdo);
    }
}

$rows = $selectedEvent > 0 ? edv_attendance_list_for_event($selectedEvent) : [];
$sel = null;
foreach ($events as $ev) {
    if ((int) $ev['id'] === $selectedEvent) {
        $sel = $ev;
        break;
    }
}

if (isset($_GET['export']) && (string) $_GET['export'] === 'csv' && $selectedEvent > 0) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="presencas-evento-' . $selectedEvent . '.csv"');
    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['checked_in_at', 'name', 'email', 'phone', 'amount_paid', 'ticket_id'], ';');
        foreach ($rows as $r) {
            fputcsv($out, [
                (string) ($r['checked_in_at'] ?? ''),
                (string) ($r['name'] ?? ''),
                (string) ($r['email'] ?? ''),
                (string) ($r['phone'] ?? ''),
                (string) ($r['amount_paid'] ?? ''),
                (string) ($r['ticket_id'] ?? ''),
            ], ';');
        }
    }
    exit;
}

function att_h(string $v): string
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
  <title>Presenças — Admin</title>
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
    .stats { display: flex; gap: 1.5rem; margin-bottom: 1rem; font-size: .85rem; color: rgba(245,239,230,.55); }
    .stats strong { color: var(--gold-l); font-weight: 400; }
    table { width: 100%; border-collapse: collapse; font-size: .82rem; }
    th, td { padding: .55rem .5rem; border-bottom: 1px solid rgba(245,239,230,.08); text-align: left; }
    th { font-size: .62rem; letter-spacing: .1em; text-transform: uppercase; color: rgba(245,239,230,.4); }
    .mono { font-family: ui-monospace, monospace; font-size: .75rem; }
    .empty { padding: 2rem; text-align: center; color: rgba(245,239,230,.4); }
    .hint-box {
      background: rgba(212,168,90,.08); border: 1px solid rgba(212,168,90,.22);
      padding: .85rem 1rem; border-radius: 8px; font-size: .8rem; line-height: 1.55;
      color: rgba(245,239,230,.65); margin-bottom: 1rem;
    }
    .flash { background: rgba(45,106,79,.18); border: 1px solid rgba(45,106,79,.36); padding: .65rem .85rem; margin-bottom: .9rem; border-radius: 8px; font-size: .82rem; }
    .flash-error { background: rgba(139,48,48,.2); border-color: rgba(180,70,70,.45); }
    .btn { border: 1px solid rgba(245,239,230,.18); background: rgba(245,239,230,.06); color: var(--bone); cursor: pointer;
      padding: .44rem .7rem; border-radius: 8px; font-size: .72rem; text-transform: uppercase; letter-spacing: .08em; }
    .email-edit { display: flex; gap: .35rem; align-items: center; flex-wrap: wrap; min-width: 12rem; }
    .email-edit input {
      flex: 1 1 10rem; min-width: 9rem; max-width: 16rem;
      background: rgba(245,239,230,.06); border: 1px solid rgba(245,239,230,.16); color: var(--bone);
      padding: .35rem .45rem; border-radius: 6px; font-size: .78rem; font-family: inherit;
    }
    .email-edit .btn-save { padding: .32rem .5rem; font-size: .62rem; }
    .email-placeholder-hint { font-size: .68rem; color: rgba(245,239,230,.4); font-style: italic; }
    .tag-phone { font-size: .62rem; color: #6bcf9a; letter-spacing: .06em; text-transform: uppercase; }
  </style>
</head>
<body class="has-bottom-tabs">
<?php
$__adminNav = 'attendance';
$__exportEventId = $selectedEvent > 0 ? $selectedEvent : null;
require __DIR__ . '/_topbar.php';
?>

<main class="main">
  <div class="head">
    <h1>Presenças por evento</h1>
    <p>
      Lista de quem <strong>entrou na porta</strong> (check-in por QR ou manual).
      Estes emails qualificam para o preço de <strong>dançarino·a de regresso</strong> na edição seguinte
      (preço mínimo configurável em Eventos → <code>returning_min_eur</code>, predefinição 15€).
    </p>
  </div>

  <?php if ($flash !== ''): ?>
    <div class="flash<?= $flashError ? ' flash-error' : '' ?>"><?= att_h($flash) ?></div>
  <?php endif; ?>

  <?php if ($isEvent01): ?>
    <div class="hint-box">
      <strong>Edição #01</strong> — folha à porta: 12 bilhetes (300€), <strong>10 presentes</strong>.
      Ausentes: Joana Silva, Ana Luísa Saraiva (bilhete válido, sem desconto de regresso).
      William, Marco e Leonore entram com telemóvel até terem email na próxima reserva.
    </div>
  <?php else: ?>
    <div class="hint-box">
      A tabela <code>event_attendance</code> é preenchida automaticamente no scanner.
      Bilhetes vendidos mas sem check-in não aparecem aqui — só quem veio.
    </div>
  <?php endif; ?>

  <form method="get" class="toolbar">
    <label>
      <span class="visually-hidden">Evento</span>
      <select name="event_id" onchange="this.form.submit()">
        <?php foreach ($events as $ev): ?>
          <option value="<?= (int) $ev['id'] ?>" <?= (int) $ev['id'] === $selectedEvent ? 'selected' : '' ?>>
            <?= att_h((string) $ev['title']) ?> — <?= date('d/m/Y', strtotime((string) $ev['date'])) ?>
            (<?= (int) $ev['attended'] ?> presentes / <?= (int) $ev['sold'] ?> bilhetes)
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if ($selectedEvent > 0): ?>
      <a href="?event_id=<?= $selectedEvent ?>&amp;export=csv">Exportar CSV</a>
      <a href="/admin/scan.php?event_id=<?= $selectedEvent ?>">Scanner QR</a>
      <a href="/admin/participants.php?event_id=<?= $selectedEvent ?>">Corrigir / marcar presenças</a>
    <?php endif; ?>
    <?php if ($isEvent01): ?>
      <form method="post" style="display:inline;">
        <input type="hidden" name="action" value="import_event_01" />
        <input type="hidden" name="event_id" value="<?= (int) $event01Id ?>" />
        <button type="submit" class="btn">Importar folha #01</button>
      </form>
    <?php endif; ?>
  </form>

  <?php if ($sel): ?>
    <div class="stats">
      <span><strong><?= count($rows) ?></strong> presentes</span>
      <span><strong><?= (int) $sel['sold'] ?></strong> bilhetes confirmados</span>
      <?php if (!empty($sel['returning_min_eur'])): ?>
        <span>Preço regresso: <strong><?= number_format((float) $sel['returning_min_eur'], 2, ',', ' ') ?> €</strong></span>
      <?php else: ?>
        <span>Preço regresso: <strong>15,00 €</strong> (predefinição)</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (empty($rows)): ?>
    <p class="empty">Ninguém com check-in registado para este evento.<br>Usa o scanner na porta ou marca entrada em Check-in.</p>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table>
        <thead>
          <tr>
            <th>Entrada</th>
            <th>Nome</th>
            <th>Email</th>
            <th>Telemóvel</th>
            <th>Valor</th>
            <th>Bilhete</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
              $rowEmail = (string) $r['email'];
              $isPlaceholderEmail = edv_is_placeholder_presence_email($rowEmail);
              $emailInputValue = $isPlaceholderEmail ? '' : $rowEmail;
          ?>
            <tr>
              <td class="mono"><?= att_h(date('d/m/Y H:i', strtotime((string) $r['checked_in_at']))) ?></td>
              <td><?= att_h((string) $r['name']) ?></td>
              <td>
                <form method="post" class="email-edit">
                  <input type="hidden" name="action" value="update_email" />
                  <input type="hidden" name="event_id" value="<?= (int) $selectedEvent ?>" />
                  <input type="hidden" name="attendance_id" value="<?= (int) $r['id'] ?>" />
                  <input type="email" name="email" value="<?= att_h($emailInputValue) ?>" placeholder="email@exemplo.com" autocomplete="email" required />
                  <button type="submit" class="btn btn-save">Guardar</button>
                </form>
                <?php if ($isPlaceholderEmail): ?>
                  <div class="email-placeholder-hint">
                    sem email
                    <?php if ((string) ($r['phone'] ?? '') !== ''): ?>
                      <span class="tag-phone">tel</span>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td class="mono"><?= att_h((string) $r['phone']) ?></td>
              <td><?= number_format((float) $r['amount_paid'], 2, ',', ' ') ?> €</td>
              <td class="mono"><?= att_h(substr((string) $r['ticket_id'], 0, 8)) ?>…</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
