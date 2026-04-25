/* ============================================================
   ECSTATIC DANCE VISEU — Bilhetes JavaScript
   Handles: booking form (bilhetes.html) + ticket display (confirmacao.html)
   ============================================================ */

const API_BASE = '/api';

/* ─────────────────────────────────────────────
   BOOKING PAGE — bilhetes.html
───────────────────────────────────────────── */
const bookingForm = document.getElementById('bookingForm');
if (bookingForm) {
  initBookingPage();
}

async function initBookingPage() {
  const eventInfo   = document.getElementById('eventInfo');
  const amountGroup = document.getElementById('amountGroup');
  const freeBadge   = document.getElementById('freeBadge');
  const paidNote    = document.getElementById('paidNote');
  const freeNote    = document.getElementById('freeNote');
  const submitBtn   = document.getElementById('submitBtn');
  const amountRange = document.getElementById('amountRange');
  const amountDisp  = document.getElementById('amountDisplay');

  let currentEvent = null;

  // ── Load current event ──
  try {
    const res = await fetch(`${API_BASE}/get-events.php`);
    const data = await res.json();

    if (!data.ok || !data.event) {
      eventInfo.innerHTML = `
        <div class="event-no-active">
          <p>Não há nenhum evento activo neste momento.<br>
          Acompanha-nos para saber das próximas datas.</p>
          <a href="https://instagram.com/ecstaticdanceviseu" target="_blank" rel="noopener">Seguir no Instagram</a>
        </div>`;
      return;
    }

    currentEvent = data.event;
    renderEventInfo(eventInfo, currentEvent);

    // Configure form based on event type
    if (currentEvent.type === 'paid') {
      amountGroup.style.display = '';
      paidNote.style.display = '';
    } else {
      freeBadge.style.display = '';
      freeNote.style.display = '';
    }

    submitBtn.disabled = false;

  } catch {
    eventInfo.innerHTML = `
      <div class="event-no-active">
        <p>Não foi possível carregar a informação do evento.<br>
        Tenta novamente ou <a href="mailto:info@ecstaticdanceviseu.pt">escreve-nos</a>.</p>
      </div>`;
    return;
  }

  // ── Sliding scale range ──
  if (amountRange && amountDisp) {
    amountRange.addEventListener('input', () => {
      amountDisp.textContent = amountRange.value;
    });
  }

  // ── Form submit ──
  bookingForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    const errorEl = document.getElementById('formError');
    errorEl.style.display = 'none';

    const name  = document.getElementById('name').value.trim();
    const email = document.getElementById('email').value.trim();
    const phone = document.getElementById('phone').value.trim();
    const agree = document.getElementById('agree').checked;

    if (!name || !email || !phone) {
      showError(errorEl, 'Por favor preenche todos os campos.');
      return;
    }
    if (!agree) {
      showError(errorEl, 'Tens de aceitar os acordos da pista para reservar.');
      return;
    }
    if (!isValidEmail(email)) {
      showError(errorEl, 'O email introduzido não é válido.');
      return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'A processar…';

    try {
      if (currentEvent.type === 'paid') {
        await handlePaidBooking(name, email, phone, currentEvent.id);
      } else {
        await handleFreeBooking(name, email, phone, currentEvent.id, errorEl);
      }
    } catch {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Reservar lugar';
      showError(errorEl, 'Ocorreu um erro. Por favor tenta novamente ou escreve para info@ecstaticdanceviseu.pt');
    }
  });
}

async function handlePaidBooking(name, email, phone, eventId) {
  const amount = parseInt(document.getElementById('amountRange').value, 10) || 30;

  const res = await fetch(`${API_BASE}/create-checkout.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ event_id: eventId, name, email, phone, amount }),
  });

  const data = await res.json();
  if (!data.ok || !data.url) {
    throw new Error(data.error || 'Erro ao criar sessão de pagamento.');
  }

  window.location.href = data.url;
}

async function handleFreeBooking(name, email, phone, eventId, errorEl) {
  const res = await fetch(`${API_BASE}/register-free.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ event_id: eventId, name, email, phone }),
  });

  const data = await res.json();
  if (!data.ok) {
    showError(errorEl, data.error || 'Não foi possível completar a reserva. Tenta novamente.');
    document.getElementById('submitBtn').disabled = false;
    document.getElementById('submitBtn').textContent = 'Reservar lugar';
    return;
  }

  window.location.href = `confirmacao.html?code=${encodeURIComponent(data.ticket_id)}`;
}

