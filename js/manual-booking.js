import { renderPaymentInstructions } from './payment-instructions.js'
import { phraseForAmount } from './ticket-phrases.js'
import {
  DEFAULT_TICKET_EUR,
  TICKET_MAX_EUR,
  TICKET_SLIDER_CAP_EUR,
  TICKET_STEP,
  isEarlyBird,
  normalizeTicketAmountEur,
  snapTicketSliderEur,
  ticketMinEur,
} from './pricing.js'

const DEFAULT_EVENT_SLUG = 'edv-2026-05-23'
const INFO_EMAIL = 'info@ecstaticdanceviseu.pt'
/** Extra fixo: jantar no local (reserva manual em bilhetes.html) */
const DINNER_EUR = 12

function prefersReducedMotionBooking() {
  return typeof matchMedia !== 'undefined' && matchMedia('(prefers-reduced-motion: reduce)').matches
}

/**
 * @param {HTMLElement | null} el
 */
function flashStepCard(el) {
  if (!el || prefersReducedMotionBooking()) return
  el.classList.remove('links-step-enter')
  void el.offsetWidth
  el.classList.add('links-step-enter')
  const done = () => el.classList.remove('links-step-enter')
  el.addEventListener('animationend', done, { once: true })
  window.setTimeout(done, 520)
}

/**
 * Centra o viewport no elemento foco do passo (ex.: título do passo 2).
 * @param {HTMLElement | null} el
 */
function scrollBookingStepIntoView(el) {
  if (!el) return
  const reduced = prefersReducedMotionBooking()
  el.scrollIntoView({
    behavior: reduced ? 'auto' : 'smooth',
    block: 'center',
    inline: 'nearest',
  })
}

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
        `API inacessível (HTTP ${status}). Em desenvolvimento usa «npm run dev:local» (Vite + PHP no loopback; a porta 8080–8099 é escolhida automaticamente). Garante server/api/config.php e, se precisares de gravar sem SQLite, LINK_USE_JSON => true.`
      )
    }
    throw new Error(
      status >= 500
        ? `Resposta vazia do servidor (HTTP ${status})${url ? ` — ${url}` : ''}. Confirma que o PHP está a correr (mensagem no terminal ao iniciar dev:local), que existe config.php e que a base de dados / modo JSON está correto.`
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

/** @param {string} id */
function elMaybe(id) {
  return document.getElementById(id)
}

/** Alinha com bilhetes.js / PHP típico para endereços correntes. */
function isValidLinkEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)
}

const API_ERR_PT_TO_EN = new Map([
  ['Email inválido.', 'Please enter a valid email address (e.g. name@example.com).'],
  ['Nome, email e telemóvel são obrigatórios.', 'Name, email and phone are required.'],
  ['JSON inválido.', 'Something went wrong. Please try again.'],
  ['Método de pagamento inválido.', 'Invalid payment method.'],
  ['Indica como tiveste conhecimento do evento.', 'Please tell us how you heard about this event.'],
  ['Especifica em «Outro» como tiveste conhecimento.', 'Please add details under “Other”.'],
  ['Valores inválidos.', 'Invalid amounts.'],
])

/**
 * @param {string} msg
 * @param {boolean} isPt
 */
function translateApiUserMessage(msg, isPt) {
  if (isPt || !msg) return msg
  const t = msg.trim()
  if (API_ERR_PT_TO_EN.has(t)) return /** @type {string} */ (API_ERR_PT_TO_EN.get(t))
  if (t.startsWith('Valor do bilhete fora do intervalo')) {
    return 'Ticket amount is outside the allowed range.'
  }
  return msg
}

/** @param {HTMLElement} el */
function clearFormBanner(el) {
  el.textContent = ''
  el.classList.remove('links-form-error-banner')
}

/** @param {HTMLElement} el */
function showFormBanner(el, message) {
  el.textContent = message
  el.classList.add('links-form-error-banner')
  el.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
}

