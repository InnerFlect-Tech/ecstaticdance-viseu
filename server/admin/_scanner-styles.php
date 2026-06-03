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
  .modal-title { font-size: 1.2rem; font-weight: 300; color: var(--bone); margin-bottom: 0.5rem; }
  .modal-lead { font-size: 0.78rem; color: rgba(245,239,230,.45); line-height: 1.5; margin-bottom: 1rem; }
  .modal-lead a { color: var(--gold-l); }
  .modal-close { position: absolute; top: 1rem; right: 1rem; background: none; border: none;
                 color: rgba(245,239,230,.4); cursor: pointer; font-size: 1.2rem; line-height: 1; }
  .modal-close:hover { color: var(--bone); }
  #reader { width: 100%; background: #000; min-height: 280px; position: relative; }
  .scan-result { margin-top: 1rem; padding: 1.2rem; text-align: center; font-size: .9rem;
                 font-weight: 400; line-height: 1.5; border-radius: 8px; }
  .scan-result.ok  { background: rgba(45,106,79,.3); color: #40916c; border: 1px solid rgba(64,145,108,.4); }
  .scan-result.warn { background: rgba(212,168,90,.15); color: #d4a85a; border: 1px solid rgba(212,168,90,.35); }
  .scan-result.err { background: rgba(196,89,63,.2); color: #e07050; border: 1px solid rgba(196,89,63,.4); }
  .scan-result .scan-name { font-size: 1.1rem; display: block; margin-bottom: .3rem; font-weight: 400; }
  .scan-result .scan-meta { opacity: .75; }
  .scanner-manual-form { margin-top: 1rem; }
  .scanner-manual-form label {
    display: block; font-size: .62rem; letter-spacing: .1em; text-transform: uppercase;
    color: rgba(245,239,230,.4); margin-bottom: .4rem;
  }
  .scanner-manual-row { display: flex; gap: .5rem; }
  .scanner-manual-row input {
    flex: 1; min-width: 0; background: rgba(245,239,230,.06); border: 1px solid rgba(245,239,230,.16);
    color: var(--bone); padding: .5rem .6rem; border-radius: 6px; font-size: .82rem; font-family: ui-monospace, monospace;
  }
  .scanner-manual-submit {
    appearance: none; border: 1px solid rgba(64,145,108,.45); background: rgba(45,106,79,.25);
    color: #8fd4a8; padding: .5rem .75rem; border-radius: 6px; font-size: .68rem;
    letter-spacing: .08em; text-transform: uppercase; cursor: pointer; font-family: inherit;
  }
