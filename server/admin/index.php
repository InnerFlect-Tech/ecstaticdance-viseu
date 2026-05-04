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
  body { background: var(--dark); color: var(--bone); font-family: Arial, sans-serif; font-weight: 300; font-size: 14px; min-height: 100dvh; }
  a { color: inherit; text-decoration: none; }

  /* ── BUTTONS (forms / table toolbar) ── */
  .btn { display: inline-block; padding: .55rem 1.2rem; font-size: .7rem; letter-spacing: .14em;
         text-transform: uppercase; cursor: pointer; font-family: inherit; font-weight: 400; border: none; transition: background .2s; }
  .btn-outline { background: transparent; color: rgba(245,239,230,.5); border: 1px solid rgba(245,239,230,.15); }
  .btn-outline:hover { border-color: rgba(245,239,230,.4); color: var(--bone); }
  <?php require __DIR__ . '/_topbar-styles.php'; ?>

  /* ── LAYOUT ── */
  /* ── LAYOUT ── */
  .layout { display: flex; min-height: calc(100dvh - 52px); position: relative; }
  @media (min-width: 768px) { .layout { min-height: calc(100dvh - 56px); } }

  /* ── SIDEBAR ── */
  /* Mobile: horizontal scrolling pill-list of events above main content */
  .sidebar {
    display: none; /* replaced by mobile-events-strip on small screens */
    width: 220px;
    flex-shrink: 0;
    background: var(--dark-m);
    border-right: 1px solid rgba(245,239,230,.07);
    padding: 1.25rem 0;
    overflow-y: auto;
  }
  @media (min-width: 768px) { .sidebar { display: block; } }

  .sidebar-label { font-size: .6rem; letter-spacing: .2em; text-transform: uppercase; color: rgba(245,239,230,.3);
                   padding: .5rem 1.5rem 1rem; display: block; font-weight: 400; }
  .sidebar-event { display: block; padding: .75rem 1.5rem; border-left: 2px solid transparent;
                   transition: background .15s; cursor: pointer; }
  .sidebar-event:hover { background: rgba(245,239,230,.04); }
  .sidebar-event.active { border-left-color: var(--terra); background: rgba(139,58,42,.1); }
  .sidebar-event-title { font-size: .82rem; color: var(--bone); font-weight: 400; line-height: 1.3; }
  .sidebar-event-date  { font-size: .7rem; color: rgba(245,239,230,.35); margin-top: .2rem; }
  .sidebar-event-count { font-size: .7rem; color: var(--gold); margin-top: .3rem; }

  /* Mobile event switcher strip */
  .mobile-events-strip {
    display: flex;
    gap: 0.45rem;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    padding: 0.65rem 1rem;
    border-bottom: 1px solid rgba(245,239,230,.06);
    background: var(--dark-m);
  }
  .mobile-events-strip::-webkit-scrollbar { display: none; }
  @media (min-width: 768px) { .mobile-events-strip { display: none; } }
  .mes-pill {
    display: inline-flex;
    flex-direction: column;
    align-items: flex-start;
    flex-shrink: 0;
    padding: 0.5rem 0.8rem;
    border-radius: 10px;
    border: 1px solid rgba(245,239,230,.1);
    background: rgba(245,239,230,.03);
    text-decoration: none;
    max-width: 160px;
  }
  .mes-pill.active {
    border-color: rgba(139,58,42,.5);
    background: rgba(139,58,42,.12);
  }
  .mes-pill-title { font-size: .73rem; font-weight: 400; color: var(--bone); white-space: nowrap;
                    overflow: hidden; text-overflow: ellipsis; max-width: 140px; }
  .mes-pill-date  { font-size: .62rem; color: rgba(245,239,230,.4); margin-top: 1px; }
  .mes-pill-count { font-size: .62rem; color: var(--gold); }

  /* ── MAIN ── */
  .main { flex: 1; padding: 1rem; overflow-x: auto; min-width: 0; }
  @media (min-width: 768px) { .main { padding: 2rem; } }
  .page-header { margin-bottom: 1.25rem; }
  @media (min-width: 768px) { .page-header { margin-bottom: 2rem; } }
  .page-header h1 { font-size: 1.2rem; font-weight: 300; color: var(--bone); margin-bottom: .3rem; }
  @media (min-width: 768px) { .page-header h1 { font-size: 1.5rem; } }
  .page-header p  { font-size: .82rem; color: rgba(245,239,230,.4); }

  /* ── STATS ── */
  .stats-row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.6rem; margin-bottom: 1.25rem; }
  @media (min-width: 540px) { .stats-row { grid-template-columns: repeat(4, 1fr); } }
  @media (min-width: 768px) { .stats-row { gap: 1rem; margin-bottom: 2rem; } }
  .stat-box { background: var(--dark-l); padding: 0.85rem 1rem; border-radius: 10px; }
  @media (min-width: 768px) { .stat-box { padding: 1.2rem 1.5rem; border-radius: 0; } }
  .stat-box .num { font-size: 1.6rem; font-weight: 300; color: var(--bone); line-height: 1; margin-bottom: .3rem; }
  @media (min-width: 768px) { .stat-box .num { font-size: 2rem; margin-bottom: .4rem; } }
  .stat-box .lbl { font-size: .6rem; letter-spacing: .14em; text-transform: uppercase; color: rgba(245,239,230,.35); font-weight: 400; }
  .stat-box.highlight .num { color: var(--gold-l); }

  /* ── TOOLBAR ── */
  .toolbar { display: flex; gap: .55rem; align-items: center; flex-wrap: wrap; margin-bottom: 1.25rem; }
  @media (min-width: 768px) { .toolbar { gap: .75rem; margin-bottom: 1.5rem; } }
  .search-input { background: rgba(245,239,230,.05); border: 1px solid rgba(245,239,230,.12); color: var(--bone);
                  padding: .5rem .85rem; font-size: .82rem; font-family: inherit; outline: none;
                  flex: 1; min-width: 0; border-radius: 8px; height: 40px; }
  @media (min-width: 768px) { .search-input { flex: none; width: 220px; border-radius: 0; } }
  .search-input:focus { border-color: rgba(245,239,230,.25); }
  .search-input::placeholder { color: rgba(245,239,230,.2); }
  .filter-select { background: rgba(245,239,230,.05); border: 1px solid rgba(245,239,230,.12); color: var(--bone);
                   padding: .5rem .75rem; font-size: .82rem; font-family: inherit; outline: none; cursor: pointer;
                   border-radius: 8px; height: 40px; }
  @media (min-width: 768px) { .filter-select { border-radius: 0; } }
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

  <?php require __DIR__ . '/_scanner-styles.php'; ?>