function clearStep1InlineErrors() {
  clearInlineFieldError('lb_name', 'lb_name_error')
  clearInlineFieldError('lb_email', 'lb_email_error')
  clearInlineFieldError('lb_phone', 'lb_phone_error')
  clearInlineFieldError('lb_heard_other', 'lb_heard_other_error')
}

/**
 * @param {string} inputId
 * @param {string} errorElId
 */
function clearInlineFieldError(inputId, errorElId) {
  const inp = elMaybe(inputId)
  const errEl = elMaybe(errorElId)
  if (errEl) {
    errEl.textContent = ''
    errEl.hidden = true
  }
  if (inp) {
    inp.removeAttribute('aria-invalid')
    if (inp.getAttribute('aria-describedby') === errorElId) {
      inp.removeAttribute('aria-describedby')
    }
  }
}

/**
 * @param {string} inputId
 * @param {string} errorElId
 * @param {string} message
 */
function showInlineFieldError(inputId, errorElId, message) {
  clearStep1InlineErrors()
  clearFormBanner(getEl('lb_form_error'))
  const inp = elMaybe(inputId)
  const errEl = elMaybe(errorElId)
  if (!inp || !errEl) return
  errEl.textContent = message
  errEl.hidden = false
  inp.setAttribute('aria-invalid', 'true')
  inp.setAttribute('aria-describedby', errorElId)
  inp.focus({ preventScroll: true })
  inp.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

/**
 * Erros sem campo dedicado ou vários campos.
 * @param {string} message
 */
function showFormSummaryError(message) {
  clearStep1InlineErrors()
  showFormBanner(getEl('lb_form_error'), message)
}

/**
 * @param {string} rawMessage
 */
function applyApiErrorToUi(rawMessage) {
  const isPt = isManualPt()
  const raw = String(rawMessage || '').trim()
  const msg = translateApiUserMessage(raw, isPt)

  if (raw === 'Email inválido.' || raw.includes('Email inválido')) {
    showInlineFieldError('lb_email', 'lb_email_error', msg)
    return
  }
  if (raw.startsWith('Especifica em «Outro»')) {
    showInlineFieldError('lb_heard_other', 'lb_heard_other_error', msg)
    return
  }
  if (raw === 'Nome, email e telemóvel são obrigatórios.') {
    const name = getEl('lb_name').value.trim()
    const email = getEl('lb_email').value.trim()
    const phone = getEl('lb_phone').value.trim()
    const short = isPt ? 'Este campo é obrigatório.' : 'This field is required.'
    if (!name) showInlineFieldError('lb_name', 'lb_name_error', short)
    else if (!email) showInlineFieldError('lb_email', 'lb_email_error', short)
    else if (!phone) showInlineFieldError('lb_phone', 'lb_phone_error', short)
    else showFormSummaryError(msg)
    return
  }
  if (raw.startsWith('Valor do bilhete fora do intervalo')) {
    showFormSummaryError(msg)
    const range = document.getElementById('lb_ticket_range')
    range?.focus({ preventScroll: true })
    document.getElementById('links-amount-picker')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
    return
  }
  if (raw === 'Indica como tiveste conhecimento do evento.') {
    showFormSummaryError(msg)
    const sel = getEl('lb_heard_from')
    sel.focus({ preventScroll: true })
    sel.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
    return
  }
  showFormSummaryError(msg)
}

function wireStep1InlineErrorClearing() {
  const banner = getEl('lb_form_error')
  const pairs = [
    ['lb_name', 'lb_name_error'],
    ['lb_email', 'lb_email_error'],
    ['lb_phone', 'lb_phone_error'],
    ['lb_heard_other', 'lb_heard_other_error'],
  ]
  for (const [inpId, errId] of pairs) {
    const inp = elMaybe(inpId)
    if (!inp) continue
    inp.addEventListener('input', () => {
      clearInlineFieldError(inpId, errId)
      clearFormBanner(banner)
    })
  }
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
    box,
    isManualPt()
  )
}

