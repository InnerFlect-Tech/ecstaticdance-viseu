/**
 * Preços alinhados com server/api/get-ticket-pricing.php.
 * Early bird, standard, ou dançarino·a de regresso (por email).
 */

export const TICKET_MAX_EUR = 200
export const TICKET_SLIDER_CAP_EUR = 100
export const TICKET_STEP = 5
export const STANDARD_MIN_EUR = 30
export const EARLY_BIRD_MIN_EUR = 25
export const RETURNING_MIN_EUR_DEFAULT = 15

/** @type {{ minEur: number, tier: string, isReturning: boolean, isDiscountCode: boolean, eventId: number, email: string, promoCode: string }} */
let pricingState = {
  minEur: STANDARD_MIN_EUR,
  tier: 'standard',
  isReturning: false,
  isDiscountCode: false,
  eventId: 0,
  email: '',
  promoCode: '',
}

/** @type {{ standardMinEur: number, earlyBirdMinEur: number, earlyBirdUntil: string | null }} */
let eventPricingConfig = {
  standardMinEur: STANDARD_MIN_EUR,
  earlyBirdMinEur: EARLY_BIRD_MIN_EUR,
  earlyBirdUntil: null,
}

export function getPricingState() {
  return pricingState
}

export function getEventPricingConfig() {
  return eventPricingConfig
}

function parsePrice(value, fallback) {
  const n = Number(value)
  return Number.isFinite(n) && n >= 0 ? n : fallback
}

/**
 * @param {{ min_price?: unknown, early_bird_min_eur?: unknown, early_bird_until?: unknown } | null | undefined} event
 */
export function setEventPricingFromEvent(event) {
  if (!event) return
  eventPricingConfig = {
    standardMinEur: parsePrice(event.min_price, STANDARD_MIN_EUR),
    earlyBirdMinEur: parsePrice(event.early_bird_min_eur, EARLY_BIRD_MIN_EUR),
    earlyBirdUntil: event.early_bird_until ? String(event.early_bird_until) : null,
  }
}

/**
 * @param {{ standard_min_eur?: unknown, early_bird_min_eur?: unknown, early_bird_until?: unknown } | null | undefined} data
 */
export function setEventPricingFromApi(data) {
  if (!data) return
  eventPricingConfig = {
    standardMinEur: parsePrice(data.standard_min_eur, eventPricingConfig.standardMinEur),
    earlyBirdMinEur: parsePrice(data.early_bird_min_eur, eventPricingConfig.earlyBirdMinEur),
    earlyBirdUntil: data.early_bird_until ? String(data.early_bird_until) : null,
  }
}

/**
 * @param {Date} [d]
 */
export function isEarlyBirdPeriod(d = new Date()) {
  const until = eventPricingConfig.earlyBirdUntil
  if (!until) return false
  const ymd = d.toLocaleDateString('en-CA', { timeZone: 'Europe/Lisbon' })
  return ymd <= until
}

/** Early bird activo no admin (data limite definida). */
export function isEarlyBirdConfigured() {
  return Boolean(eventPricingConfig.earlyBirdUntil)
}

/** Sincroniza piso local com config do evento (páginas sem email). */
export function syncEventPricingFloor(d = new Date()) {
  pricingState.minEur = defaultTicketMinEur(d)
  pricingState.tier = isEarlyBirdPeriod(d) ? 'early_bird' : 'standard'
}

/** Piso local (sem API) — early bird vs standard. */
export function defaultTicketMinEur(d = new Date()) {
  return isEarlyBirdPeriod(d) ? eventPricingConfig.earlyBirdMinEur : eventPricingConfig.standardMinEur
}

/**
 * Piso activo (API ou fallback local).
 * @param {Date} [d]
 */
export function ticketMinEur(d = new Date()) {
  if (pricingState.minEur > 0) {
    return pricingState.minEur
  }
  return defaultTicketMinEur(d)
}

export function getPromoCode() {
  return pricingState.promoCode
}

/**
 * @param {string} code
 */
export function setPromoCode(code) {
  pricingState.promoCode = String(code || '').trim().toUpperCase()
}

/**
 * @param {string | null | undefined} until YYYY-MM-DD
 * @param {'pt' | 'en'} [lang]
 */
export function formatEarlyBirdUntil(until, lang = 'pt') {
  if (!until) return ''
  const [y, m, d] = until.split('-').map(Number)
  if (!y || !m || !d) return until
  const date = new Date(Date.UTC(y, m - 1, d, 12, 0, 0))
  if (lang === 'en') {
    return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', timeZone: 'UTC' })
  }
  return date.toLocaleDateString('pt-PT', { day: 'numeric', month: 'long', timeZone: 'UTC' })
}

/**
 * @param {string} email
 * @param {number} [eventId]
 * @param {string} [eventSlug]
 * @param {string} [phone]
 * @param {string} [promoCode]
 */
