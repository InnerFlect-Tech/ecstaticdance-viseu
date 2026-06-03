/**
 * Ecstatic Dance Viseu — admin QR check-in (camera + manual code).
 * Requires QrScanner UMD (self-hosted under /admin/assets/vendor/).
 */
(function (global) {
  'use strict';

  const WORKER_PATH = '/admin/assets/vendor/qr-scanner-worker.min.js';
  const LIB_PATH = '/admin/assets/vendor/qr-scanner.umd.min.js';

  /** @param {string} raw */
  function parseTicketCode(raw) {
    const s = String(raw || '').trim();
    const m = s.match(
      /[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i
    );
    return m ? m[0].toLowerCase() : '';
  }

  function escHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function vibrate(pattern) {
    if (global.navigator && global.navigator.vibrate) {
      global.navigator.vibrate(pattern);
    }
  }

  let libPromise = null;

  function loadQrLibrary() {
    if (global.QrScanner) {
      global.QrScanner.WORKER_PATH = WORKER_PATH;
      return Promise.resolve(global.QrScanner);
    }
    if (libPromise) return libPromise;
    libPromise = new Promise(function (resolve, reject) {
      const script = document.createElement('script');
      script.src = LIB_PATH;
      script.async = true;
      script.onload = function () {
        if (global.QrScanner) {
          global.QrScanner.WORKER_PATH = WORKER_PATH;
          resolve(global.QrScanner);
        } else {
          reject(new Error('QrScanner not loaded'));
        }
      };
      script.onerror = function () {
        reject(new Error('Failed to load QR library'));
      };
      document.head.appendChild(script);
    });
    return libPromise;
  }

  /**
   * @param {object} opts
   * @param {HTMLElement} opts.readerEl
   * @param {HTMLElement} opts.resultEl
   * @param {() => number} [opts.getEventId]
   * @param {(entry: object) => void} [opts.onHistory]
   * @param {() => void} [opts.onSuccess]
   */
  function createScannerController(opts) {
    const readerEl = opts.readerEl;
    const resultEl = opts.resultEl;
    const getEventId = opts.getEventId || function () { return 0; };
    const onHistory = opts.onHistory || function () {};
    const onSuccess = opts.onSuccess || function () {};

    /** @type {import('qr-scanner').default | null} */
    let scanner = null;
    let scanCooldown = false;
    let active = false;

    function showResult(type, html) {
      if (!resultEl) return;
      resultEl.className = 'scan-result ' + type;
      resultEl.innerHTML = html;
      resultEl.hidden = false;
    }

    function hideResult() {
      if (!resultEl) return;
      resultEl.hidden = true;
      resultEl.className = 'scan-result';
      resultEl.innerHTML = '';
    }

    async function verifyCode(raw) {
      const code = parseTicketCode(raw);
      if (!code) {
        showResult(
          'err',
          '<span class="scan-name">Código inválido</span>Não foi possível ler um bilhete válido.'
        );
        vibrate([200]);
        return;
      }

      if (scanCooldown) return;
      scanCooldown = true;
      vibrate(80);

      const payload = { code: code };
      const eventId = getEventId();
      if (eventId > 0) {
        payload.event_id = eventId;
      }

      try {
        const res = await fetch('/api/verify-ticket.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify(payload),
        });
        const data = await res.json();

        if (data.ok && data.ticket) {
          const t = data.ticket;
          const amount =
            Number(t.amount_paid) > 0
              ? '€' + Number(t.amount_paid).toFixed(2).replace('.', ',')
              : 'Gratuito';
          showResult(
            'ok',
            '<span class="scan-name">' +
              escHtml(t.name) +
              '</span>' +
              'Entrada válida ✓<br>' +
              '<small class="scan-meta">' +
              escHtml(t.event_title) +
              ' · ' +
              amount +
              '</small>'
          );
          vibrate([100, 50, 100]);
          onHistory({
            at: new Date(),
            ok: true,
            name: t.name,
            code: code,
            event: t.event_title,
          });
          onSuccess();
        } else {
          const err = data.error || 'Bilhete inválido.';
          const isUsed = res.status === 409 && /já utilizado/i.test(err);
          const isWrongEvent = res.status === 409 && /não para o evento/i.test(err);
          const cls = isUsed || isWrongEvent ? 'warn' : 'err';
          showResult(
            cls,
            '<span class="scan-name">' +
              (isUsed ? 'Já entrou' : isWrongEvent ? 'Outro evento' : 'Inválido') +
              '</span>' +
              escHtml(err)
          );
          vibrate(isUsed ? [120, 80, 120] : [280]);
          onHistory({
            at: new Date(),
            ok: false,
            name: '',
            code: code,
            event: err,
          });
        }
      } catch {
        showResult('err', '<span class="scan-name">Erro de rede</span>Verifica a ligação e tenta outra vez.');
        vibrate([300]);
      }

      global.setTimeout(function () {
        scanCooldown = false;
      }, 2500);
    }

    async function start() {
      if (active || !readerEl) return;
      hideResult();
      readerEl.innerHTML =
        '<p class="scan-loading">A pedir acesso à câmara…</p>';

      try {
        const QrScanner = await loadQrLibrary();
        const videoEl = document.createElement('video');
        videoEl.setAttribute('playsinline', 'true');
        videoEl.setAttribute('muted', 'true');
        readerEl.innerHTML = '';
        readerEl.appendChild(videoEl);

        scanner = new QrScanner(
          videoEl,
          function (result) {
            verifyCode(result.data);
          },
          {
            highlightScanRegion: true,
            highlightCodeOutline: true,
            preferredCamera: 'environment',
            maxScansPerSecond: 4,
          }
        );
        await scanner.start();
        active = true;
      } catch (e) {
        readerEl.innerHTML =
          '<p class="scan-error">Não foi possível iniciar a câmara. Usa a entrada manual abaixo ou permite o acesso à câmara nas definições do browser.</p>';
        console.error(e);
      }
    }

    function stop() {
      active = false;
      if (scanner) {
        scanner.stop();
        scanner.destroy();
        scanner = null;
      }
      if (readerEl) {
        readerEl.innerHTML = '';
      }
    }

    return {
      start: start,
      stop: stop,
      verifyCode: verifyCode,
      hideResult: hideResult,
    };
  }

  /** Modal scanner (legacy quick access) */
  function initModalScanner() {
    const modal = document.getElementById('scannerModal');
    const closeBtn = document.getElementById('closeScannerBtn');
    const readerHost = document.getElementById('reader');
    const resultEl = document.getElementById('scanResult');
    if (!modal || !closeBtn || !readerHost || !resultEl) return;

    const ctrl = createScannerController({
      readerEl: readerHost,
      resultEl: resultEl,
      getEventId: function () {
        const el = document.getElementById('scanEventId');
        return el ? parseInt(String(el.value), 10) || 0 : 0;
      },
    });

    function open() {
      modal.classList.add('open');
      ctrl.start();
    }

    function close() {
      ctrl.stop();
      modal.classList.remove('open');
    }

    document.querySelectorAll('#openScannerQuick').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        open();
      });
    });
    closeBtn.addEventListener('click', close);
    modal.addEventListener('click', function (e) {
      if (e.target === modal) close();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.classList.contains('open')) close();
    });

    const manualForm = document.getElementById('scannerManualForm');
    if (manualForm) {
      manualForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const inp = document.getElementById('scannerManualCode');
        if (inp) {
          ctrl.verifyCode(inp.value);
          inp.value = '';
        }
      });
    }
  }

  /** Full-page scanner at /admin/scan.php */
  function initPageScanner() {
    const root = document.getElementById('scanPage');
    if (!root) return;

    const readerEl = document.getElementById('scanReader');
    const resultEl = document.getElementById('scanResult');
    const historyList = document.getElementById('scanHistory');
    const eventSelect = document.getElementById('scanEventSelect');
    const manualForm = document.getElementById('scanManualForm');
    const restartBtn = document.getElementById('scanRestartCamera');

    const history = [];
    const maxHistory = 8;

    function renderHistory() {
      if (!historyList) return;
      if (history.length === 0) {
        historyList.innerHTML =
          '<li class="scan-history-empty">Ainda sem leituras nesta sessão.</li>';
        return;
      }
      historyList.innerHTML = history
        .map(function (h) {
          const time = h.at.toLocaleTimeString('pt-PT', {
            hour: '2-digit',
            minute: '2-digit',
          });
          const cls = h.ok ? 'ok' : 'bad';
          const label = h.ok ? escHtml(h.name) : escHtml(h.event);
          return (
            '<li class="scan-history-item ' +
            cls +
            '"><span class="scan-history-time">' +
            time +
            '</span> ' +
            label +
            '</li>'
          );
        })
        .join('');
    }

    const ctrl = createScannerController({
      readerEl: readerEl,
      resultEl: resultEl,
      getEventId: function () {
        return eventSelect ? parseInt(String(eventSelect.value), 10) || 0 : 0;
      },
      onHistory: function (entry) {
        history.unshift(entry);
        if (history.length > maxHistory) history.pop();
        renderHistory();
      },
      onSuccess: function () {
        const statIn = document.getElementById('scanStatIn');
        if (statIn) {
          const n = parseInt(statIn.textContent, 10) || 0;
          statIn.textContent = String(n + 1);
        }
      },
    });

    ctrl.start();

    if (manualForm) {
      manualForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const inp = document.getElementById('scanManualCode');
        if (inp && inp.value.trim()) {
          ctrl.verifyCode(inp.value);
          inp.value = '';
          inp.focus();
        }
      });
    }

    if (restartBtn) {
      restartBtn.addEventListener('click', function () {
        ctrl.stop();
        ctrl.start();
      });
    }

    if (eventSelect) {
      eventSelect.addEventListener('change', function () {
        const id = eventSelect.value;
        if (id) {
          const u = new URL(global.location.href);
          u.searchParams.set('event_id', id);
          global.history.replaceState(null, '', u.pathname + u.search);
        }
      });
    }

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        ctrl.stop();
      } else {
        ctrl.start();
      }
    });
  }

  global.EdvAdminScanner = {
    parseTicketCode: parseTicketCode,
    initModalScanner: initModalScanner,
    initPageScanner: initPageScanner,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initModalScanner();
      initPageScanner();
    });
  } else {
    initModalScanner();
    initPageScanner();
  }
})(window);
