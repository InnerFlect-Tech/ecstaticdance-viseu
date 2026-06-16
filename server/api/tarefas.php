<?php
declare(strict_types=1);

/**
 * Public follow-up board for the ED Viseu campaign tasks (read-only).
 * Token-protected so the team can open it from the WhatsApp digest without
 * the admin login. Link form: /api/tarefas.php?t=<EDV_TASKS_TOKEN>
 *
 * Low-sensitivity by design (it shows task titles/owners, no secrets). Editing
 * still happens in /admin or via the WhatsApp bot (tasks-command.php).
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../admin/campaign-lib.php';

$expected = trim((string) getenv('EDV_TASKS_TOKEN'));
$token = (string) ($_GET['t'] ?? ($_GET['token'] ?? ''));

if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code($expected === '' ? 500 : 401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:system-ui;background:#1A1210;color:#F5EFE6;padding:48px;text-align:center">';
    echo $expected === ''
        ? '<h2>Indisponível</h2><p>EDV_TASKS_TOKEN não configurada.</p>'
        : '<h2>Sem acesso</h2><p>Link inválido ou expirado.</p>';
    echo '</body>';
    exit;
}

$pdo = db();
edv_campaign_ensure_schema($pdo);
edv_campaign_seed_from_meeting($pdo);
edv_campaign_seed_promo_schedule($pdo);
edv_campaign_prune_cut_promo_posts($pdo);

$rows = edv_campaign_all($pdo);
$today = db_today_string();

$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$byArea = [];
$openCount = 0;
foreach ($rows as $r) {
    $byArea[(string) $r['area']][] = $r;
    if (($r['status'] ?? '') !== 'done') {
        $openCount++;
    }
}

$statusMeta = [
    'todo'  => ['A fazer', '#8A7B6B'],
    'doing' => ['Em curso', '#B8924A'],
    'done'  => ['Feito', '#5E7D5A'],
];
?>
<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Tarefas — Ecstatic Dance Viseu</title>
<style>
  :root { --bg:#1A1210; --panel:#241A16; --line:rgba(245,239,230,.10); --txt:#F5EFE6; --muted:rgba(245,239,230,.45); --gold:#B8924A; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--txt); line-height:1.5; }
  .wrap { max-width:760px; margin:0 auto; padding:32px 20px 64px; }
  .eyebrow { font-size:11px; letter-spacing:3px; text-transform:uppercase; color:var(--gold); margin:0 0 6px; }
  h1 { font-size:26px; font-weight:300; margin:0 0 4px; }
  .meta { color:var(--muted); font-size:13px; margin:0 0 28px; }
  .area { margin:0 0 26px; }
  .area h2 { font-size:13px; letter-spacing:1.5px; text-transform:uppercase; color:var(--gold); border-bottom:1px solid var(--line); padding-bottom:8px; margin:0 0 12px; }
  .task { display:flex; gap:12px; align-items:flex-start; padding:11px 0; border-bottom:1px solid var(--line); }
  .task:last-child { border-bottom:0; }
  .task.is-done .title { color:var(--muted); text-decoration:line-through; }
  .badge { flex:0 0 auto; font-size:10px; letter-spacing:.5px; text-transform:uppercase; padding:3px 8px; border-radius:999px; color:#1A1210; font-weight:600; white-space:nowrap; }
  .body { flex:1 1 auto; min-width:0; }
  .title { font-size:15px; }
  .sub { font-size:12px; color:var(--muted); margin-top:3px; }
  .id { color:var(--muted); font-variant-numeric:tabular-nums; }
  .foot { color:var(--muted); font-size:12px; margin-top:32px; border-top:1px solid var(--line); padding-top:16px; }
  .foot code { color:var(--txt); }
</style>
</head>
<body>
<div class="wrap">
  <p class="eyebrow">Ecstatic Dance Viseu</p>
  <h1>Tarefas da campanha</h1>
  <p class="meta"><?= $openCount ?> abertas · <?= count($rows) ?> no total · <?= $h($today) ?></p>

  <?php foreach (EDV_CAMPAIGN_AREAS as $key => $label): ?>
    <?php $list = $byArea[$key] ?? []; if ($list === []) { continue; } ?>
    <section class="area">
      <h2><?= $h($label) ?></h2>
      <?php foreach ($list as $r): ?>
        <?php
          $st = (string) ($r['status'] ?? 'todo');
          [$stLabel, $stColor] = $statusMeta[$st] ?? [$st, '#8A7B6B'];
          $sub = [];
          if (!empty($r['owner'])) { $sub[] = (string) $r['owner']; }
          if (!empty($r['due_date'])) { $sub[] = 'prazo ' . substr((string) $r['due_date'], 0, 10); }
          if (!empty($r['post_date'])) { $sub[] = 'publicar ' . substr((string) $r['post_date'], 0, 10); }
        ?>
        <div class="task<?= $st === 'done' ? ' is-done' : '' ?>">
          <span class="badge" style="background:<?= $h($stColor) ?>"><?= $h($stLabel) ?></span>
          <div class="body">
            <div class="title"><span class="id">#<?= (int) $r['id'] ?></span> <?= $h((string) $r['title']) ?></div>
            <?php if ($sub !== [] || !empty($r['details'])): ?>
              <div class="sub">
                <?= $h(implode(' · ', $sub)) ?><?php if ($sub !== [] && !empty($r['details'])): ?> — <?php endif; ?><?= $h((string) ($r['details'] ?? '')) ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>

  <p class="foot">
    No grupo do WhatsApp: <code>tarefas</code> lista · <code>feito &lt;nº&gt;</code> conclui · <code>nova &lt;área&gt;: &lt;título&gt;</code> cria.
  </p>
</div>
</body>
</html>
