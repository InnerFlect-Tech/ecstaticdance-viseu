<?php
declare(strict_types=1);
?>
<script>
(function () {
  const closeBtn   = document.getElementById('closeScannerBtn');
  const modal      = document.getElementById('scannerModal');
  const scanResult = document.getElementById('scanResult');
  if (!modal || !closeBtn || !scanResult) return;

  let scanner = null;
  let scanCooldown = false;

  document.querySelectorAll('#openScannerBtn, #openScannerBtnMobile').forEach(function (btn) {
    btn.addEventListener('click', startScanner);
  });
  closeBtn.addEventListener('click', stopScanner);
  modal.addEventListener('click', function (e) { if (e.target === modal) stopScanner(); });

  async function startScanner() {
    modal.classList.add('open');
    scanResult.style.display = 'none';
    scanResult.className = 'scan-result';

    if (!window.QrScanner) {
      const script = document.createElement('script');
      script.src = 'https://unpkg.com/qr-scanner@1/qr-scanner.umd.min.js';
      document.head.appendChild(script);
      await new Promise(function (res, rej) { script.onload = res; script.onerror = rej; });
    }

    const videoEl = document.createElement('video');
    const reader = document.getElementById('reader');
    reader.innerHTML = '';
    reader.appendChild(videoEl);

    scanner = new QrScanner(
      videoEl,
      function (result) { handleScan(result.data); },
      {
        highlightScanRegion: true,
        highlightCodeOutline: true,
        preferredCamera: 'environment',
      }
    );
    await scanner.start();
  }

  function stopScanner() {
    if (scanner) {
      scanner.stop();
      scanner.destroy();
      scanner = null;
    }
    const reader = document.getElementById('reader');
    if (reader) reader.innerHTML = '';
    modal.classList.remove('open');
  }

  async function handleScan(code) {
    if (scanCooldown || !code) return;
    scanCooldown = true;

    if (navigator.vibrate) navigator.vibrate(100);

    try {
      const res  = await fetch('/api/verify-ticket.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ code: code }),
      });
      const data = await res.json();

      if (data.ok) {
        showScanResult('ok',
          `<span class="scan-name">${escHtml(data.ticket.name)}</span>
           Entrada válida &nbsp;✓<br>
           <small style="opacity:.7">${escHtml(data.ticket.event_title)}</small>`
        );
        if (navigator.vibrate) navigator.vibrate([100, 50, 100]);
      } else {
        showScanResult('err',
          `<span class="scan-name">Inválido</span>${escHtml(data.error)}`
        );
        if (navigator.vibrate) navigator.vibrate([300]);
      }
    } catch (e) {
      showScanResult('err', 'Erro de rede. Verifica a ligação.');
    }

    setTimeout(function () { scanCooldown = false; }, 3000);
  }

  function showScanResult(type, html) {
    scanResult.className = 'scan-result ' + type;
    scanResult.innerHTML = html;
    scanResult.style.display = '';
    setTimeout(function () { scanResult.style.display = 'none'; }, 5000);
  }

  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.classList.contains('open')) stopScanner();
  });
})();
</script>
