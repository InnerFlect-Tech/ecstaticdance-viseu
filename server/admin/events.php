<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/attendance.php';
require_admin_session();

function ev_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ev_now_sql(): string {
    return date('Y-m-d H:i:s');
}

function ev_cut(string $value, int $max): string {
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return strlen($value) <= $max ? $value : substr($value, 0, $max);
}

$pdo = db();
edv_attendance_ensure_schema($pdo);
$flash = '';
$selectedEventId = (int)($_REQUEST['event_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_event') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $date = trim((string)($_POST['date'] ?? ''));
        $timeStart = trim((string)($_POST['time_start'] ?? '16:00:00'));
        $timeEnd = trim((string)($_POST['time_end'] ?? '19:00:00'));
        $doorsOpen = trim((string)($_POST['doors_open'] ?? '15:30:00'));
        $location = trim((string)($_POST['location'] ?? ''));
        $type = (string)($_POST['type'] ?? 'paid');
        $capacity = max(0, (int)($_POST['capacity'] ?? 0));
        $minPrice = max(0.0, (float)($_POST['min_price'] ?? 0));
        $returningMinRaw = trim((string)($_POST['returning_min_eur'] ?? ''));
        $returningMin = $returningMinRaw === '' ? null : max(0.0, (float)$returningMinRaw);
        $earlyBirdMinRaw = trim((string)($_POST['early_bird_min_eur'] ?? ''));
        $earlyBirdMin = $earlyBirdMinRaw === '' ? null : max(0.0, (float)$earlyBirdMinRaw);
        $earlyBirdUntilRaw = trim((string)($_POST['early_bird_until'] ?? ''));
        $earlyBirdUntil = $earlyBirdUntilRaw !== '' ? $earlyBirdUntilRaw : null;
        $doorsClose = trim((string)($_POST['doors_close'] ?? ''));
        $danceStart = trim((string)($_POST['dance_start'] ?? ''));
        $danceEnd = trim((string)($_POST['dance_end'] ?? ''));
        $integrationTime = trim((string)($_POST['integration_time'] ?? ''));
        $djName = trim((string)($_POST['dj_name'] ?? ''));
        $djInstagram = trim((string)($_POST['dj_instagram'] ?? ''));
        $warmupName = trim((string)($_POST['warmup_name'] ?? ''));
        $warmupInstagram = trim((string)($_POST['warmup_instagram'] ?? ''));
        $integrationName = trim((string)($_POST['integration_name'] ?? ''));
        $integrationInstagram = trim((string)($_POST['integration_instagram'] ?? ''));
        $locationUrl = trim((string)($_POST['location_url'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($title === '' || $date === '') {
            $flash = 'Título e data são obrigatórios.';
        } elseif (!in_array($type, ['paid', 'free'], true)) {
            $flash = 'Tipo de evento inválido.';
        } else {
            $stmt = $pdo->prepare(
                "UPDATE events
                 SET title = ?, description = ?, date = ?, time_start = ?, time_end = ?, doors_open = ?,
                     doors_close = ?, dance_start = ?, dance_end = ?, integration_time = ?,
                     location = ?, location_url = ?, type = ?, capacity = ?, min_price = ?, returning_min_eur = ?,
                     early_bird_min_eur = ?, early_bird_until = ?,
                     dj_name = ?, dj_instagram = ?, warmup_name = ?, warmup_instagram = ?,
                     integration_name = ?, integration_instagram = ?,
                     is_active = ?
                 WHERE id = ?"
            );
            $stmt->execute([
                ev_cut($title, 255),
                $description !== '' ? $description : null,
                $date,
                $timeStart !== '' ? $timeStart : null,
                $timeEnd !== '' ? $timeEnd : null,
                $doorsOpen !== '' ? $doorsOpen : null,
                $doorsClose !== '' ? $doorsClose : null,
                $danceStart !== '' ? $danceStart : null,
                $danceEnd !== '' ? $danceEnd : null,
                $integrationTime !== '' ? $integrationTime : null,
                $location !== '' ? ev_cut($location, 255) : null,
                $locationUrl !== '' ? ev_cut($locationUrl, 512) : null,
                $type,
                $capacity,
                $minPrice,
                $returningMin,
                $earlyBirdMin,
                $earlyBirdUntil,
                $djName !== '' ? ev_cut($djName, 255) : null,
                $djInstagram !== '' ? ev_cut(ltrim($djInstagram, '@'), 64) : null,
                $warmupName !== '' ? ev_cut($warmupName, 255) : null,
                $warmupInstagram !== '' ? ev_cut(ltrim($warmupInstagram, '@'), 64) : null,
                $integrationName !== '' ? ev_cut($integrationName, 255) : null,
                $integrationInstagram !== '' ? ev_cut(ltrim($integrationInstagram, '@'), 64) : null,
                $isActive,
                $eventId,
            ]);
            if ($isActive === 1) {
                $pdo->prepare('UPDATE events SET is_active = 0 WHERE id != ?')->execute([$eventId]);
            }
            $selectedEventId = $eventId;
            $flash = $isActive === 1
                ? 'Evento atualizado e definido como único activo para venda.'
                : 'Evento atualizado.';
        }
    } elseif ($action === 'create_event') {
        $title = trim((string)($_POST['title'] ?? ''));
        $date = trim((string)($_POST['date'] ?? ''));
        if ($title === '' || $date === '') {
            $flash = 'Para criar um evento, preenche título e data.';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO events
                 (title, description, date, time_start, time_end, doors_open, location, type, capacity, min_price, is_active, created_at)
                 VALUES (?, NULL, ?, '16:00:00', '19:00:00', '15:30:00', 'Viseu', 'paid', 60, 25.00, 0, ?)"
            );
            $stmt->execute([ev_cut($title, 255), $date, ev_now_sql()]);
            $selectedEventId = (int)$pdo->lastInsertId();
            $flash = 'Novo evento criado. Completa os detalhes e ativa quando estiver pronto.';
        }
    }
}

$eventsStmt = $pdo->query(
    "SELECT e.*,
            COALESCE(SUM(CASE WHEN t.payment_status IN ('paid','free') THEN 1 ELSE 0 END), 0) AS tickets_sold
     FROM events e
     LEFT JOIN tickets t ON t.event_id = e.id
     GROUP BY e.id
     ORDER BY e.date DESC
     LIMIT 40"
);
$events = $eventsStmt->fetchAll();
if ($selectedEventId <= 0 && !empty($events)) {
    $selectedEventId = (int)$events[0]['id'];
}

$selected = null;
foreach ($events as $ev) {
    if ((int)$ev['id'] === $selectedEventId) {
        $selected = $ev;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex,nofollow" />
  <title>Eventos — Admin</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root { --dark:#0E0B09; --dark-m:#1A1210; --dark-l:#2A1E1A; --bone:#F5EFE6; --gold:#D4A85A; --ok:#2d6a4f; }
    body { background: var(--dark); color: var(--bone); font-family: Arial, sans-serif; font-size: 14px; }
    <?php require __DIR__ . '/_topbar-styles.php'; ?>
    .main { max-width: 1180px; margin: 0 auto; padding: 1.2rem 1rem 2.5rem; }
    .head { margin-bottom: 1rem; }
    .head h1 { font-weight: 300; font-size: 1.5rem; }
    .head p { color: rgba(245,239,230,.55); font-size: .82rem; margin-top: .25rem; }
    .flash { background: rgba(45,106,79,.18); border: 1px solid rgba(45,106,79,.36); padding: .65rem .85rem; margin-bottom: .9rem; border-radius: 8px; font-size: .82rem; }
    .grid { display: grid; grid-template-columns: 1fr; gap: 1rem; }
    @media (min-width: 980px) { .grid { grid-template-columns: 360px 1fr; } }
    .panel { background: var(--dark-m); border: 1px solid rgba(245,239,230,.08); border-radius: 10px; padding: .95rem; }
    .panel h2 { font-size: .68rem; letter-spacing: .14em; text-transform: uppercase; color: rgba(245,239,230,.38); margin-bottom: .8rem; }
    .event-list { display: grid; gap: .45rem; max-height: 70vh; overflow: auto; }
    .event-item { display: block; padding: .65rem .7rem; border: 1px solid rgba(245,239,230,.08); border-radius: 8px; text-decoration: none; color: inherit; }
    .event-item.active { border-color: rgba(212,168,90,.45); background: rgba(212,168,90,.08); }
    .event-title { font-size: .85rem; }
    .event-meta { font-size: .72rem; color: rgba(245,239,230,.5); margin-top: .2rem; }
    .event-state { font-size: .68rem; margin-top: .28rem; color: #6bcf9a; }
    .event-state.off { color: rgba(245,239,230,.45); }
    .form-grid { display: grid; grid-template-columns: 1fr; gap: .62rem; }
    @media (min-width: 760px) { .form-grid.cols2 { grid-template-columns: 1fr 1fr; } }
    @media (min-width: 980px) { .form-grid.cols3 { grid-template-columns: 1fr 1fr 1fr; } }
    input, select, textarea { width: 100%; background: rgba(245,239,230,.06); border: 1px solid rgba(245,239,230,.16); color: var(--bone);
      padding: .48rem .56rem; font-size: .82rem; border-radius: 8px; font-family: inherit; }
    textarea { min-height: 110px; resize: vertical; }
    .field label { display: block; font-size: .62rem; letter-spacing: .1em; text-transform: uppercase; color: rgba(245,239,230,.42); margin-bottom: .25rem; }
    .btn { border: 1px solid rgba(245,239,230,.18); background: rgba(245,239,230,.06); color: var(--bone); cursor: pointer;
      padding: .5rem .8rem; border-radius: 8px; font-size: .72rem; text-transform: uppercase; letter-spacing: .08em; }
    .btn:hover { border-color: rgba(245,239,230,.35); }
    .help { font-size: .75rem; color: rgba(245,239,230,.5); margin-top: .5rem; line-height: 1.45; }
    .switch { display: inline-flex; align-items: center; gap: .5rem; margin-top: .2rem; font-size: .82rem; }
  </style>
</head>
<body class="has-bottom-tabs">
<?php
$__adminNav = 'events';
$__exportEventId = null;
require __DIR__ . '/_topbar.php';
?>

<main class="main">
  <div class="head">
    <h1>Gestão de eventos</h1>
    <p>Aqui defines capacidade, localização, horários, tipo e restantes detalhes do evento.</p>
  </div>

  <?php if ($flash !== ''): ?>
    <div class="flash"><?= ev_h($flash) ?></div>
  <?php endif; ?>

  <div class="grid">
    <section class="panel">
      <h2>Eventos</h2>

      <form method="post" style="margin-bottom:.9rem;">
        <input type="hidden" name="action" value="create_event" />
        <div class="form-grid cols2">
          <div class="field">
            <label>Título novo evento</label>
            <input type="text" name="title" placeholder="Ecstatic Dance Viseu #..." required />
          </div>
          <div class="field">
            <label>Data</label>
            <input type="date" name="date" required />
          </div>
        </div>
        <div style="margin-top:.55rem;">
          <button class="btn" type="submit">Criar evento</button>
        </div>
      </form>

      <div class="event-list">
        <?php foreach ($events as $ev): ?>
          <a class="event-item <?= (int)$ev['id'] === $selectedEventId ? 'active' : '' ?>" href="/admin/events.php?event_id=<?= (int)$ev['id'] ?>">
            <div class="event-title"><?= ev_h((string)$ev['title']) ?></div>
            <div class="event-meta"><?= ev_h(date('d/m/Y', strtotime((string)$ev['date']))) ?> · <?= (int)$ev['tickets_sold'] ?> bilhetes</div>
            <div class="event-state <?= (int)$ev['is_active'] === 1 ? '' : 'off' ?>">
              <?= (int)$ev['is_active'] === 1 ? 'Ativo' : 'Inativo' ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="panel">
      <h2>Detalhes do evento</h2>
      <?php if (!$selected): ?>
        <p class="help">Cria ou seleciona um evento.</p>
      <?php else: ?>
        <form method="post">
          <input type="hidden" name="action" value="save_event" />
          <input type="hidden" name="event_id" value="<?= (int)$selected['id'] ?>" />

          <div class="form-grid cols2">
            <div class="field">
              <label>Título</label>
              <input type="text" name="title" value="<?= ev_h((string)$selected['title']) ?>" required />
            </div>
            <div class="field">
              <label>Localização</label>
              <input type="text" name="location" value="<?= ev_h((string)($selected['location'] ?? '')) ?>" placeholder="Nua e Crua, Viseu" />
            </div>
            <div class="field">
              <label>Link do local (opcional)</label>
              <input type="url" name="location_url" value="<?= ev_h((string)($selected['location_url'] ?? '')) ?>" placeholder="https://www.instagram.com/_nua_e_crua_/" />
            </div>
          </div>

          <div class="field" style="margin-top:.62rem;">
            <label>Descrição</label>
            <textarea name="description"><?= ev_h((string)($selected['description'] ?? '')) ?></textarea>
          </div>

          <div class="form-grid cols3" style="margin-top:.62rem;">
            <div class="field">
              <label>Data</label>
              <input type="date" name="date" value="<?= ev_h((string)$selected['date']) ?>" required />
            </div>
            <div class="field">
              <label>Abertura de portas</label>
              <input type="time" name="doors_open" value="<?= ev_h((string)($selected['doors_open'] ?? '15:30')) ?>" />
            </div>
            <div class="field">
              <label>Hora início (warm-up)</label>
              <input type="time" name="time_start" value="<?= ev_h((string)($selected['time_start'] ?? '16:00')) ?>" />
            </div>
          </div>

          <div class="form-grid cols3" style="margin-top:.62rem;">
            <div class="field">
              <label>Dança — início</label>
              <input type="time" name="dance_start" value="<?= ev_h((string)($selected['dance_start'] ?? '')) ?>" placeholder="16:30" />
            </div>
            <div class="field">
              <label>Dança — fim</label>
              <input type="time" name="dance_end" value="<?= ev_h((string)($selected['dance_end'] ?? '')) ?>" placeholder="18:30" />
            </div>
            <div class="field">
              <label>Integração</label>
              <input type="time" name="integration_time" value="<?= ev_h((string)($selected['integration_time'] ?? '')) ?>" placeholder="18:30" />
            </div>
          </div>

          <div class="form-grid cols3" style="margin-top:.62rem;">
            <div class="field">
              <label>Hora fim (chá e convívio)</label>
              <input type="time" name="time_end" value="<?= ev_h((string)($selected['time_end'] ?? '19:00')) ?>" />
            </div>
            <div class="field">
              <label>Fecho de portas</label>
              <input type="time" name="doors_close" value="<?= ev_h((string)($selected['doors_close'] ?? '')) ?>" placeholder="20:30" />
            </div>
            <div class="field">
              <label>Tipo</label>
              <select name="type">
                <option value="paid" <?= (string)$selected['type'] === 'paid' ? 'selected' : '' ?>>Pago</option>
                <option value="free" <?= (string)$selected['type'] === 'free' ? 'selected' : '' ?>>Gratuito</option>
              </select>
            </div>
          </div>

          <div class="form-grid cols3" style="margin-top:.62rem;">
            <div class="field">
              <label>Capacidade</label>
              <input type="number" min="0" name="capacity" value="<?= (int)$selected['capacity'] ?>" />
            </div>
          </div>

          <div class="form-grid cols2" style="margin-top:.62rem;">
            <div class="field">
              <label>DJ</label>
              <input type="text" name="dj_name" value="<?= ev_h((string)($selected['dj_name'] ?? '')) ?>" placeholder="Bernardo B-file" />
            </div>
            <div class="field">
              <label>Instagram DJ (sem @)</label>
              <input type="text" name="dj_instagram" value="<?= ev_h((string)($selected['dj_instagram'] ?? '')) ?>" placeholder="b_filemusic" />
            </div>
          </div>

          <div class="form-grid cols2" style="margin-top:.62rem;">
            <div class="field">
              <label>Facilitador·a warm-up</label>
              <input type="text" name="warmup_name" value="<?= ev_h((string)($selected['warmup_name'] ?? '')) ?>" placeholder="Vazio = a anunciar" />
            </div>
            <div class="field">
              <label>Instagram warm-up</label>
              <input type="text" name="warmup_instagram" value="<?= ev_h((string)($selected['warmup_instagram'] ?? '')) ?>" />
            </div>
          </div>

          <div class="form-grid cols2" style="margin-top:.62rem;">
            <div class="field">
              <label>Facilitador·a integração</label>
              <input type="text" name="integration_name" value="<?= ev_h((string)($selected['integration_name'] ?? '')) ?>" placeholder="Vazio = a anunciar" />
            </div>
            <div class="field">
              <label>Instagram integração</label>
              <input type="text" name="integration_instagram" value="<?= ev_h((string)($selected['integration_instagram'] ?? '')) ?>" />
            </div>
          </div>

          <div class="form-grid cols2" style="margin-top:.62rem;">
            <div class="field">
              <label>Preço standard (€)</label>
              <input type="number" min="0" step="0.01" name="min_price" value="<?= number_format((float)$selected['min_price'], 2, '.', '') ?>" />
            </div>
            <div class="field">
              <label>Preço regresso (€)</label>
              <input type="number" min="0" step="0.01" name="returning_min_eur"
                     value="<?= isset($selected['returning_min_eur']) && $selected['returning_min_eur'] !== null && $selected['returning_min_eur'] !== ''
                       ? number_format((float)$selected['returning_min_eur'], 2, '.', '') : '' ?>"
                     placeholder="15 (predefinição)" />
            </div>
          </div>

          <div class="form-grid cols2" style="margin-top:.62rem;">
            <div class="field">
              <label>Early bird (€)</label>
              <input type="number" min="0" step="0.01" name="early_bird_min_eur"
                     value="<?= isset($selected['early_bird_min_eur']) && $selected['early_bird_min_eur'] !== null && $selected['early_bird_min_eur'] !== ''
                       ? number_format((float)$selected['early_bird_min_eur'], 2, '.', '') : '' ?>"
                     placeholder="20 (predefinição)" />
            </div>
            <div class="field">
              <label>Early bird até (inclusivo)</label>
              <input type="date" name="early_bird_until"
                     value="<?= ev_h((string)($selected['early_bird_until'] ?? '')) ?>" />
            </div>
          </div>

          <div class="form-grid cols2" style="margin-top:.62rem;">
            <div class="field">
              <label>Estado</label>
              <label class="switch">
                <input type="checkbox" name="is_active" value="1" <?= (int)$selected['is_active'] === 1 ? 'checked' : '' ?> />
                Evento ativo para venda
              </label>
            </div>
          </div>

          <div style="margin-top:.75rem;">
            <button class="btn" type="submit">Guardar alterações</button>
          </div>
        </form>
        <p class="help">A capacidade usada nos painéis e no checkout vem de <code>events.capacity</code>. Esta página grava diretamente nessa tabela.</p>
        <p class="help" style="margin-top:.45rem"><strong>Preço standard</strong> é o piso após o early bird. <strong>Early bird</strong> aplica-se até ao fim do dia indicado (hora de Lisboa). Deixa a data vazia para desactivar early bird.</p>
        <p class="help" style="margin-top:.45rem">Quem fez check-in numa edição anterior (lista em <a href="/admin/attendance.php" style="color:#D4A85A">Presenças</a>) paga o piso de <strong>regresso</strong> ao usar o mesmo email. Vazio = 15€.</p>
        <p class="help" style="margin-top:.45rem">Horários vazios (dança, integração) usam valores por defeito a partir do warm-up e do fim do evento. A página <code>/links</code> lê estes campos do evento <strong>activo para venda</strong>.</p>
        <p class="help" style="margin-top:.65rem">A página <code>/links</code> mostra automaticamente o evento com <strong>activo para venda</strong> (título, horários, facilitadores, preços). O slug das reservas manuais é <code>edv-<?= ev_h((string)$selected['date']) ?></code>. Só um evento deve estar activo de cada vez.</p>
      <?php endif; ?>
    </section>
  </div>
</main>

</body>
</html>
