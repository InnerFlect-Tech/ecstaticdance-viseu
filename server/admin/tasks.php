<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/campaign-lib.php';
require_once __DIR__ . '/../api/whatsapp.php';
require_admin_session();

function tasks_h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$pdo = db();
edv_campaign_ensure_schema($pdo);
edv_campaign_seed_from_meeting($pdo);

$flash = '';
$flashKind = 'ok';
$editId = (int) ($_REQUEST['edit_id'] ?? 0);

function tasks_collect(): array
{
    $area = (string) ($_POST['area'] ?? 'producao');
    if (!isset(EDV_CAMPAIGN_AREAS[$area])) {
        $area = 'producao';
    }
    $status = (string) ($_POST['status'] ?? 'todo');
    if (!isset(EDV_CAMPAIGN_STATUSES[$status])) {
        $status = 'todo';
    }
    $phaseRaw = trim((string) ($_POST['phase'] ?? ''));
    $dueRaw = trim((string) ($_POST['due_date'] ?? ''));
    $postRaw = trim((string) ($_POST['post_date'] ?? ''));
    return [
        'area' => $area,
        'title' => trim((string) ($_POST['title'] ?? '')),
        'owner' => ($o = trim((string) ($_POST['owner'] ?? ''))) !== '' ? $o : null,
        'status' => $status,
        'due_date' => $dueRaw !== '' ? $dueRaw : null,
        'post_date' => $postRaw !== '' ? $postRaw : null,
        'phase' => $phaseRaw !== '' ? (int) $phaseRaw : null,
        'channel' => ($c = trim((string) ($_POST['channel'] ?? ''))) !== '' ? $c : null,
        'details' => ($d = trim((string) ($_POST['details'] ?? ''))) !== '' ? $d : null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $d = tasks_collect();
        if ($d['title'] === '') {
            $flash = 'A tarefa precisa de um título.';
            $flashKind = 'bad';
        } else {
            edv_campaign_create($pdo, $d);
            $flash = 'Tarefa adicionada.';
        }
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $d = tasks_collect();
        if ($id > 0 && $d['title'] !== '') {
            edv_campaign_update($pdo, $id, $d);
            $flash = 'Tarefa atualizada.';
        }
        $editId = 0;
    } elseif ($action === 'set_status') {
        $id = (int) ($_POST['id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'todo');
        if ($id > 0 && isset(EDV_CAMPAIGN_STATUSES[$status])) {
            edv_campaign_set_status($pdo, $id, $status);
            $flash = 'Estado atualizado.';
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            edv_campaign_delete($pdo, $id);
            $flash = 'Tarefa apagada.';
        }
    } elseif ($action === 'send_wa') {
        $id = (int) ($_POST['id'] ?? 0);
        $task = $id > 0 ? edv_campaign_get($pdo, $id) : null;
        if ($task) {
            $msg = '✅ *Tarefa* — ' . edv_campaign_area_label((string) $task['area']) . "\n"
                . (string) $task['title']
                . (!empty($task['owner']) ? "\n👤 " . (string) $task['owner'] : '')
                . (!empty($task['due_date']) ? "\n📅 " . (string) $task['due_date'] : '')
                . (!empty($task['details']) ? "\n\n" . (string) $task['details'] : '');
            $res = edv_waha_send_text($msg);
            if ($res['ok']) {
                edv_campaign_mark_wa_sent($pdo, $id);
                $flash = 'Enviado para o grupo WhatsApp.';
            } else {
                $flash = 'WhatsApp: ' . ($res['error'] ?? 'falhou');
                $flashKind = 'bad';
            }
        }
    }
}

$tasks = edv_campaign_all($pdo);
$byArea = [];
foreach (array_keys(EDV_CAMPAIGN_AREAS) as $a) {
    $byArea[$a] = [];
}
foreach ($tasks as $t) {
    $a = (string) $t['area'];
    $byArea[$a][] = $t;
    if (!isset($byArea[$a])) {
        $byArea[$a] = [$t];
    }
}
$editTask = $editId > 0 ? edv_campaign_get($pdo, $editId) : null;
$waReady = edv_waha_enabled();

$counts = ['todo' => 0, 'doing' => 0, 'done' => 0];
foreach ($tasks as $t) {
    $s = (string) $t['status'];
    if (isset($counts[$s])) {
        $counts[$s]++;
    }
}

[$calYear, $calMonth] = edv_campaign_calendar_ym((string) ($_GET['ym'] ?? ''));
$calByDate = [];
foreach ($tasks as $t) {
    $d = (string) ($t['due_date'] ?? '');
    if ($d === '') {
        $d = (string) ($t['post_date'] ?? '');
    }
    if ($d === '') {
        continue;
    }
    $calByDate[substr($d, 0, 10)][] = ['label' => (string) $t['title'], 'cls' => (string) $t['status']];
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex,nofollow" />
  <title>Tarefas — Admin</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root { --dark:#0E0B09; --dark-m:#1A1210; --dark-l:#2A1E1A; --bone:#F5EFE6; --gold:#D4A85A; --ok:#2d6a4f; --bad:#c45d4a; }
    body { background: var(--dark); color: var(--bone); font-family: Arial, sans-serif; font-size: 14px; }
    <?php require __DIR__ . '/_topbar-styles.php'; ?>
    .main { max-width: 1180px; margin: 0 auto; padding: 1.2rem 1rem 2.5rem; }
    .head { margin-bottom: 1rem; }
    .head h1 { font-weight: 300; font-size: 1.5rem; }
    .head p { color: rgba(245,239,230,.55); font-size: .82rem; margin-top: .25rem; }
    .flash { background: rgba(45,106,79,.18); border: 1px solid rgba(45,106,79,.36); padding: .65rem .85rem; margin-bottom: .9rem; border-radius: 8px; font-size: .82rem; }
    .flash.bad { background: rgba(196,93,74,.16); border-color: rgba(196,93,74,.4); }
    .notice { font-size: .78rem; color: rgba(245,239,230,.5); margin-bottom: 1rem; line-height: 1.5; }
    .panel { background: var(--dark-m); border: 1px solid rgba(245,239,230,.08); border-radius: 10px; padding: .95rem; margin-bottom: 1rem; }
    .panel h2 { font-size: .68rem; letter-spacing: .14em; text-transform: uppercase; color: rgba(245,239,230,.5); margin-bottom: .8rem; }
    .area-grid { display: grid; grid-template-columns: 1fr; gap: 1rem; }
    @media (min-width: 880px) { .area-grid { grid-template-columns: 1fr 1fr; } }
    .form-grid { display: grid; grid-template-columns: 1fr; gap: .55rem; }
    @media (min-width: 720px) { .form-grid { grid-template-columns: 1.4fr .7fr .7fr .7fr; } }
    label.lbl { font-size: .62rem; letter-spacing: .1em; text-transform: uppercase; color: rgba(245,239,230,.4); display:block; margin-bottom:.2rem; }
    input, select, textarea { width: 100%; background: rgba(245,239,230,.06); border: 1px solid rgba(245,239,230,.16); color: var(--bone);
      padding: .45rem .55rem; font-size: .82rem; border-radius: 8px; font-family: inherit; }
    textarea { min-height: 38px; resize: vertical; }
    .btn { border: 1px solid rgba(245,239,230,.18); background: rgba(245,239,230,.06); color: var(--bone); cursor: pointer;
      padding: .38rem .6rem; border-radius: 8px; font-size: .68rem; text-transform: uppercase; letter-spacing: .06em; }
    .btn:hover { border-color: rgba(245,239,230,.35); }
    .btn-gold { border-color: rgba(212,168,90,.5); color: var(--gold); background: rgba(212,168,90,.1); }
    .btn-danger { border-color: rgba(196,93,74,.45); color: #f2c7be; background: rgba(196,93,74,.1); }
    .btn-wa { border-color: rgba(37,211,102,.45); color: #6be39a; background: rgba(37,211,102,.08); }
    .task { border: 1px solid rgba(245,239,230,.08); border-radius: 9px; padding: .7rem .8rem; margin-bottom: .6rem; background: rgba(0,0,0,.14); }
    .task.is-done { opacity: .55; }
    .task .t-title { font-size: .9rem; font-weight: 500; }
    .task .t-meta { font-size: .72rem; color: rgba(245,239,230,.5); margin-top: .2rem; }
    .task .t-details { font-size: .78rem; color: rgba(245,239,230,.7); margin-top: .35rem; line-height: 1.45; white-space: pre-wrap; }
    .task .t-actions { display: flex; flex-wrap: wrap; gap: .35rem; align-items: center; margin-top: .55rem; }
    .pill { font-size: .6rem; letter-spacing: .08em; text-transform: uppercase; padding: .15rem .45rem; border-radius: 99px; border: 1px solid rgba(245,239,230,.18); }
    .pill.todo { color: rgba(245,239,230,.6); }
    .pill.doing { color: var(--gold); border-color: rgba(212,168,90,.4); }
    .pill.done { color: #6be39a; border-color: rgba(37,211,102,.35); }
    .inline-form { display: inline; }
    .cal { background: var(--dark-m); border:1px solid rgba(245,239,230,.08); border-radius:10px; padding:.9rem; margin-bottom:1.2rem; }
    .cal-head { display:flex; align-items:center; justify-content:space-between; font-size:.92rem; color:var(--gold); margin-bottom:.7rem; }
    .cal-nav { color:rgba(245,239,230,.6); text-decoration:none; padding:.1rem .55rem; border:1px solid rgba(245,239,230,.14); border-radius:6px; }
    .cal-nav:hover { color:var(--bone); }
    .cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
    .cal-dow { font-size:.58rem; text-transform:uppercase; letter-spacing:.08em; color:rgba(245,239,230,.4); text-align:center; padding:.2rem 0; }
    .cal-cell { min-height:66px; border:1px solid rgba(245,239,230,.06); border-radius:7px; padding:.25rem; background:rgba(0,0,0,.14); overflow:hidden; }
    .cal-cell.cal-empty { background:transparent; border-color:transparent; }
    .cal-cell.is-today { border-color:rgba(212,168,90,.55); }
    .cal-day { font-size:.66rem; color:rgba(245,239,230,.45); margin-bottom:.18rem; }
    .cal-chip { font-size:.56rem; line-height:1.25; padding:.1rem .28rem; border-radius:4px; margin-bottom:2px; background:rgba(245,239,230,.1); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .cal-chip.doing { background:rgba(212,168,90,.2); color:var(--gold); }
    .cal-chip.done { opacity:.45; }
    @media (max-width:700px){ .cal-cell{ min-height:46px; } .cal-day{ font-size:.56rem; } .cal-chip{ font-size:.48rem; } }
    .stat-row { display: flex; gap: .6rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .stat { background: var(--dark-l); border: 1px solid rgba(245,239,230,.08); border-radius: 9px; padding: .55rem .8rem; font-size: .8rem; }
    .stat strong { font-size: 1.05rem; font-weight: 500; }
    .sent { color:#6be39a; font-size:.68rem; }
  </style>
</head>
<body class="has-bottom-tabs">
<?php
$__adminNav = 'tasks';
require __DIR__ . '/_topbar.php';
?>
<main class="main">
  <div class="head">
    <h1>Tarefas da campanha</h1>
    <p>Logística · Parcerias · Promoção · Produção — tarefas da edição #02 (27 jun). Envia qualquer tarefa para o grupo WhatsApp da equipa.</p>
  </div>

  <?php if ($flash !== ''): ?>
    <div class="flash <?= $flashKind === 'bad' ? 'bad' : '' ?>"><?= tasks_h($flash) ?></div>
  <?php endif; ?>

  <?php if (!$waReady): ?>
    <div class="notice">⚠️ Envio WhatsApp desligado — falta a env <code>EDV_WAHA_API_KEY</code> no edv-server (Coolify). O resto funciona; o watchdog n8n já envia o digest diário ao grupo.</div>
  <?php endif; ?>

  <div class="stat-row">
    <div class="stat"><strong><?= $counts['todo'] ?></strong> a fazer</div>
    <div class="stat"><strong><?= $counts['doing'] ?></strong> em curso</div>
    <div class="stat"><strong><?= $counts['done'] ?></strong> feito</div>
  </div>

  <?= edv_campaign_month_calendar($calByDate, $calYear, $calMonth) ?>

  <div class="panel">
    <h2><?= $editTask ? 'Editar tarefa' : 'Nova tarefa' ?></h2>
    <form method="post">
      <input type="hidden" name="action" value="<?= $editTask ? 'update' : 'create' ?>" />
      <?php if ($editTask): ?><input type="hidden" name="id" value="<?= (int) $editTask['id'] ?>" /><?php endif; ?>
      <div class="form-grid">
        <div>
          <label class="lbl">Título</label>
          <input name="title" required value="<?= $editTask ? tasks_h((string) $editTask['title']) : '' ?>" />
        </div>
        <div>
          <label class="lbl">Área</label>
          <select name="area">
            <?php foreach (EDV_CAMPAIGN_AREAS as $k => $v): ?>
              <option value="<?= $k ?>" <?= $editTask && (string) $editTask['area'] === $k ? 'selected' : '' ?>><?= tasks_h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="lbl">Responsável</label>
          <input name="owner" value="<?= $editTask ? tasks_h((string) ($editTask['owner'] ?? '')) : '' ?>" placeholder="Daniel / Carolina" />
        </div>
        <div>
          <label class="lbl">Estado</label>
          <select name="status">
            <?php foreach (EDV_CAMPAIGN_STATUSES as $k => $v): ?>
              <option value="<?= $k ?>" <?= $editTask && (string) $editTask['status'] === $k ? 'selected' : '' ?>><?= tasks_h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="lbl">Prazo</label>
          <input type="date" name="due_date" value="<?= $editTask ? tasks_h((string) ($editTask['due_date'] ?? '')) : '' ?>" />
        </div>
        <div>
          <label class="lbl">Data de post (calendário)</label>
          <input type="date" name="post_date" value="<?= $editTask ? tasks_h((string) ($editTask['post_date'] ?? '')) : '' ?>" />
        </div>
        <div>
          <label class="lbl">Fase (1–4)</label>
          <input type="number" name="phase" min="1" max="4" value="<?= $editTask && $editTask['phase'] !== null ? (int) $editTask['phase'] : '' ?>" />
        </div>
        <div>
          <label class="lbl">Canal / plataforma</label>
          <input name="channel" value="<?= $editTask ? tasks_h((string) ($editTask['channel'] ?? '')) : '' ?>" />
        </div>
      </div>
      <div style="margin-top:.55rem;">
        <label class="lbl">Detalhes</label>
        <textarea name="details"><?= $editTask ? tasks_h((string) ($editTask['details'] ?? '')) : '' ?></textarea>
      </div>
      <div style="margin-top:.6rem; display:flex; gap:.4rem;">
        <button class="btn btn-gold" type="submit"><?= $editTask ? 'Guardar' : 'Adicionar' ?></button>
        <?php if ($editTask): ?><a class="btn" href="/admin/tasks.php">Cancelar</a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="area-grid">
    <?php foreach (EDV_CAMPAIGN_AREAS as $area => $areaLabel): ?>
      <div class="panel">
        <h2><?= tasks_h($areaLabel) ?> · <?= count($byArea[$area] ?? []) ?></h2>
        <?php if (empty($byArea[$area])): ?>
          <p class="t-meta">Sem tarefas.</p>
        <?php else: ?>
          <?php foreach ($byArea[$area] as $t): ?>
            <?php $st = (string) $t['status']; ?>
            <div class="task <?= $st === 'done' ? 'is-done' : '' ?>">
              <div class="t-title"><?= tasks_h((string) $t['title']) ?></div>
              <div class="t-meta">
                <span class="pill <?= $st ?>"><?= tasks_h(edv_campaign_status_label($st)) ?></span>
                <?php if (!empty($t['owner'])): ?> · 👤 <?= tasks_h((string) $t['owner']) ?><?php endif; ?>
                <?php if (!empty($t['due_date'])): ?> · 📅 <?= tasks_h((string) $t['due_date']) ?><?php endif; ?>
                <?php if (!empty($t['wa_sent_at'])): ?> · <span class="sent">✓ WhatsApp</span><?php endif; ?>
              </div>
              <?php if (!empty($t['details'])): ?>
                <div class="t-details"><?= tasks_h((string) $t['details']) ?></div>
              <?php endif; ?>
              <div class="t-actions">
                <?php foreach (EDV_CAMPAIGN_STATUSES as $sk => $sv): ?>
                  <?php if ($sk !== $st): ?>
                    <form method="post" class="inline-form">
                      <input type="hidden" name="action" value="set_status" />
                      <input type="hidden" name="id" value="<?= (int) $t['id'] ?>" />
                      <input type="hidden" name="status" value="<?= $sk ?>" />
                      <button class="btn" type="submit">→ <?= tasks_h($sv) ?></button>
                    </form>
                  <?php endif; ?>
                <?php endforeach; ?>
                <a class="btn" href="/admin/tasks.php?edit_id=<?= (int) $t['id'] ?>">Editar</a>
                <?php if ($waReady): ?>
                  <form method="post" class="inline-form">
                    <input type="hidden" name="action" value="send_wa" />
                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>" />
                    <button class="btn btn-wa" type="submit">WhatsApp</button>
                  </form>
                <?php endif; ?>
                <form method="post" class="inline-form" onsubmit="return confirm('Apagar esta tarefa?');">
                  <input type="hidden" name="action" value="delete" />
                  <input type="hidden" name="id" value="<?= (int) $t['id'] ?>" />
                  <button class="btn btn-danger" type="submit">Apagar</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</main>
</body>
</html>
