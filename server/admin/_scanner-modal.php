<?php
declare(strict_types=1);
?>
<!-- QR SCANNER MODAL -->
<div class="modal-backdrop" id="scannerModal" role="dialog" aria-modal="true" aria-label="Scanner QR">
  <div class="modal">
    <button class="modal-close" id="closeScannerBtn" aria-label="Fechar">&times;</button>
    <p class="modal-title">Scan QR code</p>
    <div id="reader"></div>
    <div class="scan-result" id="scanResult"></div>
  </div>
</div>