export async function refreshTicketPricing(email, eventId = 0, eventSlug = '', phone = '', promoCode = '') {
  const trimmed = String(email || '').trim()
  const phoneTrim = String(phone || '').trim()
  const code = String(promoCode || pricingState.promoCode || '').trim().toUpperCase()
  pricingState.promoCode = code
  const q = new URLSearchParams()
  if (eventId > 0) q.set('event_id', String(eventId))
  if (eventSlug) q.set('event_slug', eventSlug)
  if (code) q.set('code', code)

  if ((!trimmed || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) && !phoneTrim) {
    try {
      const res = await fetch(`/api/get-ticket-pricing.php?${q}`)
      const data = await res.json()
      if (data.ok) {
        setEventPricingFromApi(data)
        pricingState = {
          minEur: Number(data.min_eur) || defaultTicketMinEur(),
          tier: String(data.tier || (isEarlyBirdPeriod() ? 'early_bird' : 'standard')),
          isReturning: false,
          isDiscountCode: Boolean(data.is_discount_code),
          eventId: eventId || 0,
          email: '',
          promoCode: code,
        }
        return pricingState
      }
    } catch {
      // fallback local config
    }
    pricingState = {
      minEur: defaultTicketMinEur(),
      tier: isEarlyBirdPeriod() ? 'early_bird' : 'standard',
      isReturning: false,
      isDiscountCode: false,
      eventId: eventId || 0,
      email: '',
      promoCode: code,
    }
    return pricingState
  }

  if (trimmed && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) q.set('email', trimmed)
  if (phoneTrim) q.set('phone', phoneTrim)

  try {
    const res = await fetch(`/api/get-ticket-pricing.php?${q}`)
    const data = await res.json()
    if (data.ok) {
      setEventPricingFromApi(data)
      pricingState = {
        minEur: Number(data.min_eur) || defaultTicketMinEur(),
        tier: String(data.tier || 'standard'),
        isReturning: Boolean(data.is_returning),
        isDiscountCode: Boolean(data.is_discount_code),
        eventId: eventId || 0,
        email: trimmed,
        promoCode: code,
      }
      return pricingState
    }
  } catch {
    // fallback
  }

  pricingState = {
    minEur: defaultTicketMinEur(),
    tier: isEarlyBirdPeriod() ? 'early_bird' : 'standard',
    isReturning: false,
    isDiscountCode: false,
    eventId: eventId || 0,
    email: trimmed,
    promoCode: code,
  }
  return pricingState
}

/**
 * Carrega configuração de preços do evento activo (sem email).
 */
export async function loadActiveEventPricing() {
  try {
    const res = await fetch('/api/get-events.php')
    const data = await res.json()
    if (data.ok && data.event) {
      setEventPricingFromEvent(data.event)
      syncEventPricingFloor()
      return data.event
    }
  } catch {
    // ignore
  }
  return null
}

export function minPriceLabelPt() {
  const min = ticketMinEur()
  if (pricingState.tier === 'discount_code') {
    return `${min}€ — código de desconto`
  }
  if (pricingState.tier === 'returning') {
    return `${min}€ — dançarino·a de regresso`
  }
  if (pricingState.tier === 'early_bird') {
    return `${min}€ — early bird`
  }
  return `${min}€ — mínimo`
}

export function minPriceLabelEn() {
  const min = ticketMinEur()
  if (pricingState.tier === 'discount_code') {
    return `€${min} — discount code`
  }
  if (pricingState.tier === 'returning') {
    return `€${min} — returning dancer`
  }
  if (pricingState.tier === 'early_bird') {
    return `€${min} — early bird`
  }
  return `€${min} — minimum`
}

/**
 * @param {number} raw
 * @param {Date} [d]
 */
export function snapTicketEur(raw, d = new Date()) {
  const min = ticketMinEur(d)
  const max = TICKET_MAX_EUR
  let n = Math.round(Number(raw) / TICKET_STEP) * TICKET_STEP
  if (!Number.isFinite(n)) n = min
  return Math.min(max, Math.max(min, n))
}

export function snapTicketSliderEur(raw, d = new Date()) {
  const min = ticketMinEur(d)
  const cap = TICKET_SLIDER_CAP_EUR
  let n = Math.round((Number(raw) - min) / TICKET_STEP) * TICKET_STEP + min
  if (!Number.isFinite(n)) n = min
  return Math.min(cap, Math.max(min, n))
}

export function normalizeTicketAmountEur(raw, d = new Date()) {
  let n = Number(raw)
  if (!Number.isFinite(n)) return snapTicketSliderEur(DEFAULT_TICKET_EUR, d)
  if (n > TICKET_SLIDER_CAP_EUR) {
    const r = Math.round(n)
    return Math.min(TICKET_MAX_EUR, Math.max(101, r))
  }
  return snapTicketSliderEur(n, d)
}

export const DEFAULT_TICKET_EUR = 30

export function applyBilhetesAmountRange() {
  const range = document.getElementById('amountRange')
  const disp = document.getElementById('amountDisplay')
  const minLb = document.getElementById('bilhetes_range_min_lbl')
  if (!range || !disp) return
  const min = ticketMinEur()
  const max = TICKET_MAX_EUR
  range.min = String(min)
  range.max = String(max)
  range.step = String(TICKET_STEP)
  range.setAttribute('aria-label', `Valor entre ${min}€ e ${max}€`)
  let v = parseInt(String(range.value), 10)
  if (!Number.isFinite(v)) v = min
  v = snapTicketEur(v)
  range.value = String(v)
  disp.textContent = String(v)

  if (minLb) {
    minLb.textContent = minPriceLabelPt()
  }
}