function showStep1() {
  getEl('lb_step_1').hidden = false
  getEl('lb_step_2').hidden = true
  requestAnimationFrame(() => flashStepCard(document.getElementById('lb_step_1')))
}

function hideStep2CompletionUi() {
  const active = document.getElementById('lb_step_2_heading_active')
  const done = document.getElementById('lb_step_2_heading_done')
  const bodyUp = document.getElementById('lb_step_2_done_body_upload')
  const bodyLater = document.getElementById('lb_step_2_done_body_later')
  if (active) active.hidden = false
  if (done) done.hidden = true
  if (bodyUp) bodyUp.hidden = true
  if (bodyLater) bodyLater.hidden = true
}

function showStep2CompletionUploaded() {
  const active = document.getElementById('lb_step_2_heading_active')
  const done = document.getElementById('lb_step_2_heading_done')
  const bodyUp = document.getElementById('lb_step_2_done_body_upload')
  const bodyLater = document.getElementById('lb_step_2_done_body_later')
  if (active) active.hidden = true
  if (done) done.hidden = false
  if (bodyLater) bodyLater.hidden = true
  if (bodyUp) bodyUp.hidden = false
}

function showStep2CompletionEmailLater() {
  const active = document.getElementById('lb_step_2_heading_active')
  const done = document.getElementById('lb_step_2_heading_done')
  const bodyUp = document.getElementById('lb_step_2_done_body_upload')
  const bodyLater = document.getElementById('lb_step_2_done_body_later')
  if (active) active.hidden = true
  if (done) done.hidden = false
  if (bodyUp) bodyUp.hidden = true
  if (bodyLater) bodyLater.hidden = false
}

function showStep2() {
  getEl('lb_step_1').hidden = true
  getEl('lb_step_2').hidden = false
  hideStep2CompletionUi()
  getEl('lb_step_2_in').hidden = false
  const ok = document.getElementById('lb_step2_success')
  if (ok) ok.textContent = ''
  const err = document.getElementById('lb_step2_error')
  if (err) err.textContent = ''
  for (const el of document.querySelectorAll('.js-summary-ref')) {
    el.textContent = state.paymentRef
  }
  for (const el of document.querySelectorAll('.js-summary-total')) {
    el.textContent = formatEur(state.totalEur)
  }
  paintInstructions()
  requestAnimationFrame(() => {
    const card = document.getElementById('lb_step_2')
    flashStepCard(card)
    requestAnimationFrame(() => scrollBookingStepIntoView(document.getElementById('lb_step_2_title')))
  })
}

function setHeardOtherVisible() {
  const sel = getEl('lb_heard_from')
  const other = getEl('lb_heard_other_wrap')
  const v = sel.value
  other.hidden = v !== 'other'
  if (v !== 'other') {
    clearInlineFieldError('lb_heard_other', 'lb_heard_other_error')
  }
}

let lastTicketTierForAnim = /** @type {number | null} */ (null)

/** Mostra o bloco «101–200» só quando o slider está no máximo (100€). */
function updateTicketScalePlusVisibility() {
  const wrap = document.getElementById('lb_ticket_scale_plus_wrap')
  const range = document.getElementById('lb_ticket_range')
  if (!wrap || !range) return
  const rv = parseFloat(String(range.value))
  const atCap = Number.isFinite(rv) && rv >= TICKET_SLIDER_CAP_EUR
  if (atCap) {
    wrap.removeAttribute('hidden')
    wrap.setAttribute('aria-hidden', 'false')
  } else {
    wrap.setAttribute('hidden', '')
    wrap.setAttribute('aria-hidden', 'true')
  }
}

