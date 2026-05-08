<?php
declare(strict_types=1);
/**
 * Admin navigation — mesmo menu em todas as páginas.
 * Scan sempre ao centro (desktop + mobile).
 *
 * Opcional antes do include:
 *   $__adminNav          'tickets' | 'links' | 'analytics'
 *   $__exportEventId     int|null
 *   $__secondaryCsvHref  string|null
 *   $__hideDbBackup      bool (omit link to full SQL backup)
 */
$__adminNav         = $__adminNav ?? 'tickets';
$__exportEventId    = $__exportEventId ?? null;
$__secondaryCsvHref = $__secondaryCsvHref ?? null;
$__hideDbBackup     = $__hideDbBackup ?? false;

$isTickets   = $__adminNav === 'tickets';
$isLinks     = $__adminNav === 'links';
$isAnalytics = $__adminNav === 'analytics';

$iChart  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>';
$iTicket = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/></svg>';
$iLink   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
$iScan   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="7" y1="12" x2="17" y2="12"/></svg>';
$iBackup = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>';
$iLogout = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>';
?>
<!-- ══════════ TOP BAR ══════════ -->
<div class="topbar">

  <span class="topbar-brand" aria-label="Ecstatic Dance Viseu Admin">
    EDV — Admin
  </span>

  <!-- Desktop: Analytics · Reservas · Scan · Check-in -->
  <nav class="topbar-nav" aria-label="Administração">
    <a href="/admin/analytics.php"
       class="tb-btn tb-pill<?= $isAnalytics ? ' is-active' : '' ?>"
       <?= $isAnalytics ? 'aria-current="page"' : '' ?>>
      <?= $iChart ?> Analytics
    </a>
    <a href="/admin/link-bookings.php"
       class="tb-btn tb-pill<?= $isLinks ? ' is-active' : '' ?>"
       <?= $isLinks ? 'aria-current="page"' : '' ?>>
      <?= $iLink ?> Inscrições (/links)
    </a>
    <button type="button" class="tb-btn tb-scan-fab tb-scan-nav" id="openScannerBtn" aria-label="Scan QR code">
      <?= $iScan ?>
      <span class="tb-scan-label">Scan QR</span>
    </button>
    <a href="/admin/"
       class="tb-btn tb-pill<?= $isTickets ? ' is-active' : '' ?>"
       <?= $isTickets ? 'aria-current="page"' : '' ?>>
      <?= $iTicket ?> Check-in
    </a>
  </nav>

  <div class="topbar-ctx">

    <?php if (!$__hideDbBackup): ?>
      <a href="/admin/export-database.php"
         class="tb-btn tb-icon-btn" aria-label="Descarregar backup SQL da base de dados" title="Backup SQL (toda a base)">
        <?= $iBackup ?>
      </a>
    <?php endif; ?>

    <a href="/admin/logout.php" class="tb-btn tb-icon-btn tb-logout" aria-label="Terminar sessão" title="Sair">
      <?= $iLogout ?>
      <span class="tb-logout-label">Sair</span>
    </a>

  </div>
</div>

<!-- Mobile: mesma ordem — Scan sempre ao centro -->
<nav class="bottom-tabs" aria-label="Navegação principal">

  <a href="/admin/analytics.php"
     class="btab btab-analytics<?= $isAnalytics ? ' is-active' : '' ?>"
     <?= $isAnalytics ? 'aria-current="page"' : '' ?>>
    <?= $iChart ?>
    <span class="btab-label">Analytics</span>
  </a>

  <a href="/admin/link-bookings.php"
     class="btab<?= $isLinks ? ' is-active' : '' ?>"
     <?= $isLinks ? 'aria-current="page"' : '' ?>>
    <?= $iLink ?>
    <span class="btab-label">Inscrições</span>
  </a>

  <button type="button" class="btab btab-scan" id="openScannerBtnMobile" aria-label="Scan QR code">
    <span class="btab-icon-wrap"><?= $iScan ?></span>
    <span class="btab-label btab-scan-sub">Scan</span>
  </button>

  <a href="/admin/"
     class="btab<?= $isTickets ? ' is-active' : '' ?>"
     <?= $isTickets ? 'aria-current="page"' : '' ?>>
    <?= $iTicket ?>
    <span class="btab-label">Check-in</span>
  </a>

  <a href="/admin/logout.php" class="btab" aria-label="Terminar sessão">
    <?= $iLogout ?>
    <span class="btab-label">Sair</span>
  </a>

</nav>
