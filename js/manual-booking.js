import { renderPaymentInstructions } from './payment-instructions.js'
import {
  DEFAULT_TICKET_EUR,
  TICKET_MAX_EUR,
  TICKET_STEP,
  isEarlyBird,
  snapTicketEur,
  ticketMinEur,
} from './pricing.js'

const DEFAULT_EVENT_SLUG = 'edv-2026-05-23'
const INFO_EMAIL = 'info@ecstaticdanceviseu.pt'
/** Extra fixo: jantar no local (reserva manual em bilhetes.html) */
const DINNER_EUR = 10

function apiBase() {
  const raw = import.meta.env.VITE_API_BASE
  if (raw && String(raw).trim() !== '') {
    return String(raw).replace(/\/$/, '')
  }
  return ''
}

/**
 * @param {string} path
 */
function apiUrl(path) {
  const p = path.startsWith('/') ? path : '/' + path
  return apiBase() + p
}

/**
 * Lê o corpo como texto e faz parse JSON (evita falha com corpo vazio ou HTML; ver MDN: Response, json()).
 * @param {Response} res
 * @returns {Promise<any>}
 */
async function parseLinkApiJson(res) {
  const raw = await res.text()
  const status = res.status
  const url = typeof res.url === 'string' ? res.url : ''
  if (!raw.trim()) {
    if (status === 502 || status === 503 || status === 504) {
      throw new Error(
        `API inacessível (HTTP ${status}). Em desenvolvimento, inicia o PHP na porta 8080: deixa um terminal com «php -S 127.0.0.1:8080 -t server» a correr e usa «npm run dev:local» (Vite + PHP). Cria também server/api/config.php a partir de config.example.php.`
      )
    }
    throw new Error(
      status >= 500
        ? `Resposta vazia do servidor (HTTP ${status})${url ? ` — ${url}` : ''}. Verifica se a API PHP está no ar, se existe config.php e a base de dados.`
        : `Resposta vazia (HTTP ${status}).`
    )
  }
  try {
    return JSON.parse(raw)
  } catch {
    if (/^\s*</.test(raw)) {
      throw new Error(
        'A API devolveu HTML em vez de JSON (404 ou rota em falta). Em dev, confirma o proxy /api do Vite e o servidor PHP.'
      )
    }
    throw new Error('Resposta inválida do servidor (não é JSON).')
  }
}

function parseMoney(s) {
  const t = String(s).replace(/\s/g, '').replace(',', '.')
  const n = parseFloat(t)
  return Number.isFinite(n) ? n : 0
}

function formatEur(n) {
  return (
    n.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €'
  )
}

function getEl(id) {
  const el = document.getElementById(id)
  if (!el) throw new Error('Missing #' + id)
  return el
}

function manualLangRoot() {
  return document.getElementById('reserva-manual')
}

function isManualPt() {
  const root = manualLangRoot()
  if (root) {
    return (
      root.classList.contains('lang-pt') || !root.classList.contains('lang-en')
    )
  }
  return true
}

function setManualSectionLang(lang) {
  const root = manualLangRoot()
  if (!root) return
  root.classList.remove('lang-pt', 'lang-en')
  root.classList.add(lang === 'en' ? 'lang-en' : 'lang-pt')
  try {
    localStorage.setItem('edv_lang', lang === 'en' ? 'en' : 'pt')
  } catch {
    // ignore
  }
}

function initManualLang() {
  const root = manualLangRoot()
  if (!root) return
  let stored = 'pt'
  try {
    stored = localStorage.getItem('edv_lang') === 'en' ? 'en' : 'pt'
  } catch {
    // ignore
  }
  setManualSectionLang(stored)
  const ptBtn = document.getElementById('lb_lang_pt')
  const enBtn = document.getElementById('lb_lang_en')
  if (ptBtn) {
    ptBtn.addEventListener('click', () => {
      setManualSectionLang('pt')
      applyTicketPricingToDom()
      if (state.paymentMethod) paintInstructions()
    })
  }
  if (enBtn) {
    enBtn.addEventListener('click', () => {
      setManualSectionLang('en')
      applyTicketPricingToDom()
      if (state.paymentMethod) paintInstructions()
    })
  }
}

const state = {
  registrationId: null,
  paymentRef: null,
  ticketEur: DEFAULT_TICKET_EUR,
  dinnerEur: 0,
  totalEur: DEFAULT_TICKET_EUR,
  paymentMethod: 'mbway',
  heardFrom: 'instagram',
}

function paintInstructions() {
  const box = document.getElementById('lb_payment_instructions')
  if (!box || !state.paymentRef) return
  renderPaymentInstructions(
    state.paymentMethod,
    {
      paymentRef: state.paymentRef,
      totalLabel: formatEur(state.totalEur),
      infoEmail: INFO_EMAIL,
    },
    box
  )
}

