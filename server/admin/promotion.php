<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/campaign-lib.php';
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

$flash = '';
$flashKind = 'ok';
$editId = (int) ($_REQUEST['edit_id'] ?? 0);

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
