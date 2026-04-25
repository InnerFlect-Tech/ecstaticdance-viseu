<?php
/* ============================================================
   index.php — Admin panel: ticket list + QR scanner
   ============================================================ */

require_once __DIR__ . '/auth.php';
require_admin_session();

// Load events for filter
$events_stmt = db()->query(
    'SELECT e.id, e.title, e.date, e.type,
            COUNT(t.id) AS total,
            SUM(t.checked_in) AS checked_in
     FROM events e
     LEFT JOIN tickets t ON t.event_id = e.id AND t.payment_status IN (\'paid\', \'free\')
     GROUP BY e.id
     ORDER BY e.date DESC
     LIMIT 20'
);
$events = $events_stmt->fetchAll();

$selected_event = (int)($_GET['event_id'] ?? ($events[0]['id'] ?? 0));
$search         = trim($_GET['q'] ?? '');
$filter_status  = $_GET['status'] ?? 'all';

// Load tickets for selected event
$tickets = [];
if ($selected_event) {
    $where  = ['t.event_id = ?'];
    $params = [$selected_event];

    if ($search !== '') {
        $where[] = '(t.name LIKE ? OR t.email LIKE ?)';
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    if ($filter_status === 'checked_in') {
        $where[] = 't.checked_in = 1';
    } elseif ($filter_status === 'not_checked') {
        $where[] = 't.checked_in = 0';
    } elseif ($filter_status !== 'all') {
        // pending, paid, free
        $where[]  = 't.payment_status = ?';
        $params[] = $filter_status;
    } else {
        $where[] = "t.payment_status IN ('paid','free')";
    }

    $sql = 'SELECT t.* FROM tickets t WHERE ' . implode(' AND ', $where) . ' ORDER BY t.created_at DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll();
}

// Stats for selected event
$stats = ['total' => 0, 'paid' => 0, 'free' => 0, 'checked_in' => 0];
if ($selected_event) {
    $s = db()->prepare(
        'SELECT
             COUNT(*) AS total,
             SUM(payment_status = \'paid\') AS paid,
             SUM(payment_status = \'free\') AS free,
             SUM(checked_in = 1) AS checked_in
         FROM tickets
         WHERE event_id = ? AND payment_status IN (\'paid\', \'free\')'
    );
    $s->execute([$selected_event]);
    $stats = $s->fetch();
}

$sel_event_obj = null;
foreach ($events as $ev) {
    if ((int)$ev['id'] === $selected_event) { $sel_event_obj = $ev; break; }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex,nofollow" />
<title>Admin — Ecstatic Dance Viseu</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --dark: #0E0B09; --dark-m: #1A1210; --dark-l: #2A1E1A;
    --bone: #F5EFE6; --bone-d: #E8DCCC;
    --terra: #8B3A2A; --terra-l: #C4593F; --terra-d: #5C2218;
    --gold: #B8924A; --gold-l: #D4A85A;
    --verde: #1E2E27; --verde-m: #2A3D35;
    --success: #2d6a4f; --success-l: #40916c;
  }
  body { background: var(--dark); color: var(--bone); font-family: Arial, sans-serif; font-weight: 300; font-size: 14px; }
  a { color: inherit; text-decoration: none; }

  /* ── TOP BAR ── */
  .topbar { background: var(--dark-m); border-bottom: 1px solid rgba(245,239,230,.08);
            padding: 1rem 2rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
  .topbar-brand { font-size: .7rem; letter-spacing: .2em; text-transform: uppercase; color: var(--gold); font-weight: 400; }
  .topbar-actions { display: flex; gap: .75rem; align-items: center; }
  .btn { display: inline-block; padding: .55rem 1.2rem; font-size: .7rem; letter-spacing: .14em;
         text-transform: uppercase; cursor: pointer; font-family: inherit; font-weight: 400; border: none; transition: background .2s; }
  .btn-primary { background: var(--terra); color: var(--bone); }
  .btn-primary:hover { background: var(--terra-l); }
  .btn-outline { background: transparent; color: rgba(245,239,230,.5); border: 1px solid rgba(245,239,230,.15); }
  .btn-outline:hover { border-color: rgba(245,239,230,.4); color: var(--bone); }
  .btn-scan { background: var(--verde); color: var(--bone); }
  .btn-scan:hover { background: var(--verde-m); }

  /* ── LAYOUT ── */
  .layout { display: flex; min-height: calc(100vh - 57px); }
  .sidebar { width: 240px; flex-shrink: 0; background: var(--dark-m); border-right: 1px solid rgba(245,239,230,.07);
             padding: 1.5rem 0; overflow-y: auto; }
  .sidebar-label { font-size: .6rem; letter-spacing: .2em; text-transform: uppercase; color: rgba(245,239,230,.3);
                   padding: .5rem 1.5rem 1rem; display: block; font-weight: 400; }
  .sidebar-event { display: block; padding: .75rem 1.5rem; border-left: 2px solid transparent;
                   transition: background .15s; cursor: pointer; }
  .sidebar-event:hover { background: rgba(245,239,230,.04); }
  .sidebar-event.active { border-left-color: var(--terra); background: rgba(139,58,42,.1); }
  .sidebar-event-title { font-size: .82rem; color: var(--bone); font-weight: 400; line-height: 1.3; }
  .sidebar-event-date  { font-size: .7rem; color: rgba(245,239,230,.35); margin-top: .2rem; }
  .sidebar-event-count { font-size: .7rem; color: var(--gold); margin-top: .3rem; }

  /* ── MAIN ── */
  .main { flex: 1; padding: 2rem; overflow-x: auto; }
  .page-header { margin-bottom: 2rem; }
  .page-header h1 { font-size: 1.5rem; font-weight: 300; color: var(--bone); margin-bottom: .3rem; }
  .page-header p  { font-size: .82rem; color: rgba(245,239,230,.4); }

  /* ── STATS ── */
  .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 2rem; }
  .stat-box { background: var(--dark-l); padding: 1.2rem 1.5rem; }
  .stat-box .num { font-size: 2rem; font-weight: 300; color: var(--bone); line-height: 1; margin-bottom: .4rem; }
  .stat-box .lbl { font-size: .65rem; letter-spacing: .16em; text-transform: uppercase; color: rgba(245,239,230,.35); font-weight: 400; }
  .stat-box.highlight .num { color: var(--gold-l); }

  /* ── TOOLBAR ── */
  .toolbar { display: flex; gap: .75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1.5rem; }
  .search-input { background: rgba(245,239,230,.05); border: 1px solid rgba(245,239,230,.12); color: var(--bone);
                  padding: .5rem 1rem; font-size: .82rem; font-family: inherit; outline: none;
                  width: 220px; }
  .search-input:focus { border-color: rgba(245,239,230,.25); }
  .search-input::placeholder { color: rgba(245,239,230,.2); }
  .filter-select { background: rgba(245,239,230,.05); border: 1px solid rgba(245,239,230,.12); color: var(--bone);
                   padding: .5rem .9rem; font-size: .82rem; font-family: inherit; outline: none; cursor: pointer; }
  .filter-select option { background: var(--dark-m); }
  .spacer { flex: 1; }

  /* ── TABLE ── */
  .table-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: .82rem; }
  th { font-size: .62rem; letter-spacing: .16em; text-transform: uppercase; color: rgba(245,239,230,.35);
       font-weight: 400; padding: .7rem 1rem; text-align: left; border-bottom: 1px solid rgba(245,239,230,.08); white-space: nowrap; }
  td { padding: .75rem 1rem; border-bottom: 1px solid rgba(245,239,230,.05); color: rgba(245,239,230,.75); vertical-align: middle; }
  tr:hover td { background: rgba(245,239,230,.02); }
  .badge { display: inline-block; padding: .25rem .6rem; font-size: .65rem; letter-spacing: .1em; text-transform: uppercase; font-weight: 400; }
  .badge-paid  { background: rgba(45,106,79,.25); color: #40916c; }
  .badge-free  { background: rgba(184,146,74,.15); color: var(--gold); }
  .badge-pending { background: rgba(245,239,230,.06); color: rgba(245,239,230,.4); }
  .checkin-toggle { cursor: pointer; background: none; border: 1px solid rgba(245,239,230,.15);
                    color: rgba(245,239,230,.4); padding: .3rem .7rem; font-size: .7rem; font-family: inherit;
                    transition: all .15s; white-space: nowrap; }
  .checkin-toggle:hover { border-color: rgba(245,239,230,.3); color: var(--bone); }
  .checkin-toggle.in { background: rgba(45,106,79,.3); border-color: rgba(64,145,108,.5); color: #40916c; }
  .ticket-id-short { font-family: monospace; font-size: .7rem; color: rgba(245,239,230,.3); }
  .empty-state { text-align: center; padding: 4rem; color: rgba(245,239,230,.25); font-style: italic; }

  /* ── QR SCANNER MODAL ── */
  .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(14,11,9,.85);
                    z-index: 100; align-items: center; justify-content: center; }
  .modal-backdrop.open { display: flex; }
  .modal { background: var(--dark-m); border: 1px solid rgba(245,239,230,.1);
           width: 100%; max-width: 480px; padding: 2rem; position: relative; }
  .modal-title { font-size: 1.2rem; font-weight: 300; color: var(--bone); margin-bottom: 1.5rem; }
  .modal-close { position: absolute; top: 1rem; right: 1rem; background: none; border: none;
                 color: rgba(245,239,230,.4); cursor: pointer; font-size: 1.2rem; line-height: 1; }
  .modal-close:hover { color: var(--bone); }
  #reader { width: 100%; background: #000; min-height: 280px; position: relative; }
  .scan-result { margin-top: 1.5rem; padding: 1.2rem; text-align: center; font-size: .9rem;
                 font-weight: 400; display: none; line-height: 1.5; }
  .scan-result.ok  { background: rgba(45,106,79,.3); color: #40916c; border: 1px solid rgba(64,145,108,.4); }
  .scan-result.err { background: rgba(196,89,63,.2); color: #e07050; border: 1px solid rgba(196,89,63,.4); }
  .scan-result .scan-name { font-size: 1.1rem; display: block; margin-bottom: .3rem; }
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="topbar">
  <span class="topbar-brand">Ecstatic Dance Viseu — Admin</span>
  <div class="topbar-actions">
    <button class="btn btn-scan" id="openScannerBtn">Scan QR</button>
    <?php if ($selected_event): ?>
      <a href="/admin/export.php?event_id=<?= $selected_event ?>" class="btn btn-outline">Exportar CSV</a>
    <?php endif; ?>
    <a href="/admin/logout.php" class="btn btn-outline">Sair</a>
  </div>
</div>

<!-- LAYOUT -->
<div class="layout">

  <!-- SIDEBAR: events list -->
  <nav class="sidebar">
    <span class="sidebar-label">Eventos</span>
    <?php foreach ($events as $ev): ?>
      <a href="?event_id=<?= $ev['id'] ?>"
         class="sidebar-event <?= (int)$ev['id'] === $selected_event ? 'active' : '' ?>">
        <div class="sidebar-event-title"><?= htmlspecialchars($ev['title']) ?></div>
        <div class="sidebar-event-date"><?= date('d/m/Y', strtotime($ev['date'])) ?></div>
        <div class="sidebar-event-count"><?= (int)$ev['checked_in'] ?>/<?= (int)$ev['total'] ?> entradas</div>
      </a>
    <?php endforeach; ?>
    <?php if (empty($events)): ?>
      <p style="padding:1rem 1.5rem;font-size:.8rem;color:rgba(245,239,230,.3)">Sem eventos ainda.</p>
    <?php endif; ?>
  </nav>

  <!-- MAIN -->
  <main class="main">
    <?php if ($sel_event_obj): ?>

      <div class="page-header">
        <h1><?= htmlspecialchars($sel_event_obj['title']) ?></h1>
        <p><?= date('d \d\e F \d\e Y', strtotime($sel_event_obj['date'])) ?>
          &nbsp;·&nbsp;
          <?= $sel_event_obj['type'] === 'paid' ? 'Pago' : 'Gratuito' ?>
        </p>
      </div>

      <!-- Stats -->
      <div class="stats-row">
        <div class="stat-box highlight">
          <div class="num"><?= (int)$stats['total'] ?></div>
          <div class="lbl">Bilhetes</div>
        </div>
        <div class="stat-box">
          <div class="num"><?= (int)$stats['paid'] ?></div>
          <div class="lbl">Pagos</div>
        </div>
        <div class="stat-box">
          <div class="num"><?= (int)$stats['free'] ?></div>
          <div class="lbl">Gratuitos</div>
        </div>
        <div class="stat-box">
          <div class="num"><?= (int)$stats['checked_in'] ?></div>
          <div class="lbl">Entradas</div>
        </div>
      </div>

      <!-- Toolbar -->
      <form method="GET" class="toolbar">
        <input type="hidden" name="event_id" value="<?= $selected_event ?>">
        <input class="search-input" type="text" name="q" placeholder="Nome ou email…"
               value="<?= htmlspecialchars($search) ?>">
        <select class="filter-select" name="status" onchange="this.form.submit()">
          <option value="all"        <?= $filter_status === 'all'         ? 'selected' : '' ?>>Todos</option>
          <option value="paid"       <?= $filter_status === 'paid'        ? 'selected' : '' ?>>Pagos</option>
          <option value="free"       <?= $filter_status === 'free'        ? 'selected' : '' ?>>Gratuitos</option>
          <option value="checked_in" <?= $filter_status === 'checked_in'  ? 'selected' : '' ?>>Com entrada</option>
          <option value="not_checked"<?= $filter_status === 'not_checked' ? 'selected' : '' ?>>Sem entrada</option>
        </select>
        <button type="submit" class="btn btn-outline">Filtrar</button>
        <span class="spacer"></span>
        <span style="font-size:.75rem;color:rgba(245,239,230,.3)"><?= count($tickets) ?> resultado<?= count($tickets) !== 1 ? 's' : '' ?></span>
      </form>

      <!-- Ticket table -->
      <div class="table-wrap">
        <?php if (!empty($tickets)): ?>
        <table>
          <thead>
            <tr>
              <th>Nome</th>
              <th>Email</th>
              <th>Telemóvel</th>
              <th>Estado</th>
              <th>Valor</th>
              <th>Reservado</th>
              <th>Entrada</th>
              <th>ID</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tickets as $t): ?>
            <tr data-ticket-id="<?= htmlspecialchars($t['id']) ?>">
              <td><?= htmlspecialchars($t['name']) ?></td>
              <td><?= htmlspecialchars($t['email']) ?></td>
              <td><?= htmlspecialchars($t['phone']) ?></td>
              <td>
                <span class="badge badge-<?= htmlspecialchars($t['payment_status']) ?>">
                  <?= match($t['payment_status']) {
                      'paid'    => 'Pago',
                      'free'    => 'Gratuito',
                      'pending' => 'Pendente',
                      default   => htmlspecialchars($t['payment_status']),
                  } ?>
                </span>
              </td>
              <td><?= $t['amount_paid'] > 0 ? '€' . number_format((float)$t['amount_paid'], 2) : '—' ?></td>
              <td><?= date('d/m H:i', strtotime($t['created_at'])) ?></td>
              <td>
                <button class="checkin-toggle <?= $t['checked_in'] ? 'in' : '' ?>"
                        onclick="toggleCheckin(this, '<?= htmlspecialchars($t['id']) ?>', <?= $t['checked_in'] ? 'true' : 'false' ?>)">
                  <?= $t['checked_in']
                      ? '✓ ' . date('H:i', strtotime($t['checked_in_at']))
                      : 'Marcar entrada' ?>
                </button>
              </td>
              <td><span class="ticket-id-short"><?= htmlspecialchars(substr($t['id'], 0, 8)) ?>…</span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <div class="empty-state">Sem bilhetes encontrados.</div>
        <?php endif; ?>
      </div>

    <?php else: ?>
      <div class="empty-state" style="padding-top:5rem">
        Selecciona um evento na barra lateral.
      </div>
    <?php endif; ?>
  </main>
</div>


<!-- QR SCANNER MODAL -->
<div class="modal-backdrop" id="scannerModal" role="dialog" aria-modal="true" aria-label="Scanner QR">
  <div class="modal">
    <button class="modal-close" id="closeScannerBtn" aria-label="Fechar">&times;</button>
    <p class="modal-title">Scan QR code</p>
    <div id="reader"></div>
    <div class="scan-result" id="scanResult"></div>
  </div>
</div>


<script>
// ── Manual check-in toggle ──
async function toggleCheckin(btn, ticketId, currentState) {
  const newState = !currentState;
  btn.disabled = true;

  try {
    const res = await fetch('/admin/checkin.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ticket_id: ticketId, checked_in: newState }),
    });
    const data = await res.json();
    if (data.ok) {
      btn.classList.toggle('in', newState);
      btn.textContent = newState ? '✓ Agora' : 'Marcar entrada';
      btn.onclick = () => toggleCheckin(btn, ticketId, newState);
    } else {
      alert(data.error || 'Erro ao actualizar.');
    }
  } catch {
    alert('Erro de rede. Tenta novamente.');
  }
  btn.disabled = false;
}

// ── QR Scanner (qr-scanner via CDN) ──
const openBtn    = document.getElementById('openScannerBtn');
const closeBtn   = document.getElementById('closeScannerBtn');
const modal      = document.getElementById('scannerModal');
const scanResult = document.getElementById('scanResult');

let scanner = null;
let scanCooldown = false;

openBtn.addEventListener('click', startScanner);
closeBtn.addEventListener('click', stopScanner);
modal.addEventListener('click', e => { if (e.target === modal) stopScanner(); });

async function startScanner() {
  modal.classList.add('open');
  scanResult.style.display = 'none';
  scanResult.className = 'scan-result';

  // Lazy-load qr-scanner from CDN
  if (!window.QrScanner) {
    const script = document.createElement('script');
    script.src = 'https://unpkg.com/qr-scanner@1/qr-scanner.umd.min.js';
    document.head.appendChild(script);
    await new Promise((res, rej) => { script.onload = res; script.onerror = rej; });
  }

  const videoEl = document.createElement('video');
  document.getElementById('reader').innerHTML = '';
  document.getElementById('reader').appendChild(videoEl);

  scanner = new QrScanner(
    videoEl,
    result => handleScan(result.data),
    {
      highlightScanRegion: true,
      highlightCodeOutline: true,
      preferredCamera: 'environment',
    }
  );
  await scanner.start();
}

function stopScanner() {
  if (scanner) {
    scanner.stop();
    scanner.destroy();
    scanner = null;
  }
  document.getElementById('reader').innerHTML = '';
  modal.classList.remove('open');
}

async function handleScan(code) {
  if (scanCooldown || !code) return;
  scanCooldown = true;

  // Vibrate on supported devices
  if (navigator.vibrate) navigator.vibrate(100);

  try {
    const res  = await fetch('/api/verify-ticket.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code }),
    });
    const data = await res.json();

    if (data.ok) {
      showScanResult('ok',
        `<span class="scan-name">${escHtml(data.ticket.name)}</span>
         Entrada válida &nbsp;✓<br>
         <small style="opacity:.7">${escHtml(data.ticket.event_title)}</small>`
      );
      if (navigator.vibrate) navigator.vibrate([100, 50, 100]);
    } else {
      showScanResult('err',
        `<span class="scan-name">Inválido</span>${escHtml(data.error)}`
      );
      if (navigator.vibrate) navigator.vibrate([300]);
    }
  } catch {
    showScanResult('err', 'Erro de rede. Verifica a ligação.');
  }

  setTimeout(() => { scanCooldown = false; }, 3000);
}

function showScanResult(type, html) {
  scanResult.className = `scan-result ${type}`;
  scanResult.innerHTML = html;
  scanResult.style.display = '';
  setTimeout(() => { scanResult.style.display = 'none'; }, 5000);
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Keyboard shortcut: Escape closes modal
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && modal.classList.contains('open')) stopScanner();
});
</script>

</body>
</html>