function showStep1() {
  getEl('lb_step_1').hidden = false
  getEl('lb_step_2').hidden = true
}

function showStep2() {
  getEl('lb_step_1').hidden = true
  getEl('lb_step_2').hidden = false
  for (const el of document.querySelectorAll('.js-summary-ref')) {
    el.textContent = state.paymentRef
  }
  for (const el of document.querySelectorAll('.js-summary-total')) {
    el.textContent = formatEur(state.totalEur)
  }
  paintInstructions()
}

function setHeardOtherVisible() {
  const sel = getEl('lb_heard_from')
  const other = getEl('lb_heard_other_wrap')
  const v = sel.value
  other.hidden = v !== 'other'
}

const TIER_PHRASES = [
  {
    max: 35,
    pt: 'Entrada acessível — o essencial para o evento acontecer.',
    en: 'Accessible entry — the baseline that keeps the event running.',
  },
  {
    max: 50,
    pt: 'Contribuição equilibrada — fazes parte com naturalidade.',
    en: 'A balanced choice — you meet the event at an honest, sustainable level.',
  },
  {
    max: 75,
    pt: 'Um valor que sustém o projeto, o espaço e o convidado que vem a seguir.',
    en: 'This level helps sustain the space, the guest artists, and the next person in the circle.',
  },
  {
    max: 110,
    pt: 'Generosidade que abre o círculo a mais pessoas — muito obrigada.',
    en: 'Generous support that keeps the door open for more dancers — deep thanks.',
  },
  {
    max: 160,
    pt: 'Apoio forte — ajudas de forma muito concreta a manter a escala acessível.',
    en: 'Strong support — you concretely help keep the low end of the scale possible.',
  },
  {
    max: 999,
    pt: 'Contribuição de apoio — tornas o sliding scale possível para toda a gente.',
    en: 'Patron-style support — you help make the whole sliding scale system work.',
  },
]

function phraseForAmount(euros) {
  for (const row of TIER_PHRASES) {
    if (euros <= row.max) return row
  }
  return TIER_PHRASES[TIER_PHRASES.length - 1]
}

let lastTicketTierForAnim = /** @type {number | null} */ (null)

function setRangeTrackPct(ticket) {
  const range = document.getElementById('lb_ticket_range')
  if (range) {
    const min = parseFloat(String(range.min)) || ticketMinEur()
    const max = parseFloat(String(range.max)) || TICKET_MAX_EUR
    const span = max - min
    const pct = span > 0 ? ((ticket - min) / span) * 100 : 0
    const clamped = Math.min(100, Math.max(0, pct))
    range.style.setProperty('--range-pct', `${clamped}%`)
  }
}

function playTierChangeAnimation(ticket) {
  const out = document.getElementById('lb_ticket_amount_out')
  if (out) {
    out.classList.remove('amount-value--pop')
    void out.offsetWidth
    out.classList.add('amount-value--pop')
  }
  const box = document.getElementById('lb_ticket_tier_phrase')
  if (box) {
    const { pt, en } = phraseForAmount(ticket)
    const ptEl = box.querySelector('.lang-pt')
    const enEl = box.querySelector('.lang-en')
    if (ptEl) ptEl.textContent = pt
    if (enEl) enEl.textContent = en
    box.classList.remove('links-tier-phrase--flash')
    void box.offsetWidth
    box.classList.add('links-tier-phrase--flash')
  }
}

function recalcTotals() {
  const range = document.getElementById('lb_ticket_range')
  const ticketHidden = getEl('lb_ticket_euros')
  const out = document.getElementById('lb_ticket_amount_out')
  const dinner = getEl('lb_dinner_euros')
  const totalOut = getEl('lb_total_euros_out')
  let t = DEFAULT_TICKET_EUR
  if (range) {
    t = parseFloat(String(range.value))
    if (!Number.isFinite(t)) t = DEFAULT_TICKET_EUR
    t = snapTicketEur(t)
    range.value = String(t)
    ticketHidden.value = String(t)
    if (out) out.textContent = String(Math.round(t))
  } else {
    t = Math.max(0, parseMoney(ticketHidden.value) || 0)
  }
  state.ticketEur = t
  const incl = document.getElementById('lb_dinner_incl')
  if (incl) {
    state.dinnerEur = incl.checked ? DINNER_EUR : 0
    dinner.value = String(state.dinnerEur)
  } else {
    state.dinnerEur = Math.max(0, parseMoney(dinner.value) || 0)
  }
  state.totalEur = state.ticketEur + state.dinnerEur
  totalOut.textContent = formatEur(state.totalEur)
  const detail = document.getElementById('lb_total_detail_line')
  if (detail) {
    const pt = detail.querySelector('.lang-pt')
    const en = detail.querySelector('.lang-en')
    if (pt && en) {
      if (state.dinnerEur > 0) {
        pt.textContent = `Bilhete ${String(Math.round(state.ticketEur))}€ + jantar ${DINNER_EUR}€`
        en.textContent = `Ticket €${String(Math.round(state.ticketEur))} + dinner €${DINNER_EUR}`
        detail.removeAttribute('hidden')
      } else {
        detail.setAttribute('hidden', '')
      }
    }
  }
  const dbox = document.querySelector('.links-dinner-box')
  if (dbox) {
    dbox.classList.toggle('links-dinner-box--active', state.dinnerEur > 0)
  }
  setRangeTrackPct(t)
  if (lastTicketTierForAnim === null || lastTicketTierForAnim !== t) {
    lastTicketTierForAnim = t
    playTierChangeAnimation(t)
  }
}