function renderEventInfo(container, ev) {
  const dateObj = new Date(ev.date);
  const day     = dateObj.getDate();
  const month   = dateObj.toLocaleDateString('pt-PT', { month: 'long', year: 'numeric' });
  const time    = ev.time_start ? ev.time_start.slice(0, 5) + '–' + ev.time_end.slice(0, 5) : '19h00–22h30';
  const doors   = ev.doors_open ? ev.doors_open.slice(0, 5) : '18h30';

  const capacity  = parseInt(ev.capacity, 10) || 0;
  const sold      = parseInt(ev.tickets_sold, 10) || 0;
  const remaining = Math.max(0, capacity - sold);
  const fillPct   = capacity > 0 ? Math.min(100, Math.round((sold / capacity) * 100)) : 0;

  const priceLabel = ev.type === 'paid'
    ? `A partir de €${parseInt(ev.min_price, 10) || 25}`
    : 'Gratuito';

  container.innerHTML = `
    <div class="event-info-card reveal">
      <div class="event-info-date">${day}</div>
      <div class="event-info-month">${capitalise(month)}</div>
      <div class="event-info-title">${escHtml(ev.title)}</div>
      <p class="event-info-desc">${escHtml(ev.description || '')}</p>

      <div class="event-meta-grid">
        <div class="event-meta-item">
          <label>Horário</label>
          <strong>${time}</strong>
        </div>
        <div class="event-meta-item">
          <label>Abertura</label>
          <strong>${doors}</strong>
        </div>
        <div class="event-meta-item">
          <label>Preço</label>
          <strong>${priceLabel}</strong>
        </div>
        <div class="event-meta-item">
          <label>Local</label>
          <strong>${escHtml(ev.location || 'Viseu')}</strong>
        </div>
      </div>

      ${capacity > 0 ? `
      <div class="event-capacity-bar">
        <label>Disponibilidade</label>
        <div class="capacity-track">
          <div class="capacity-fill" style="width:${fillPct}%"></div>
        </div>
        <p class="capacity-text">${remaining} lugar${remaining !== 1 ? 'es' : ''} restante${remaining !== 1 ? 's' : ''}</p>
      </div>` : ''}
    </div>`;
}


/* ─────────────────────────────────────────────
   CONFIRMATION PAGE — confirmacao.html
───────────────────────────────────────────── */
const ticketLoading = document.getElementById('ticketLoading');
if (ticketLoading) {
  initConfirmationPage();
}

async function initConfirmationPage() {
  const loading = document.getElementById('ticketLoading');
  const content = document.getElementById('ticketContent');
  const error   = document.getElementById('ticketError');

  const params    = new URLSearchParams(window.location.search);
  const code      = params.get('code');
  const sessionId = params.get('session_id');

  if (!code && !sessionId) {
    loading.style.display = 'none';
    error.style.display = '';
    return;
  }

  try {
    let ticket;

    if (sessionId) {
      // Paid ticket — verify Stripe session
      const res = await fetch(`${API_BASE}/create-checkout.php?verify=${encodeURIComponent(sessionId)}`);
      const data = await res.json();
      if (!data.ok) throw new Error(data.error);
      ticket = data.ticket;
    } else {
      // Free ticket — load by code
      const res = await fetch(`${API_BASE}/verify-ticket.php?code=${encodeURIComponent(code)}&preview=1`);
      const data = await res.json();
      if (!data.ok) throw new Error(data.error);
      ticket = data.ticket;
    }

    renderTicket(ticket);
    loading.style.display = 'none';
    content.style.display = '';

  } catch {
    loading.style.display = 'none';
    error.style.display = '';
  }
}

function renderTicket(ticket) {
  const qrImg     = document.getElementById('ticketQR');
  const detailsEl = document.getElementById('ticketDetails');
  const downloadA = document.getElementById('ticketDownload');

  const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?data=${encodeURIComponent(ticket.id)}&size=300x300&margin=10`;
  qrImg.src = qrUrl;
  qrImg.alt = `QR code do bilhete ${ticket.id}`;

  const dateObj  = new Date(ticket.event_date);
  const dateFmt  = dateObj.toLocaleDateString('pt-PT', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  const amountFmt = ticket.amount_paid > 0
    ? `€${parseFloat(ticket.amount_paid).toFixed(2)}`
    : 'Gratuito';

  detailsEl.innerHTML = `
    <li><span class="tdl-label">Evento</span><span class="tdl-value">${escHtml(ticket.event_title)}</span></li>
    <li><span class="tdl-label">Data</span><span class="tdl-value">${capitalise(dateFmt)}</span></li>
    <li><span class="tdl-label">Nome</span><span class="tdl-value">${escHtml(ticket.name)}</span></li>
    <li><span class="tdl-label">Valor</span><span class="tdl-value">${amountFmt}</span></li>
    <li><span class="tdl-label">Referência</span><span class="tdl-value ticket-id">${escHtml(ticket.id)}</span></li>`;

  // Download link — opens QR image in a new tab (user can long-press to save on mobile)
  downloadA.href = qrUrl;
  downloadA.setAttribute('target', '_blank');
  downloadA.setAttribute('rel', 'noopener');
  downloadA.removeAttribute('download');
  downloadA.textContent = 'Ver / guardar QR code';
}


/* ─────────────────────────────────────────────
   UTILITIES
───────────────────────────────────────────── */
function showError(el, msg) {
  el.textContent = msg;
  el.style.display = '';
  el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function escHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function capitalise(str) {
  return str.charAt(0).toUpperCase() + str.slice(1);
}