</style>
</head>
<body class="has-bottom-tabs">

<?php
$__adminNav = 'tickets';
$__exportEventId = $selected_event ?: null;
require __DIR__ . '/_topbar.php';
?>

<!-- Mobile event switcher strip -->
<div class="mobile-events-strip" role="navigation" aria-label="Eventos">
  <?php foreach ($events as $ev): ?>
    <a href="?event_id=<?= $ev['id'] ?>"
       class="mes-pill <?= (int)$ev['id'] === $selected_event ? 'active' : '' ?>">
      <span class="mes-pill-title"><?= htmlspecialchars($ev['title']) ?></span>
      <span class="mes-pill-date"><?= date('d/m/Y', strtotime($ev['date'])) ?></span>
      <span class="mes-pill-count"><?= (int)$ev['checked_in'] ?>/<?= (int)$ev['total'] ?></span>
    </a>
  <?php endforeach; ?>
  <?php if (empty($events)): ?>
    <span style="font-size:.78rem;color:rgba(245,239,230,.3);padding:.5rem 0;">Sem eventos ainda.</span>
  <?php endif; ?>
</div>

<!-- LAYOUT -->
<div class="layout">

  <!-- SIDEBAR: events list (tablet/desktop) -->
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


<?php require __DIR__ . '/_scanner-modal.php'; ?>

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
</script>

<?php require __DIR__ . '/_scanner-script.php'; ?>

</body>
</html>
