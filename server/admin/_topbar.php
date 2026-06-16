<?php
declare(strict_types=1);
/**
 * Admin navigation — mesmo menu em todas as páginas.
 * Scan sempre ao centro (desktop + mobile).
 *
 * Opcional antes do include:
 *   $__adminNav          'tickets' | 'links' | 'analytics' | 'costs' | 'events' | 'attendance' | 'participants' | 'codes' | 'scan'
 *   $__exportEventId     int|null
 *   $__secondaryCsvHref  string|null
 *   $__hideDbBackup      bool (omit link to full SQL backup)
 */
$__adminNav         = $__adminNav ?? 'tickets';
$__exportEventId    = $__exportEventId ?? null;
$__secondaryCsvHref = $__secondaryCsvHref ?? null;
$__hideDbBackup     = $__hideDbBackup ?? false;
$__showAnalytics    = admin_analytics_enabled();

$isTickets   = $__adminNav === 'tickets';
$isLinks     = $__adminNav === 'links';
$isAnalytics = $__adminNav === 'analytics';
$isCosts     = $__adminNav === 'costs';
$isEvents     = $__adminNav === 'events';
$isAttendance = $__adminNav === 'attendance';
$isParticipants = $__adminNav === 'participants';
$isCodes      = $__adminNav === 'codes';
$isScan       = $__adminNav === 'scan';
$isTasks      = $__adminNav === 'tasks';
$isPromotion  = $__adminNav === 'promotion';

$iChart  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>';
$iCosts  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M3 12h18"/><path d="M3 18h18"/><circle cx="8" cy="6" r="1.5"/><circle cx="16" cy="12" r="1.5"/><circle cx="11" cy="18" r="1.5"/></svg>';
$iEvents = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>';
$iTicket = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/></svg>';
$iLink   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
$iScan   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="7" y1="12" x2="17" y2="12"/></svg>';
$iPeople = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
$iCodes  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-6"/><path d="M12 12V4"/><path d="M8 8l4-4 4 4"/></svg>';
$iTask   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>';
$iPromo  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l18-5v12L3 14z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>';
$iBackup = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>';
$iImport = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>';
$iLogout = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>';
?>
<!-- ══════════ TOP BAR ══════════ -->
<div class="topbar">

  <span class="topbar-brand" aria-label="Ecstatic Dance Viseu Admin">
    EDV — Admin
  </span>

  <!-- Desktop: Reservas · Scan · Check-in (Analytics when enabled) -->
  <nav class="topbar-nav" aria-label="Administração">
    <?php if ($__showAnalytics): ?>
    <a href="/admin/analytics.php"
       class="tb-btn tb-pill<?= $isAnalytics ? ' is-active' : '' ?>"
       <?= $isAnalytics ? 'aria-current="page"' : '' ?>>
      <?= $iChart ?> Analytics
    </a>
    <?php endif; ?>
    <a href="/admin/costs.php"
       class="tb-btn tb-pill<?= $isCosts ? ' is-active' : '' ?>"
       <?= $isCosts ? 'aria-current="page"' : '' ?>>
      <?= $iCosts ?> Custos
    </a>
    <a href="/admin/tasks.php"
       class="tb-btn tb-pill<?= $isTasks ? ' is-active' : '' ?>"
       <?= $isTasks ? 'aria-current="page"' : '' ?>>
      <?= $iTask ?> Tarefas
    </a>
    <a href="/admin/promotion.php"
       class="tb-btn tb-pill<?= $isPromotion ? ' is-active' : '' ?>"
       <?= $isPromotion ? 'aria-current="page"' : '' ?>>
      <?= $iPromo ?> Promoção
    </a>
    <a href="/admin/events.php"
       class="tb-btn tb-pill<?= $isEvents ? ' is-active' : '' ?>"
       <?= $isEvents ? 'aria-current="page"' : '' ?>>
      <?= $iEvents ?> Eventos
    </a>
    <a href="/admin/attendance.php"
       class="tb-btn tb-pill<?= $isAttendance ? ' is-active' : '' ?>"
       <?= $isAttendance ? 'aria-current="page"' : '' ?>>
      <?= $iPeople ?> Presenças
    </a>
    <a href="/admin/participants.php"
       class="tb-btn tb-pill<?= $isParticipants ? ' is-active' : '' ?>"
       <?= $isParticipants ? 'aria-current="page"' : '' ?>>
      <?= $iPeople ?> Participantes
    </a>
    <a href="/admin/discount-codes.php"
       class="tb-btn tb-pill<?= $isCodes ? ' is-active' : '' ?>"
       <?= $isCodes ? 'aria-current="page"' : '' ?>>
      <?= $iCodes ?> Códigos
    </a>
    <a href="/admin/link-bookings.php"
       class="tb-btn tb-pill<?= $isLinks ? ' is-active' : '' ?>"
       <?= $isLinks ? 'aria-current="page"' : '' ?>>
      <?= $iLink ?> Inscrições (/links)
    </a>
    <a href="/admin/scan.php"
       class="tb-btn tb-scan-fab tb-scan-nav<?= $isScan ? ' is-active' : '' ?>"
       <?= $isScan ? 'aria-current="page"' : '' ?>
       aria-label="Scanner QR">
      <?= $iScan ?>
      <span class="tb-scan-label">Scan QR</span>
    </a>
    <a href="/admin/"
       class="tb-btn tb-pill<?= $isTickets ? ' is-active' : '' ?>"
       <?= $isTickets ? 'aria-current="page"' : '' ?>>
      <?= $iTicket ?> Check-in
    </a>
  </nav>

  <div class="topbar-ctx">

    <?php if (!$__hideDbBackup): ?>
      <div class="tb-backup-actions" aria-label="Backup da base de dados">
        <a href="/admin/export-database.php"
           class="tb-btn tb-backup-btn"
           aria-label="Exportar backup SQL da base de dados"
           title="Descarregar backup SQL">
          <?= $iBackup ?>
          <span class="tb-backup-label">Exportar</span>
        </a>
        <a href="/admin/import-database.php"
           class="tb-btn tb-backup-btn"
           aria-label="Importar backup SQL ou SQLite"
           title="Restaurar backup SQL ou SQLite">
          <?= $iImport ?>
          <span class="tb-backup-label">Importar</span>
        </a>
      </div>
    <?php endif; ?>

    <a href="/admin/logout.php" class="tb-btn tb-icon-btn tb-logout" aria-label="Terminar sessão" title="Sair">
      <?= $iLogout ?>
      <span class="tb-logout-label">Sair</span>
    </a>

  </div>