function applyTicketPricingToDom() {
  const range = document.getElementById('lb_ticket_range')
  if (!range) return
  const min = ticketMinEur()
  const max = TICKET_MAX_EUR
  range.min = String(min)
  range.max = String(max)
  range.step = String(TICKET_STEP)
  range.setAttribute(
    'aria-label',
    `Sliding scale: ${min}–${max} €, step ${TICKET_STEP}`
  )
  const raw = parseFloat(String(range.value))
  const v = snapTicketEur(Number.isFinite(raw) ? raw : DEFAULT_TICKET_EUR)
  range.value = String(v)
  getEl('lb_ticket_euros').value = String(v)
  const out = document.getElementById('lb_ticket_amount_out')
  if (out) out.textContent = String(Math.round(v))

  const minEl = document.getElementById('lb_ticket_range_min_lbl')
  if (minEl) {
    const pt = minEl.querySelector('.lang-pt')
    const en = minEl.querySelector('.lang-en')
    if (isEarlyBird()) {
      if (pt) pt.textContent = '20€ — early bird'
      if (en) en.textContent = '€20 — early bird'
    } else {
      if (pt) pt.textContent = '25€ — mínimo'
      if (en) en.textContent = '€25 — minimum'
    }
  }

}

function wireTotals() {
  const form = getEl('lb_booking_form')
  if (form.dataset.totalsWired === '1') return
  form.dataset.totalsWired = '1'
  const range = document.getElementById('lb_ticket_range')
  const dinner = getEl('lb_dinner_euros')
  const incl = document.getElementById('lb_dinner_incl')
  range?.addEventListener('input', recalcTotals)
  range?.addEventListener('change', recalcTotals)
  incl?.addEventListener('change', () => {
    if (!incl.checked) {
      const note = document.getElementById('lb_dinner_note')
      if (note) note.value = ''
      const wrap = document.getElementById('lb_dinner_note_wrap')
      if (wrap) wrap.setAttribute('hidden', '')
    } else {
      const wrap = document.getElementById('lb_dinner_note_wrap')
      if (wrap) wrap.removeAttribute('hidden')
    }
    recalcTotals()
  })
  recalcTotals()
}

function onPaymentChange() {
  const radios = document.querySelectorAll('input[name="lb_payment_method"]')
  for (const r of radios) {
    if (r.checked) {
      state.paymentMethod = r.value
      break
    }
  }
  if (!getEl('lb_step_2').hidden) paintInstructions()
}

async function onSubmitStep1(e) {
  e.preventDefault()
  const err = getEl('lb_form_error')
  err.textContent = ''
  const name = getEl('lb_name').value.trim()
  const email = getEl('lb_email').value.trim()
  const phone = getEl('lb_phone').value.trim()
  const dinnerNote = getEl('lb_dinner_note').value.trim()
  const heard = getEl('lb_heard_from').value
  const heardOther = getEl('lb_heard_other').value.trim()
  onPaymentChange()
  if (heard === 'other' && heardOther === '') {
    const isPt = isManualPt()
    err.textContent = isPt
      ? 'Indica o texto em «Outro».'
      : 'Please add details for “Other”.'
    return
  }
  if (state.totalEur <= 0) {
    const isPt = isManualPt()
    err.textContent = isPt ? 'O total deve ser maior que zero.' : 'Total must be greater than zero.'
    return
  }
  getEl('lb_step1_submit').disabled = true
  try {
    const res = await fetch(apiUrl('/api/save-link-booking.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        name,
        email,
        phone,
        ticket_euros: state.ticketEur,
        total_euros: state.totalEur,
        dinner_note: dinnerNote,
        payment_method: state.paymentMethod,
        heard_from: heard,
        heard_other: heard === 'other' ? heardOther : '',
        event_slug: getEl('lb_event_slug').value || DEFAULT_EVENT_SLUG,
      }),
    })
    const data = await parseLinkApiJson(res)
    if (!res.ok || !data.ok) {
      throw new Error(data.error || 'Erro a gravar.')
    }
    state.registrationId = data.registration_id
    state.paymentRef = data.payment_ref
    showStep2()
  } catch (ex) {
    err.textContent = ex instanceof Error ? ex.message : 'Erro.'
  } finally {
    getEl('lb_step1_submit').disabled = false
  }
}

