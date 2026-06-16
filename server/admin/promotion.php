<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/campaign-lib.php';
require_once __DIR__ . '/promo-places-lib.php';
require_once __DIR__ . '/../api/whatsapp.php';
require_admin_session();

function promo_h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

const EDV_PROMO_PHASES = [
    1 => 'Fase 1 — Informar (data + local)',
    2 => 'Fase 2 — Facilitadores + imagem',
    3 => 'Fase 3 — Promoção intensa',
    4 => 'Fase 4 — Semana do evento + semana zero',
];

$pdo = db();
edv_campaign_ensure_schema($pdo);
edv_campaign_seed_from_meeting($pdo);
edv_campaign_seed_promo_schedule($pdo);
edv_campaign_prune_cut_promo_posts($pdo);
edv_promo_places_ensure_schema($pdo);
edv_promo_places_seed($pdo);

// Eventos para o seletor de edição (locais de promoção marcados por edição).
$promoEvents = $pdo->query(
    'SELECT id, title, date, is_active FROM events ORDER BY date DESC LIMIT 30'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$flash = '';
$flashKind = 'ok';
$editId = (int) ($_REQUEST['edit_id'] ?? 0);

// Edição selecionada: ?place_event=ID, senão o próximo evento ativo, senão o mais recente.
$selectedPlaceEvent = (int) ($_REQUEST['place_event'] ?? 0);
if ($selectedPlaceEvent <= 0) {
    $today = date('Y-m-d');
    foreach ($promoEvents as $ev) {
        if ((int) $ev['is_active'] === 1 && (string) $ev['date'] >= $today) {
            $selectedPlaceEvent = (int) $ev['id'];
            break;
        }
    }
    if ($selectedPlaceEvent <= 0 && $promoEvents !== []) {
        $selectedPlaceEvent = (int) $promoEvents[0]['id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create_post') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $postDate = trim((string) ($_POST['post_date'] ?? ''));
        $phaseRaw = trim((string) ($_POST['phase'] ?? ''));
        if ($title === '' || $postDate === '') {
            $flash = 'O post precisa de título e data.';
            $flashKind = 'bad';
        } else {
            edv_campaign_create($pdo, [
                'area' => 'promocao',
                'title' => $title,
                'owner' => ($o = trim((string) ($_POST['owner'] ?? ''))) !== '' ? $o : null,
                'status' => 'todo',
                'due_date' => null,
                'post_date' => $postDate,
                'phase' => $phaseRaw !== '' ? (int) $phaseRaw : null,
                'channel' => ($c = trim((string) ($_POST['channel'] ?? ''))) !== '' ? $c : null,
                'details' => ($d = trim((string) ($_POST['details'] ?? ''))) !== '' ? $d : null,
            ]);
            $flash = 'Post adicionado ao calendário.';
        }
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
            $flash = 'Post apagado.';
        }
    } elseif ($action === 'send_wa') {
        $id = (int) ($_POST['id'] ?? 0);
        $post = $id > 0 ? edv_campaign_get($pdo, $id) : null;
        if ($post) {
            $phase = $post['phase'] !== null ? (int) $post['phase'] : 0;
            $msg = '📣 *Promo* ' . (isset(EDV_PROMO_PHASES[$phase]) ? '(' . EDV_PROMO_PHASES[$phase] . ')' : '') . "\n"
                . (string) $post['title']
                . (!empty($post['post_date']) ? "\n📅 " . (string) $post['post_date'] : '')
                . (!empty($post['channel']) ? "\n📍 " . (string) $post['channel'] : '')
                . (!empty($post['details']) ? "\n\n" . (string) $post['details'] : '');
            $res = edv_waha_send_text($msg);
            if ($res['ok']) {
                edv_campaign_mark_wa_sent($pdo, $id);
                $flash = 'Post enviado para o grupo WhatsApp.';
            } else {
                $flash = 'WhatsApp: ' . ($res['error'] ?? 'falhou');
                $flashKind = 'bad';
            }
        }
    } elseif ($action === 'place_create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $type = trim((string) ($_POST['type'] ?? 'outro'));
        if ($name === '') {
            $flash = 'O local precisa de nome.';
            $flashKind = 'bad';
        } else {
            edv_promo_place_create($pdo, $name, $type, trim((string) ($_POST['url'] ?? '')), trim((string) ($_POST['notes'] ?? '')));
            $flash = 'Local adicionado à lista.';
        }
    } elseif ($action === 'place_update') {
        $id = (int) ($_POST['place_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($id > 0 && $name !== '') {
            edv_promo_place_update($pdo, $id, $name, trim((string) ($_POST['type'] ?? 'outro')), trim((string) ($_POST['url'] ?? '')), trim((string) ($_POST['notes'] ?? '')));
            $flash = 'Local atualizado.';
        } else {
            $flash = 'Nome do local em falta.';
            $flashKind = 'bad';
        }
    } elseif ($action === 'place_toggle_active') {
        $id = (int) ($_POST['place_id'] ?? 0);
        if ($id > 0) {
            edv_promo_place_toggle_active($pdo, $id);
            $flash = 'Estado do local alterado.';
        }
    } elseif ($action === 'place_delete') {
        $id = (int) ($_POST['place_id'] ?? 0);
        if ($id > 0) {
            edv_promo_place_delete($pdo, $id);
            $flash = 'Local removido da lista.';
        }
    } elseif ($action === 'place_toggle_posted') {
        $placeId = (int) ($_POST['place_id'] ?? 0);
        $eventId = (int) ($_POST['event_id'] ?? 0);
        if ($placeId > 0 && $eventId > 0) {
            $nowPosted = edv_promo_place_toggle_posted($pdo, $placeId, $eventId);
            $flash = $nowPosted ? 'Marcado como publicado nesta edição.' : 'Marca de publicação removida.';
            $selectedPlaceEvent = $eventId;
        }
    }
}

$rows = $pdo->query(
    'SELECT * FROM campaign_tasks WHERE post_date IS NOT NULL ORDER BY post_date ASC, phase ASC, id ASC'
)->fetchAll();

$byPhase = [1 => [], 2 => [], 3 => [], 4 => [], 0 => []];
foreach ($rows as $r) {
    $p = $r['phase'] !== null ? (int) $r['phase'] : 0;
    if (!isset($byPhase[$p])) {
        $p = 0;
    }
    $byPhase[$p][] = $r;
}
$waReady = edv_waha_enabled();

// Locais de promoção + estado de publicação na edição selecionada.
$promoPlaces = edv_promo_places_all($pdo);
$placePostsForEvent = $selectedPlaceEvent > 0 ? edv_promo_place_posts_for_event($pdo, $selectedPlaceEvent) : [];
$selectedPlaceEventRow = null;
foreach ($promoEvents as $ev) {
    if ((int) $ev['id'] === $selectedPlaceEvent) {
        $selectedPlaceEventRow = $ev;
        break;
    }
}
$activePlacesCount = 0;
$postedPlacesCount = 0;
foreach ($promoPlaces as $pl) {
    if ((int) $pl['is_active'] === 1) {
        $activePlacesCount++;
        if (isset($placePostsForEvent[(int) $pl['id']])) {
            $postedPlacesCount++;
        }
    }
}

[$calYear, $calMonth] = edv_campaign_calendar_ym((string) ($_GET['ym'] ?? ''));
$calByDate = [];
foreach ($rows as $r) {
    $d = (string) ($r['post_date'] ?? '');
    if ($d === '') {
        continue;
    }
    $calByDate[substr($d, 0, 10)][] = ['label' => (string) $r['title'], 'cls' => (string) $r['status']];
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex,nofollow" />
  <title>Promoção — Admin</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root { --dark:#0E0B09; --dark-m:#1A1210; --dark-l:#2A1E1A; --bone:#F5EFE6; --gold:#D4A85A; --ok:#2d6a4f; --bad:#c45d4a; }
    body { background: var(--dark); color: var(--bone); font-family: Arial, sans-serif; font-size: 14px; }
    <?php require __DIR__ . '/_topbar-styles.php'; ?>
    .main { max-width: 1000px; margin: 0 auto; padding: 1.2rem 1rem 2.5rem; }
    .head { margin-bottom: 1rem; }
    .head h1 { font-weight: 300; font-size: 1.5rem; }
    .head p { color: rgba(245,239,230,.55); font-size: .82rem; margin-top: .25rem; }
    .flash { background: rgba(45,106,79,.18); border: 1px solid rgba(45,106,79,.36); padding: .65rem .85rem; margin-bottom: .9rem; border-radius: 8px; font-size: .82rem; }
    .flash.bad { background: rgba(196,93,74,.16); border-color: rgba(196,93,74,.4); }
    .notice { font-size: .78rem; color: rgba(245,239,230,.5); margin-bottom: 1rem; line-height: 1.5; }
    .panel { background: var(--dark-m); border: 1px solid rgba(245,239,230,.08); border-radius: 10px; padding: .95rem; margin-bottom: 1rem; }
    .panel h2 { font-size: .68rem; letter-spacing: .14em; text-transform: uppercase; color: rgba(245,239,230,.5); margin-bottom: .8rem; }
    .form-grid { display: grid; grid-template-columns: 1fr; gap: .55rem; }
    @media (min-width: 720px) { .form-grid { grid-template-columns: 1.4fr .7fr .6fr .8fr; } }
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
    .phase { border-left: 2px solid rgba(212,168,90,.4); padding-left: .85rem; margin-bottom: 1.4rem; }
    .phase > h3 { font-size: .82rem; color: var(--gold); margin-bottom: .65rem; font-weight: 500; }
    .post { border: 1px solid rgba(245,239,230,.08); border-radius: 9px; padding: .7rem .8rem; margin-bottom: .6rem; background: rgba(0,0,0,.14); }
    .post.is-done { opacity: .55; }
    .post .p-date { font-size: .7rem; color: var(--gold); letter-spacing: .04em; }
    .post .p-title { font-size: .92rem; font-weight: 500; margin-top: .15rem; }
    .post .p-meta { font-size: .72rem; color: rgba(245,239,230,.5); margin-top: .2rem; }
    .post .p-details { font-size: .78rem; color: rgba(245,239,230,.72); margin-top: .35rem; line-height: 1.45; white-space: pre-wrap; }
    .post .p-actions { display: flex; flex-wrap: wrap; gap: .35rem; align-items: center; margin-top: .55rem; }
    .pill { font-size: .6rem; letter-spacing: .08em; text-transform: uppercase; padding: .15rem .45rem; border-radius: 99px; border: 1px solid rgba(245,239,230,.18); }
    .pill.todo { color: rgba(245,239,230,.6); } .pill.doing { color: var(--gold); border-color: rgba(212,168,90,.4); } .pill.done { color:#6be39a; border-color: rgba(37,211,102,.35); }
    .inline-form { display: inline; } .sent { color:#6be39a; font-size:.68rem; }
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
    /* Locais de promoção */
    .pl-bar { display:flex; flex-wrap:wrap; gap:.6rem; align-items:center; margin-bottom:.8rem; }
    .pl-bar select { width:auto; min-width:14rem; }
    .pl-progress { font-size:.78rem; color:rgba(245,239,230,.6); }
    .pl-progress strong { color:var(--gold); }
    .place { display:flex; flex-wrap:wrap; align-items:flex-start; gap:.6rem; border:1px solid rgba(245,239,230,.08); border-radius:9px; padding:.6rem .7rem; margin-bottom:.5rem; background:rgba(0,0,0,.14); }
    .place.is-posted { border-color:rgba(37,211,102,.32); background:rgba(37,211,102,.05); }
    .place.is-off { opacity:.5; }
    .place .pl-main { flex:1 1 240px; min-width:0; }
    .place .pl-name { font-size:.9rem; font-weight:500; }
    .place .pl-name a { color:var(--gold); text-decoration:none; }
    .place .pl-type { display:inline-block; font-size:.58rem; letter-spacing:.06em; text-transform:uppercase; padding:.1rem .4rem; border-radius:99px; border:1px solid rgba(245,239,230,.18); color:rgba(245,239,230,.6); margin-left:.4rem; vertical-align:middle; }
    .place .pl-notes { font-size:.74rem; color:rgba(245,239,230,.55); margin-top:.25rem; line-height:1.4; }
    .place .pl-side { display:flex; flex-direction:column; align-items:flex-end; gap:.35rem; }
    .place .pl-when { font-size:.66rem; color:#6be39a; }
    .btn-done { border-color:rgba(37,211,102,.45); color:#6be39a; background:rgba(37,211,102,.1); }
    details.pl-edit { margin-top:.4rem; }
    details.pl-edit summary { cursor:pointer; font-size:.66rem; color:rgba(245,239,230,.5); }
    details.pl-edit .form-grid { margin-top:.5rem; }
    .pl-actions { display:flex; gap:.35rem; flex-wrap:wrap; margin-top:.4rem; }
  </style>
</head>
<body class="has-bottom-tabs">
<?php
$__adminNav = 'promotion';
require __DIR__ . '/_topbar.php';
?>
<main class="main">
  <div class="head">
    <h1>Promoção — calendário</h1>
    <p>Plano de comunicação em 4 fases (reunião 27 jun). Marca posts como feitos e envia o conteúdo para o grupo WhatsApp da equipa.</p>
  </div>

  <?php if ($flash !== ''): ?>
    <div class="flash <?= $flashKind === 'bad' ? 'bad' : '' ?>"><?= promo_h($flash) ?></div>
  <?php endif; ?>

  <?php if (!$waReady): ?>
    <div class="notice">⚠️ Envio WhatsApp desligado — falta a env <code>EDV_WAHA_API_KEY</code> no edv-server (Coolify).</div>
  <?php endif; ?>

  <?= edv_campaign_month_calendar($calByDate, $calYear, $calMonth) ?>

  <div class="panel">
    <h2>Locais de promoção — por edição</h2>
    <p class="notice" style="margin-bottom:.7rem;">
      Lista fixa de sítios onde divulgamos (agendas, grupos FB/WhatsApp/Telegram, Instagram, cartazes…).
      Escolhe a edição e marca onde já publicaste. A lista mantém-se entre edições.
    </p>
    <form method="get" class="pl-bar">
      <div>
        <label class="lbl">Edição</label>
        <select name="place_event" onchange="this.form.submit()">
          <?php if ($promoEvents === []): ?>
            <option value="0">— sem eventos —</option>
          <?php endif; ?>
          <?php foreach ($promoEvents as $ev): ?>
            <option value="<?= (int) $ev['id'] ?>" <?= (int) $ev['id'] === $selectedPlaceEvent ? 'selected' : '' ?>>
              <?= promo_h((string) $ev['title']) ?> · <?= promo_h(date('d/m/Y', strtotime((string) $ev['date']))) ?><?= (int) $ev['is_active'] === 1 ? ' ★' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($selectedPlaceEventRow !== null): ?>
        <div class="pl-progress" style="align-self:flex-end;">
          <strong><?= $postedPlacesCount ?></strong> / <?= $activePlacesCount ?> locais ativos marcados nesta edição
        </div>
      <?php endif; ?>
    </form>

    <?php if ($promoPlaces === []): ?>
      <p class="notice">Ainda não há locais. Adiciona o primeiro abaixo.</p>
    <?php endif; ?>

    <?php foreach ($promoPlaces as $pl): ?>
      <?php
        $plId = (int) $pl['id'];
        $isActive = (int) $pl['is_active'] === 1;
        $postedAt = $placePostsForEvent[$plId] ?? null;
        $isPosted = $postedAt !== null;
      ?>
      <div class="place <?= $isPosted ? 'is-posted' : '' ?> <?= $isActive ? '' : 'is-off' ?>">
        <div class="pl-main">
          <div class="pl-name">
            <?php if (!empty($pl['url'])): ?>
              <a href="<?= promo_h((string) $pl['url']) ?>" target="_blank" rel="noopener"><?= promo_h((string) $pl['name']) ?></a>
            <?php else: ?>
              <?= promo_h((string) $pl['name']) ?>
            <?php endif; ?>
            <span class="pl-type"><?= promo_h(edv_promo_place_type_label((string) $pl['type'])) ?></span>
            <?php if (!$isActive): ?><span class="pl-type">inativo</span><?php endif; ?>
          </div>
          <?php if (!empty($pl['notes'])): ?><div class="pl-notes"><?= promo_h((string) $pl['notes']) ?></div><?php endif; ?>
          <div class="pl-actions">
            <form method="post" class="inline-form">
              <input type="hidden" name="action" value="place_toggle_active" />
              <input type="hidden" name="place_id" value="<?= $plId ?>" />
              <input type="hidden" name="place_event" value="<?= $selectedPlaceEvent ?>" />
              <button class="btn" type="submit"><?= $isActive ? 'Desativar' : 'Reativar' ?></button>
            </form>
            <form method="post" class="inline-form" onsubmit="return confirm('Remover este local da lista (e o histórico em todas as edições)?');">
              <input type="hidden" name="action" value="place_delete" />
              <input type="hidden" name="place_id" value="<?= $plId ?>" />
              <input type="hidden" name="place_event" value="<?= $selectedPlaceEvent ?>" />
              <button class="btn btn-danger" type="submit">Remover</button>
            </form>
          </div>
          <details class="pl-edit">
            <summary>Editar local</summary>
            <form method="post">
              <input type="hidden" name="action" value="place_update" />
              <input type="hidden" name="place_id" value="<?= $plId ?>" />
              <input type="hidden" name="place_event" value="<?= $selectedPlaceEvent ?>" />
              <div class="form-grid">
                <div><label class="lbl">Nome</label><input name="name" value="<?= promo_h((string) $pl['name']) ?>" required /></div>
                <div><label class="lbl">Tipo</label>
                  <select name="type">
                    <?php foreach (EDV_PROMO_PLACE_TYPES as $tk => $tv): ?>
                      <option value="<?= $tk ?>" <?= (string) $pl['type'] === $tk ? 'selected' : '' ?>><?= promo_h($tv) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div><label class="lbl">Link</label><input name="url" value="<?= promo_h((string) ($pl['url'] ?? '')) ?>" placeholder="https://" /></div>
                <div style="align-self:end;"><button class="btn btn-gold" type="submit">Guardar</button></div>
              </div>
              <div style="margin-top:.5rem;"><label class="lbl">Notas</label><input name="notes" value="<?= promo_h((string) ($pl['notes'] ?? '')) ?>" /></div>
            </form>
          </details>
        </div>
        <?php if ($selectedPlaceEvent > 0): ?>
          <div class="pl-side">
            <form method="post" class="inline-form">
              <input type="hidden" name="action" value="place_toggle_posted" />
              <input type="hidden" name="place_id" value="<?= $plId ?>" />
              <input type="hidden" name="event_id" value="<?= $selectedPlaceEvent ?>" />
              <input type="hidden" name="place_event" value="<?= $selectedPlaceEvent ?>" />
              <button class="btn <?= $isPosted ? 'btn-done' : '' ?>" type="submit">
                <?= $isPosted ? '✓ Publicado' : 'Marcar publicado' ?>
              </button>
            </form>
            <?php if ($isPosted): ?>
              <span class="pl-when"><?= promo_h(date('d/m/Y', strtotime((string) $postedAt))) ?></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <details style="margin-top:.8rem;">
      <summary style="cursor:pointer;font-size:.72rem;color:var(--gold);">+ Adicionar local de promoção</summary>
      <form method="post" style="margin-top:.7rem;">
        <input type="hidden" name="action" value="place_create" />
        <input type="hidden" name="place_event" value="<?= $selectedPlaceEvent ?>" />
        <div class="form-grid">
          <div><label class="lbl">Nome</label><input name="name" required placeholder="Grupo FB Viseu Eventos" /></div>
          <div><label class="lbl">Tipo</label>
            <select name="type">
              <?php foreach (EDV_PROMO_PLACE_TYPES as $tk => $tv): ?>
                <option value="<?= $tk ?>"><?= promo_h($tv) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div><label class="lbl">Link (opcional)</label><input name="url" placeholder="https://" /></div>
          <div style="align-self:end;"><button class="btn btn-gold" type="submit">Adicionar</button></div>
        </div>
        <div style="margin-top:.5rem;"><label class="lbl">Notas (opcional)</label><input name="notes" placeholder="Como/quando submeter, contacto…" /></div>
      </form>
    </details>
  </div>

  <div class="panel">
    <h2>Novo post</h2>
    <form method="post">
      <input type="hidden" name="action" value="create_post" />
      <div class="form-grid">
        <div><label class="lbl">Título</label><input name="title" required /></div>
        <div><label class="lbl">Data</label><input type="date" name="post_date" required /></div>
        <div><label class="lbl">Fase</label>
          <select name="phase">
            <?php foreach (EDV_PROMO_PHASES as $pk => $pv): ?>
              <option value="<?= $pk ?>"><?= $pk ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label class="lbl">Canal</label><input name="channel" placeholder="Instagram / Viral Agenda" /></div>
      </div>
      <div style="margin-top:.55rem;"><label class="lbl">Conteúdo / notas</label><textarea name="details"></textarea></div>
      <div style="margin-top:.6rem;"><button class="btn btn-gold" type="submit">Adicionar post</button></div>
    </form>
  </div>

  <?php foreach ([1, 2, 3, 4, 0] as $p): ?>
    <?php if (empty($byPhase[$p])) { continue; } ?>
    <div class="phase">
      <h3><?= promo_h($p === 0 ? 'Sem fase' : (EDV_PROMO_PHASES[$p] ?? ('Fase ' . $p))) ?></h3>
      <?php foreach ($byPhase[$p] as $post): ?>
        <?php $st = (string) $post['status']; ?>
        <div class="post <?= $st === 'done' ? 'is-done' : '' ?>">
          <div class="p-date">📅 <?= promo_h((string) $post['post_date']) ?><?php if (!empty($post['channel'])): ?> · 📍 <?= promo_h((string) $post['channel']) ?><?php endif; ?></div>
          <div class="p-title"><?= promo_h((string) $post['title']) ?></div>
          <div class="p-meta">
            <span class="pill <?= $st ?>"><?= promo_h(edv_campaign_status_label($st)) ?></span>
            <?php if (!empty($post['owner'])): ?> · 👤 <?= promo_h((string) $post['owner']) ?><?php endif; ?>
            <?php if (!empty($post['wa_sent_at'])): ?> · <span class="sent">✓ WhatsApp</span><?php endif; ?>
          </div>
          <?php if (!empty($post['details'])): ?><div class="p-details"><?= promo_h((string) $post['details']) ?></div><?php endif; ?>
          <div class="p-actions">
            <?php foreach (EDV_CAMPAIGN_STATUSES as $sk => $sv): ?>
              <?php if ($sk !== $st): ?>
                <form method="post" class="inline-form">
                  <input type="hidden" name="action" value="set_status" />
                  <input type="hidden" name="id" value="<?= (int) $post['id'] ?>" />
                  <input type="hidden" name="status" value="<?= $sk ?>" />
                  <button class="btn" type="submit">→ <?= promo_h($sv) ?></button>
                </form>
              <?php endif; ?>
            <?php endforeach; ?>
            <a class="btn" href="/admin/tasks.php?edit_id=<?= (int) $post['id'] ?>">Editar</a>
            <?php if ($waReady): ?>
              <form method="post" class="inline-form">
                <input type="hidden" name="action" value="send_wa" />
                <input type="hidden" name="id" value="<?= (int) $post['id'] ?>" />
                <button class="btn btn-wa" type="submit">WhatsApp</button>
              </form>
            <?php endif; ?>
            <form method="post" class="inline-form" onsubmit="return confirm('Apagar este post?');">
              <input type="hidden" name="action" value="delete" />
              <input type="hidden" name="id" value="<?= (int) $post['id'] ?>" />
              <button class="btn btn-danger" type="submit">Apagar</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</main>
</body>
</html>