</div>

<!-- Mobile: mesma ordem — Scan sempre ao centro -->
<nav class="bottom-tabs" aria-label="Navegação principal">

  <?php if ($__showAnalytics): ?>
  <a href="/admin/analytics.php"
     class="btab btab-analytics<?= $isAnalytics ? ' is-active' : '' ?>"
     <?= $isAnalytics ? 'aria-current="page"' : '' ?>>
    <?= $iChart ?>
    <span class="btab-label">Analytics</span>
  </a>
  <?php endif; ?>

  <a href="/admin/costs.php"
     class="btab<?= $isCosts ? ' is-active' : '' ?>"
     <?= $isCosts ? 'aria-current="page"' : '' ?>>
    <?= $iCosts ?>
    <span class="btab-label">Custos</span>
  </a>

  <a href="/admin/tasks.php"
     class="btab<?= $isTasks ? ' is-active' : '' ?>"
     <?= $isTasks ? 'aria-current="page"' : '' ?>>
    <?= $iTask ?>
    <span class="btab-label">Tarefas</span>
  </a>

  <a href="/admin/promotion.php"
     class="btab<?= $isPromotion ? ' is-active' : '' ?>"
     <?= $isPromotion ? 'aria-current="page"' : '' ?>>
    <?= $iPromo ?>
    <span class="btab-label">Promoção</span>
  </a>

  <a href="/admin/events.php"
     class="btab<?= $isEvents ? ' is-active' : '' ?>"
     <?= $isEvents ? 'aria-current="page"' : '' ?>>
    <?= $iEvents ?>
    <span class="btab-label">Eventos</span>
  </a>

  <a href="/admin/attendance.php"
     class="btab<?= $isAttendance ? ' is-active' : '' ?>"
     <?= $isAttendance ? 'aria-current="page"' : '' ?>>
    <?= $iPeople ?>
    <span class="btab-label">Presenças</span>
  </a>

  <a href="/admin/participants.php"
     class="btab<?= $isParticipants ? ' is-active' : '' ?>"
     <?= $isParticipants ? 'aria-current="page"' : '' ?>>
    <?= $iPeople ?>
    <span class="btab-label">Pessoas</span>
  </a>

  <a href="/admin/discount-codes.php"
     class="btab<?= $isCodes ? ' is-active' : '' ?>"
     <?= $isCodes ? 'aria-current="page"' : '' ?>>
    <?= $iCodes ?>
    <span class="btab-label">Códigos</span>
  </a>

  <a href="/admin/link-bookings.php"
     class="btab<?= $isLinks ? ' is-active' : '' ?>"
     <?= $isLinks ? 'aria-current="page"' : '' ?>>
    <?= $iLink ?>
    <span class="btab-label">Inscrições</span>
  </a>

  <a href="/admin/scan.php"
     class="btab btab-scan<?= $isScan ? ' is-active' : '' ?>"
     <?= $isScan ? 'aria-current="page"' : '' ?>
     aria-label="Scanner QR">
    <span class="btab-icon-wrap"><?= $iScan ?></span>
    <span class="btab-label btab-scan-sub">Scan</span>
  </a>

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