async function onUploadProof() {
  const err = getEl('lb_step2_error')
  const ok = getEl('lb_step2_success')
  err.textContent = ''
  ok.textContent = ''
  const fileInput = getEl('lb_proof')
  if (!fileInput.files || !fileInput.files[0]) {
    const isPt = isManualPt()
    err.textContent = isPt ? 'Escolhe um ficheiro (PDF ou imagem).' : 'Choose a file (PDF or image).'
    return
  }
  if (!state.registrationId) return
  getEl('lb_btn_upload').disabled = true
  const fd = new FormData()
  fd.append('registration_id', state.registrationId)
  fd.append('proof', fileInput.files[0])
  try {
    const res = await fetch(apiUrl('/api/complete-link-booking.php'), {
      method: 'POST',
      body: fd,
    })
    const data = await parseLinkApiJson(res)
    if (!res.ok || !data.ok) {
      throw new Error(data.error || 'Erro no envio.')
    }
    const isPt = isManualPt()
    ok.textContent = isPt
      ? (data.message || 'Obrigado! Recebemos o comprovativo.')
      : (data.message || 'Thank you — proof received.')
    getEl('lb_step_2_in').hidden = true
  } catch (ex) {
    err.textContent = ex instanceof Error ? ex.message : 'Erro.'
  } finally {
    getEl('lb_btn_upload').disabled = false
  }
}

async function onEmailLater() {
  const err = getEl('lb_step2_error')
  const ok = getEl('lb_step2_success')
  err.textContent = ''
  ok.textContent = ''
  if (!state.registrationId) return
  getEl('lb_btn_later').disabled = true
  try {
    const res = await fetch(apiUrl('/api/complete-link-booking.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ registration_id: state.registrationId, email_later: true }),
    })
    const data = await parseLinkApiJson(res)
    if (!res.ok || !data.ok) {
      throw new Error(data.error || 'Erro.')
    }
    const isPt = isManualPt()
    ok.textContent = isPt
      ? (data.message || 'Combinado. Envia o comprovativo para ' + INFO_EMAIL + ' quando puderes.')
      : (data.message || "Got it. Send the proof to " + INFO_EMAIL + " when you can.")
    getEl('lb_step_2_in').hidden = true
  } catch (ex) {
    err.textContent = ex instanceof Error ? ex.message : 'Erro.'
  } finally {
    getEl('lb_btn_later').disabled = false
  }
}

function init() {
  if (!document.getElementById('lb_booking_form')) return
  initManualLang()
  applyTicketPricingToDom()
  getEl('lb_booking_form').addEventListener('submit', onSubmitStep1)
  getEl('lb_heard_from').addEventListener('change', setHeardOtherVisible)
  setHeardOtherVisible()
  wireTotals()
  document.querySelectorAll('input[name="lb_payment_method"]').forEach((r) => {
    r.addEventListener('change', onPaymentChange)
  })
  onPaymentChange()
  getEl('lb_btn_upload').addEventListener('click', onUploadProof)
  getEl('lb_btn_later').addEventListener('click', onEmailLater)
  document.getElementById('lb_start_over')?.addEventListener('click', (ev) => {
    ev.preventDefault()
    state.registrationId = null
    state.paymentRef = null
    getEl('lb_step_2_in').hidden = false
    getEl('lb_step2_success').textContent = ''
    getEl('lb_booking_form').reset()
    getEl('lb_heard_other_wrap').hidden = getEl('lb_heard_from').value !== 'other'
    const dIncl = document.getElementById('lb_dinner_incl')
    if (dIncl) dIncl.checked = false
    const dNoteWrap = document.getElementById('lb_dinner_note_wrap')
    if (dNoteWrap) dNoteWrap.setAttribute('hidden', '')
    if (getEl('lb_dinner_euros')) getEl('lb_dinner_euros').value = '0'
    getEl('lb_proof').value = ''
    const range = document.getElementById('lb_ticket_range')
    if (range) range.value = String(DEFAULT_TICKET_EUR)
    applyTicketPricingToDom()
    lastTicketTierForAnim = null
    recalcTotals()
    showStep1()
  })
  if (location.hash === '#reserva-manual') {
    const el = document.getElementById('reserva-manual')
    if (el) {
      el.scrollIntoView({ behavior: 'smooth', block: 'start' })
    }
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init)
} else {
  init()
}
