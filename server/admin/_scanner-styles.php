<?php
declare(strict_types=1);
/* QR scanner modal — include inside <style> */
?>
  /* ── QR SCANNER MODAL ── */
  .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(14,11,9,.85);
                    z-index: 100; align-items: center; justify-content: center; }
  .modal-backdrop.open { display: flex; }
  .modal { background: var(--dark-m); border: 1px solid rgba(245,239,230,.1);
           width: 100%; max-width: 480px; padding: 2rem; position: relative; }
  .modal-title { font-size: 1.2rem; font-weight: 300; color: var(--bone); margin-bottom: 1.5rem; }
  .modal-close { position: absolute; top: 1rem; right: 1rem; background: none; border: none;
                 color: rgba(245,239,230,.4); cursor: pointer; font-size: 1.2rem; line-height: 1; }
  .modal-close:hover { color: var(--bone); }
  #reader { width: 100%; background: #000; min-height: 280px; position: relative; }
  .scan-result { margin-top: 1.5rem; padding: 1.2rem; text-align: center; font-size: .9rem;
                 font-weight: 400; display: none; line-height: 1.5; }
  .scan-result.ok  { background: rgba(45,106,79,.3); color: #40916c; border: 1px solid rgba(64,145,108,.4); }
  .scan-result.err { background: rgba(196,89,63,.2); color: #e07050; border: 1px solid rgba(196,89,63,.4); }
  .scan-result .scan-name { font-size: 1.1rem; display: block; margin-bottom: .3rem; }
