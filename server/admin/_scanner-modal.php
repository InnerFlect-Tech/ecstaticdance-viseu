<?php
declare(strict_types=1);
?>
<!-- QR SCANNER MODAL (atalho rápido; página completa em /admin/scan.php) -->
<div class="modal-backdrop" id="scannerModal" role="dialog" aria-modal="true" aria-label="Scanner QR">
  <div class="modal">
    <button class="modal-close" id="closeScannerBtn" aria-label="Fechar">&times;</button>
    <p class="modal-title">Scanner QR</p>
    <p class="modal-lead">Lê o código do bilhete. Para ecrã completo na porta, usa <a href="/admin/scan.php">Scanner QR</a>.</p>
    <input type="hidden" id="scanEventId" value="<?= isset($__scanEventId) ? (int) $__scanEventId : 0 ?>" />
    <div id="reader"></div>
    <div class="scan-result" id="scanResult" hidden aria-live="assertive"></div>
    <form id="scannerManualForm" class="scanner-manual-form">
      <label for="scannerManualCode">Código manual</label>
      <div class="scanner-manual-row">
        <input type="text" id="scannerManualCode" placeholder="UUID ou URL do bilhete" autocomplete="off" spellcheck="false" />
        <button type="submit" class="scanner-manual-submit">Validar</button>
      </div>
    </form>
  </div>
</div>
