<?php
declare(strict_types=1);

/**
 * Página dedicada de check-in por QR — optimizada para telemóvel na porta.
 */
require_once __DIR__ . '/auth.php';
require_admin_session();

$eventsStmt = db()->query(
    "SELECT e.id, e.title, e.date, e.is_active,
            COUNT(t.id) AS total,
            SUM(t.checked_in) AS checked_in
     FROM events e
     LEFT JOIN tickets t ON t.event_id = e.id AND t.payment_status IN ('paid', 'free')
     GROUP BY e.id
     ORDER BY e.is_active DESC, e.date DESC
     LIMIT 30"
);
$events = $eventsStmt->fetchAll();

$selectedEvent = (int) ($_GET['event_id'] ?? 0);
if ($selectedEvent <= 0 && !empty($events)) {
    foreach ($events as $ev) {
        if ((int) $ev['is_active'] === 1) {
            $selectedEvent = (int) $ev['id'];
            break;
        }
    }
    if ($selectedEvent <= 0) {
        $selectedEvent = (int) $events[0]['id'];
    }
}

$sel = null;
foreach ($events as $ev) {
    if ((int) $ev['id'] === $selectedEvent) {
        $sel = $ev;
        break;
    }
}

$checkedIn = $sel ? (int) $sel['checked_in'] : 0;
$total = $sel ? (int) $sel['total'] : 0;
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="robots" content="noindex,nofollow" />
  <meta name="theme-color" content="#0E0B09" />
  <title>Scanner QR — Admin</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --dark: #0E0B09; --dark-m: #1A1210; --bone: #F5EFE6;
      --gold: #B8924A; --gold-l: #D4A85A; --ok: #40916c; --warn: #d4a85a;
    }
    body {
      background: var(--dark);
      color: var(--bone);
      font-family: Arial, sans-serif;
      font-weight: 300;
      font-size: 14px;
      min-height: 100dvh;
    }
    <?php require __DIR__ . '/_topbar-styles.php'; ?>
    <?php require __DIR__ . '/_scanner-styles.php'; ?>

    .scan-page {
      max-width: 520px;
      margin: 0 auto;
      padding: 1rem 1rem calc(4.5rem + env(safe-area-inset-bottom, 0px));
    }
    @media (min-width: 768px) {
      .scan-page { padding-bottom: 2rem; max-width: 560px; }
    }
    .scan-page-head { margin-bottom: 1rem; }
    .scan-page-head h1 {
      font-size: 1.35rem;
      font-weight: 300;
      margin-bottom: 0.35rem;
    }
    .scan-page-head p {
      font-size: 0.82rem;
      color: rgba(245, 239, 230, 0.5);
      line-height: 1.5;
    }
    .scan-event-row {
      display: grid;
      gap: 0.65rem;
      margin-bottom: 1rem;
    }
    .scan-event-row label {
      font-size: 0.62rem;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: rgba(245, 239, 230, 0.42);
    }
    .scan-event-row select {
      width: 100%;
      background: rgba(245, 239, 230, 0.06);
      border: 1px solid rgba(245, 239, 230, 0.16);
      color: var(--bone);
      padding: 0.55rem 0.65rem;
      border-radius: 8px;
      font-size: 0.9rem;
      font-family: inherit;
    }
    .scan-stats {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.65rem;
      margin-bottom: 1rem;
    }
    .scan-stat {
      background: var(--dark-m);
      border: 1px solid rgba(245, 239, 230, 0.08);
      border-radius: 10px;
      padding: 0.85rem 1rem;
      text-align: center;
    }
    .scan-stat .num {
      font-size: 1.75rem;
      font-weight: 300;
      color: var(--gold-l);
      line-height: 1.1;
    }
    .scan-stat .lbl {
      font-size: 0.65rem;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: rgba(245, 239, 230, 0.4);
      margin-top: 0.25rem;
    }
    .scan-reader-wrap {
      background: #000;
      border-radius: 12px;
      overflow: hidden;
      border: 1px solid rgba(245, 239, 230, 0.1);
      margin-bottom: 1rem;
    }
    #scanReader {
      width: 100%;
      min-height: min(52vh, 360px);
      position: relative;
    }
    #scanReader video {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .scan-loading, .scan-error {
      padding: 2rem 1.25rem;
      text-align: center;
      font-size: 0.88rem;
      color: rgba(245, 239, 230, 0.55);
      line-height: 1.6;
    }
    .scan-toolbar {
      display: flex;
      gap: 0.5rem;
      margin-bottom: 1rem;
      flex-wrap: wrap;
    }
    .scan-btn {
      flex: 1;
      min-width: 140px;
      appearance: none;
      border: 1px solid rgba(245, 239, 230, 0.18);
      background: rgba(245, 239, 230, 0.06);
      color: var(--bone);
      padding: 0.5rem 0.75rem;
      border-radius: 8px;
      font-size: 0.72rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      cursor: pointer;
      font-family: inherit;
    }
    .scan-btn:hover { border-color: rgba(245, 239, 230, 0.35); }
    .scan-btn--primary {
      background: rgba(45, 106, 79, 0.25);
      border-color: rgba(64, 145, 108, 0.45);
      color: #8fd4a8;
    }
    .scan-manual {
      background: var(--dark-m);
      border: 1px solid rgba(245, 239, 230, 0.08);
      border-radius: 10px;
      padding: 1rem;
      margin-bottom: 1rem;
    }
    .scan-manual legend {
      font-size: 0.62rem;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: rgba(245, 239, 230, 0.42);
      padding: 0 0 0.5rem;
    }
    .scan-manual-row {
      display: flex;
      gap: 0.5rem;
    }
    .scan-manual input {
      flex: 1;
      min-width: 0;
      background: rgba(245, 239, 230, 0.06);
      border: 1px solid rgba(245, 239, 230, 0.16);
      color: var(--bone);
      padding: 0.55rem 0.65rem;
      border-radius: 8px;
      font-size: 0.85rem;
      font-family: ui-monospace, monospace;
    }
    .scan-history-panel h2 {
      font-size: 0.62rem;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: rgba(245, 239, 230, 0.38);
      margin-bottom: 0.55rem;
    }
    .scan-history-list {
      list-style: none;
      margin: 0;
      padding: 0;
    }
    .scan-history-item {
      font-size: 0.82rem;
      padding: 0.45rem 0;
      border-bottom: 1px solid rgba(245, 239, 230, 0.06);
      color: rgba(245, 239, 230, 0.65);
    }
    .scan-history-item.ok { color: #8fd4a8; }
    .scan-history-item.bad { color: #e8a090; }
    .scan-history-time {
      font-variant-numeric: tabular-nums;
      color: rgba(245, 239, 230, 0.35);
      margin-right: 0.35rem;
    }
    .scan-history-empty {
      font-size: 0.8rem;
      color: rgba(245, 239, 230, 0.35);
    }
    .scan-foot-links {
      margin-top: 1.25rem;
      font-size: 0.78rem;
      text-align: center;
    }
    .scan-foot-links a { color: var(--gold-l); }
  </style>
</head>
<body class="has-bottom-tabs" id="scanPage">

<?php
$__adminNav = 'scan';
$__hideDbBackup = false;
require __DIR__ . '/_topbar.php';
?>

<main class="scan-page">
  <header class="scan-page-head">
    <h1>Scanner QR</h1>
    <p>Aponta à câmara o código do email de confirmação. Cada leitura válida regista a entrada automaticamente.</p>
  </header>

  <div class="scan-event-row">
    <label for="scanEventSelect">Evento activo na porta</label>
    <select id="scanEventSelect" name="event_id" aria-label="Seleccionar evento">
      <?php if (empty($events)): ?>
        <option value="">Sem eventos</option>
      <?php else: ?>
        <?php foreach ($events as $ev): ?>
          <option
            value="<?= (int) $ev['id'] ?>"
            <?= (int) $ev['id'] === $selectedEvent ? 'selected' : '' ?>
          >
            <?= htmlspecialchars((string) $ev['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            — <?= date('d/m/Y', strtotime((string) $ev['date'])) ?>
            <?= (int) $ev['is_active'] === 1 ? ' · activo' : '' ?>
          </option>
        <?php endforeach; ?>
      <?php endif; ?>
    </select>
  </div>

  <div class="scan-stats" aria-live="polite">
    <div class="scan-stat">
      <div class="num" id="scanStatIn"><?= $checkedIn ?></div>
      <div class="lbl">Entradas</div>
    </div>
    <div class="scan-stat">
      <div class="num"><?= $total ?></div>
      <div class="lbl">Bilhetes confirmados</div>
    </div>
  </div>

  <div class="scan-reader-wrap">
    <div id="scanReader" role="region" aria-label="Leitor de câmara QR"></div>
  </div>

  <div id="scanResult" class="scan-result" hidden aria-live="assertive"></div>

  <div class="scan-toolbar">
    <button type="button" class="scan-btn" id="scanRestartCamera">Reiniciar câmara</button>
    <a href="/admin/?event_id=<?= $selectedEvent ?>" class="scan-btn">Lista de bilhetes</a>
  </div>

  <form id="scanManualForm" class="scan-manual">
    <fieldset style="border:0;padding:0;margin:0">
      <legend>Código manual</legend>
      <p style="font-size:0.8rem;color:rgba(245,239,230,.45);margin-bottom:0.65rem;line-height:1.5">
        Cola o UUID do bilhete ou o texto do QR se a câmara falhar.
      </p>
      <div class="scan-manual-row">
        <input
          type="text"
          id="scanManualCode"
          name="code"
          placeholder="ex. a1b2c3d4-…"
          autocomplete="off"
          autocapitalize="off"
          spellcheck="false"
          inputmode="text"
        />
        <button type="submit" class="scan-btn scan-btn--primary">Validar</button>
      </div>
    </fieldset>
  </form>

  <section class="scan-history-panel" aria-label="Últimas leituras">
    <h2>Últimas leituras</h2>
    <ul id="scanHistory" class="scan-history-list">
      <li class="scan-history-empty">Ainda sem leituras nesta sessão.</li>
    </ul>
  </section>

  <p class="scan-foot-links">
    <a href="/admin/link-bookings.php">Inscrições /links</a>
    ·
    <a href="/admin/events.php">Eventos</a>
  </p>
</main>

<script src="/admin/assets/admin-scanner.js" defer></script>
</body>
</html>
