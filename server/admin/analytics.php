<?php
declare(strict_types=1);
/**
 * analytics.php — Traffic overview (mock data until GA Data API is wired).
 */

require_once __DIR__ . '/auth.php';
require_admin_session();

// ── Mock snapshot (replace with GA4 Data API or embedded Looker Studio later) ──
$mockPeriod = 'Últimos 28 dias (demo)';
$mockKpis = [
    ['label' => 'Utilizadores activos', 'value' => '3.4k', 'delta' => '+12%', 'up' => true],
    ['label' => 'Sessões', 'value' => '5.1k', 'delta' => '+8%', 'up' => true],
    ['label' => 'Novos utilizadores', 'value' => '2.7k', 'delta' => '+5%', 'up' => true],
    ['label' => 'Taxa de envolvimento', 'value' => '61%', 'delta' => '−2%', 'up' => false],
];
$mockDaily = [120, 95, 140, 132, 160, 148, 175, 165, 190, 182, 170, 188, 195, 210, 198, 205, 220, 215, 208, 225, 230, 218, 240, 235, 242, 250, 248, 260];
$mockSources = [
    ['source' => 'google / organic', 'sessions' => '2 010', 'pct' => 39],
    ['source' => '(direct) / (none)', 'sessions' => '1 240', 'pct' => 24],
    ['source' => 'instagram / social', 'sessions' => '890', 'pct' => 17],
    ['source' => 'ecstaticdanceviseu.pt / referral', 'sessions' => '410', 'pct' => 8],
    ['source' => 'facebook / social', 'sessions' => '310', 'pct' => 6],
];
$mockPages = [
    ['path' => '/', 'views' => '8.2k'],
    ['path' => '/links.html', 'views' => '3.1k'],
    ['path' => '/booking', 'views' => '1.4k'],
    ['path' => '/obrigado', 'views' => '920'],
];
$gaPropertyHint = 'G-XXXXXXXXXX'; // Substituir pelo ID real da propriedade GA4
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex,nofollow" />
<title>Analytics — Admin</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --dark: #0E0B09; --dark-m: #1A1210; --dark-l: #2A1E1A;
    --bone: #F5EFE6; --bone-d: #E8DCCC;
    --terra: #8B3A2A; --terra-l: #C4593F;
    --gold: #B8924A; --gold-l: #D4A85A;
    --verde: #1E2E27; --verde-m: #2A3D35;
    --success: #2d6a4f; --danger: #c45d4a;
  }
  body { background: var(--dark); color: var(--bone); font-family: Arial, sans-serif; font-weight: 300; font-size: 14px; line-height: 1.45; }
  a { color: inherit; text-decoration: none; }

  <?php require __DIR__ . '/_topbar-styles.php'; ?>

  .analytics-main { max-width: 1200px; margin: 0 auto; padding: 1.25rem 1rem 3rem; }
  @media (min-width: 720px) { .analytics-main { padding: 1.75rem 1.5rem 3rem; } }

  .page-head { margin-bottom: 1.75rem; }
  .page-head h1 { font-size: 1.45rem; font-weight: 300; margin-bottom: 0.35rem; }
  .page-head p { font-size: 0.82rem; color: rgba(245,239,230,0.42); }
  .demo-pill {
    display: inline-flex; align-items: center; gap: 0.35rem;
    margin-top: 0.75rem; padding: 0.35rem 0.65rem; border-radius: 999px;
    font-size: 0.65rem; letter-spacing: 0.12em; text-transform: uppercase;
    background: rgba(184,146,74,0.12); color: var(--gold-l); border: 1px solid rgba(184,146,74,0.28);
  }

  .kpi-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.75rem;
    margin-bottom: 1.5rem;
  }
  @media (min-width: 540px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }
  @media (min-width: 960px) { .kpi-grid { grid-template-columns: repeat(4, 1fr); } }

  .kpi-card {
    background: var(--dark-l);
    border: 1px solid rgba(245,239,230,0.06);
    border-radius: 14px;
    padding: 1.1rem 1.15rem;
    min-height: 96px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 0.35rem;
  }
  .kpi-card .lbl {
    font-size: 0.62rem; letter-spacing: 0.14em; text-transform: uppercase;
    color: rgba(245,239,230,0.38); font-weight: 400;
  }
  .kpi-card .val { font-size: 1.65rem; font-weight: 300; color: var(--bone); line-height: 1.1; }
  .kpi-card .delta { font-size: 0.78rem; font-weight: 400; }
  .kpi-card .delta.up { color: #6bcf9a; }
  .kpi-card .delta.down { color: #e8a598; }

  .panel {
    background: var(--dark-m);
    border: 1px solid rgba(245,239,230,0.07);
    border-radius: 14px;
    padding: 1.15rem 1.2rem;
    margin-bottom: 1rem;
  }
  .panel h2 {
    font-size: 0.72rem; letter-spacing: 0.16em; text-transform: uppercase;
    color: rgba(245,239,230,0.4); font-weight: 400; margin-bottom: 1rem;
  }

  .spark-wrap { width: 100%; overflow: hidden; }
  .spark-bars {
    display: flex; align-items: flex-end; gap: 3px;
    height: 140px;
    padding-top: 0.5rem;
  }
  .spark-bars i {
    flex: 1; min-width: 4px; border-radius: 3px 3px 0 0;
    background: linear-gradient(180deg, rgba(184,146,74,0.85), rgba(139,58,42,0.5));
    display: block;
    transition: opacity 0.15s;
  }
  .spark-bars i:hover { opacity: 0.85; }

  table.src-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
  .src-table th {
    text-align: left; font-size: 0.58rem; letter-spacing: 0.12em; text-transform: uppercase;
    color: rgba(245,239,230,0.35); font-weight: 400; padding: 0.5rem 0.35rem 0.65rem 0;
    border-bottom: 1px solid rgba(245,239,230,0.08);
  }
  .src-table td { padding: 0.55rem 0.35rem; border-bottom: 1px solid rgba(245,239,230,0.05); color: rgba(245,239,230,0.78); }
  .src-table .bar-cell { width: 38%; }
  .mini-bar { height: 6px; border-radius: 4px; background: rgba(245,239,230,0.08); overflow: hidden; margin-top: 0.35rem; }
  .mini-bar span { display: block; height: 100%; border-radius: 4px; background: linear-gradient(90deg, var(--terra-l), var(--gold-l)); }

  .cta-row {
    display: flex; flex-wrap: wrap; gap: 0.65rem; align-items: center;
    margin-top: 1.25rem;
  }
  .ext-link {
    display: inline-flex; align-items: center; gap: 0.4rem;
    padding: 0.55rem 1rem; border-radius: 10px;
    font-size: 0.72rem; letter-spacing: 0.1em; text-transform: uppercase;
    background: rgba(245,239,230,0.06);
    border: 1px solid rgba(245,239,230,0.14);
    color: var(--bone);
  }
  .ext-link:hover { border-color: rgba(184,146,74,0.45); color: var(--gold-l); }

  details.ga-guide {
    margin-top: 1.5rem; border: 1px solid rgba(245,239,230,0.1);
    border-radius: 12px; padding: 0.85rem 1rem; background: rgba(14,11,9,0.5);
  }
  details.ga-guide summary {
    cursor: pointer; font-size: 0.78rem; letter-spacing: 0.08em;
    color: rgba(245,239,230,0.65); list-style-position: inside;
  }
  details.ga-guide[open] summary { margin-bottom: 0.85rem; color: var(--gold-l); }
  .ga-guide ul { margin-left: 1.1rem; font-size: 0.82rem; color: rgba(245,239,230,0.72); }
  .ga-guide li { margin-bottom: 0.45rem; }
  .ga-guide code {
    font-size: 0.74rem; background: rgba(245,239,230,0.06); padding: 0.1rem 0.35rem;
    border-radius: 4px; color: rgba(245,239,230,0.85);
  }

  .split-panels {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
  }
  @media (min-width: 900px) {
    .split-panels { grid-template-columns: 1fr 1fr; }
  }

  <?php require __DIR__ . '/_scanner-styles.php'; ?>
</style>
</head>
<body class="has-bottom-tabs">

<?php
$__adminNav = 'analytics';
$__exportEventId = null;
require __DIR__ . '/_topbar.php';
?>

<main class="analytics-main">
  <header class="page-head">
    <h1>Analytics</h1>
    <p><?= htmlspecialchars($mockPeriod, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · dados de exemplo até ligar ao GA4</p>
    <span class="demo-pill" role="status">Mock — não ligado ao Google</span>
  </header>

  <section class="kpi-grid" aria-label="Indicadores principais">
    <?php foreach ($mockKpis as $row): ?>
      <article class="kpi-card">
        <span class="lbl"><?= htmlspecialchars($row['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        <span class="val"><?= htmlspecialchars($row['value'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        <span class="delta <?= !empty($row['up']) ? 'up' : 'down' ?>"><?= htmlspecialchars($row['delta'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> vs. período anterior</span>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="panel" aria-labelledby="sess-heading">
    <h2 id="sess-heading">Sessões por dia</h2>
    <div class="spark-wrap">
      <div class="spark-bars" role="img" aria-label="Gráfico de barras de sessões diárias (demonstração)">
        <?php
        $max = max($mockDaily) ?: 1;
        foreach ($mockDaily as $v) {
            $h = (int)round(($v / $max) * 100);
            echo '<i style="height:' . $h . '%" title="' . (int)$v . '"></i>';
        }
        ?>
      </div>
    </div>
  </section>

  <div class="split-panels">
    <section class="panel" aria-labelledby="src-heading" style="margin-bottom:0;">
      <h2 id="src-heading">Tráfego por canal (sessão)</h2>
      <table class="src-table">
        <thead>
          <tr>
            <th>Origem / médio</th>
            <th>Sessões</th>
            <th class="bar-cell">Partilha</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($mockSources as $s): ?>
            <tr>
              <td><?= htmlspecialchars($s['source'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($s['sessions'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
              <td class="bar-cell">
                <?= (int)$s['pct'] ?>%
                <div class="mini-bar" aria-hidden="true"><span style="width: <?= (int)$s['pct'] ?>%;"></span></div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <section class="panel" aria-labelledby="pages-heading" style="margin-bottom:0;">
      <h2 id="pages-heading">Páginas vistas (top)</h2>
      <table class="src-table">
        <thead>
          <tr>
            <th>Caminho</th>
            <th>Vistas</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($mockPages as $p): ?>
            <tr>
              <td><code style="background:none;padding:0;"><?= htmlspecialchars($p['path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></td>
              <td><?= htmlspecialchars($p['views'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  </div>

  <div class="cta-row">
    <a class="ext-link" href="https://analytics.google.com/" target="_blank" rel="noopener noreferrer">
      Abrir Google Analytics
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
    </a>
    <span style="font-size:0.78rem;color:rgba(245,239,230,0.35)">Property ID alvo: <code><?= htmlspecialchars($gaPropertyHint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></span>
  </div>

  <details class="ga-guide">
    <summary>Guia rápido: alinhar medição com GA4 (2026)</summary>
    <ul>
      <li><strong>Google tag (gtag.js)</strong>: instalar o snippet global com o teu <code>Measurement ID</code> (<code>G-XXXX</code>) e usar <code>gtag('config', 'G-XXXX', { … })</code> para parâmetros globais (ex.: <code>currency: 'EUR'</code>). Fonte: Google Analytics — event parameters / config.</li>
      <li><strong>Eventos recomendados</strong>: para conversões de bilhetes usa os nomes e campos documentados (ex.: <code>begin_checkout</code>, <code>purchase</code> com <code>transaction_id</code>, <code>value</code>, <code>currency</code>, <code>items</code>[]). Evita nomes arbitrários nas conversões críticas.</li>
      <li><strong>Parâmetros personalizados</strong>: regista <em>custom dimensions</em> (âmbito evento ou utilizador) na propriedade antes de consultá-los nos relatórios ou na Data API.</li>
      <li><strong>Consent Mode</strong>: carrega o modo de consentimento antes de <code>gtag('config')</code> se tiveres banner RGPD; só envia sinais de publicidade/analytics depois do utilizador aceitar (requisitos legais da tua jurisdição).</li>
      <li><strong>DebugView</strong>: em desenvolvimento usa <code>debug_mode: true</code> na config ou o URL de preview para validar eventos em tempo real.</li>
      <li><strong>Este painel admin</strong>: para dados reais aqui, usa a <strong>Google Analytics Data API</strong> (<code>runReport</code> em <code>properties/123456789</code>) com uma conta de serviço e quotas — ou incorpora um relatório Looker Studio. Os números acima são placeholders.</li>
      <li><strong>Retenção</strong>: define retenção de dados e, se precisares de audiências remarketing, avalia Google Signals nas definições da propriedade.</li>
    </ul>
  </details>
</main>

<?php require __DIR__ . '/_scanner-modal.php'; ?>
<?php require __DIR__ . '/_scanner-script.php'; ?>

</body>
</html>