function setRangeTrackPct(ticket) {
  const range = document.getElementById('lb_ticket_range')
  if (range) {
    const min = parseFloat(String(range.min)) || ticketMinEur()
    const max = parseFloat(String(range.max)) || TICKET_SLIDER_CAP_EUR
    if (ticket > max) {
      range.style.setProperty('--range-pct', '100%')
      return
    }
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
  const custom = document.getElementById('lb_ticket_custom')
  const ticketHidden = getEl('lb_ticket_euros')
  const out = document.getElementById('lb_ticket_amount_out')
  const dinner = getEl('lb_dinner_euros')
  const totalOut = elMaybe('lb_total_euros_out')
  let t = DEFAULT_TICKET_EUR
  if (range) {
    const customRaw = custom?.value?.trim() ?? ''
    let fromCustom = false
    if (customRaw !== '') {
      const c = Math.round(Number(customRaw))
      if (Number.isFinite(c)) {
        if (c <= TICKET_SLIDER_CAP_EUR) {
          if (custom) custom.value = ''
        } else {
          const adj = Math.min(TICKET_MAX_EUR, Math.max(101, c))
          if (custom) custom.value = String(adj)
          t = adj
          fromCustom = true
          range.value = String(TICKET_SLIDER_CAP_EUR)
        }
      }
    }
    if (!fromCustom) {
      let s = parseFloat(String(range.value))
      if (!Number.isFinite(s)) s = DEFAULT_TICKET_EUR
      t = snapTicketSliderEur(s)
      range.value = String(t)
    }
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
  if (totalOut) totalOut.textContent = formatEur(state.totalEur)
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
  updateTicketScalePlusVisibility()
}

function applyTicketPricingToDom() {
  const range = document.getElementById('lb_ticket_range')
  if (!range) return
  const min = ticketMinEur()
  const max = TICKET_SLIDER_CAP_EUR
  range.min = String(min)
  range.max = String(max)
  range.step = String(TICKET_STEP)
  range.setAttribute(
    'aria-label',
    `Sliding scale: ${min}–${max} €, step ${TICKET_STEP}; above ${max} € use the number field`
  )
  const raw = parseFloat(String(range.value))
  const v = snapTicketSliderEur(Number.isFinite(raw) ? raw : DEFAULT_TICKET_EUR)
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
      if (pt) pt.textContent = '30€ — mínimo'
      if (en) en.textContent = '€30 — minimum'
    }
  }

  updateTicketScalePlusVisibility()
}

function wireTotals() {
  const form = getEl('lb_booking_form')
  if (form.dataset.totalsWired === '1') return
  form.dataset.totalsWired = '1'
  const range = document.getElementById('lb_ticket_range')
  const custom = document.getElementById('lb_ticket_custom')
  const dinner = getEl('lb_dinner_euros')
  const incl = document.getElementById('lb_dinner_incl')
  function onRangeAdjust() {
    if (custom) custom.value = ''
    recalcTotals()
  }
  const clearTotalsBanner = () => clearFormBanner(getEl('lb_form_error'))
  range?.addEventListener('input', () => {
    clearTotalsBanner()
    onRangeAdjust()
  })
  range?.addEventListener('change', () => {
    clearTotalsBanner()
    onRangeAdjust()
  })
  custom?.addEventListener('input', () => {
    clearTotalsBanner()
    recalcTotals()
  })
  custom?.addEventListener('change', () => {
    clearTotalsBanner()
    recalcTotals()
  })
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
  const errBanner = getEl('lb_form_error')
  clearStep1InlineErrors()
  clearFormBanner(errBanner)

  const name = getEl('lb_name').value.trim()
  const email = getEl('lb_email').value.trim()
  const phone = getEl('lb_phone').value.trim()
  const dinnerNote = getEl('lb_dinner_note').value.trim()
  const heard = getEl('lb_heard_from').value
  const heardOther = getEl('lb_heard_other').value.trim()
  const isPt = isManualPt()
  onPaymentChange()

  if (!name) {
    showInlineFieldError(
      'lb_name',
      'lb_name_error',
      isPt ? 'Indica o teu nome.' : 'Please enter your name.'
    )
    return
  }
  if (!email) {
    showInlineFieldError(
      'lb_email',
      'lb_email_error',
      isPt ? 'Indica o email.' : 'Please enter your email.'
    )
    return
  }
  if (!isValidLinkEmail(email)) {
    showInlineFieldError(
      'lb_email',
      'lb_email_error',
      isPt ? 'O email introduzido não é válido.' : 'That email does not look valid (e.g. name@example.com).'
    )
    return
  }
  if (!phone) {
    showInlineFieldError(
      'lb_phone',
      'lb_phone_error',
      isPt ? 'Indica o telemóvel.' : 'Please enter your mobile number.'
    )
    return
  }

  if (heard === 'other' && heardOther === '') {
    showInlineFieldError(
      'lb_heard_other',
      'lb_heard_other_error',
      isPt ? 'Indica o texto em «Outro».' : 'Please add details for “Other”.'
    )
    return
  }
  if (state.totalEur <= 0) {
    showFormSummaryError(isPt ? 'O total deve ser maior que zero.' : 'Total must be greater than zero.')
    const range = document.getElementById('lb_ticket_range')
    range?.focus({ preventScroll: true })
    document.getElementById('links-amount-picker')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
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
    const raw = ex instanceof Error ? ex.message : 'Erro.'
    applyApiErrorToUi(raw)
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
    err.textContent = isPt
      ? 'Escolhe um ficheiro: imagem (foto ou captura de ecrã) ou PDF até 5 MB.'
      : 'Choose a file: image (photo or screenshot) or PDF, up to 5 MB.'
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
    ok.textContent = ''
    getEl('lb_step_2_in').hidden = true
    showStep2CompletionUploaded()
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
    ok.textContent = ''
    getEl('lb_step_2_in').hidden = true
    showStep2CompletionEmailLater()
  } catch (ex) {
    err.textContent = ex instanceof Error ? ex.message : 'Erro.'
  } finally {
    getEl('lb_btn_later').disabled = false
  }
}

function applyHubPrefTicket() {
  try {
    const raw = sessionStorage.getItem('edv_hub_ticket_eur')
    if (raw == null || raw === '') return
    sessionStorage.removeItem('edv_hub_ticket_eur')
    const n = Number(raw)
    if (!Number.isFinite(n)) return
    const v = normalizeTicketAmountEur(n)
    const range = document.getElementById('lb_ticket_range')
    const custom = document.getElementById('lb_ticket_custom')
    if (v > TICKET_SLIDER_CAP_EUR) {
      if (custom) custom.value = String(v)
      if (range) range.value = String(TICKET_SLIDER_CAP_EUR)
    } else {
      if (custom) custom.value = ''
      if (range) range.value = String(v)
    }
    lastTicketTierForAnim = null
    recalcTotals()
  } catch {
    // ignore
  }
}

function init() {
  if (!document.getElementById('lb_booking_form')) return
  initManualLang()
  applyTicketPricingToDom()
  applyHubPrefTicket()
  wireStep1InlineErrorClearing()
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
    hideStep2CompletionUi()
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
    const custom = document.getElementById('lb_ticket_custom')
    if (custom) custom.value = ''
    if (range) range.value = String(DEFAULT_TICKET_EUR)
    applyTicketPricingToDom()
    lastTicketTierForAnim = null
    recalcTotals()
    showStep1()
    clearStep1InlineErrors()
    clearFormBanner(getEl('lb_form_error'))
  })
  if (location.hash === '#reserva-manual' && document.body.id !== 'links-page') {
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

/**
 * Alinha #reserva-manual com o idioma da página (ex.: botões PT/EN do header em /links).
 * @param {'pt' | 'en'} lang
 */
export function syncManualBookingLang(lang) {
  if (!document.getElementById('reserva-manual')) return
  setManualSectionLang(lang === 'en' ? 'en' : 'pt')
  applyTicketPricingToDom()
  if (state.paymentRef) paintInstructions()
}
