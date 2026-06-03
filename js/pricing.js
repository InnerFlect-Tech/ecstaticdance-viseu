/**
 * Preços alinhados com server/api/get-ticket-pricing.php.
 * Early bird, standard, ou dançarino·a de regresso (por email).
 */

export const TICKET_MAX_EUR = 200
export const TICKET_SLIDER_CAP_EUR = 100
export const TICKET_STEP = 5
export const STANDARD_MIN_EUR = 30
export const EARLY_BIRD_MIN_EUR = 20
export const RETURNING_MIN_EUR_DEFAULT = 15

/** @type {{ minEur: number, tier: string, isReturning: boolean, eventId: number, email: string }} */
let pricingState = {
  minEur: STANDARD_MIN_EUR,
  tier: 'standard',
  isReturning: false,
  eventId: 0,
  email: '',
}

export function getPricingState() {
  return pricingState
}

/**
 * @param {Date} [d]
 */
export function isEarlyBirdPeriod(d = new Date()) {
  const ymd = d.toLocaleDateString('en-CA', { timeZone: 'Europe/Lisbon' })
  return ymd <= '2026-06-13'
}

/** Piso local (sem API) — early bird vs standard. */
export function defaultTicketMinEur(d = new Date()) {
  return isEarlyBirdPeriod(d) ? EARLY_BIRD_MIN_EUR : STANDARD_MIN_EUR
}

/**
 * Piso activo (API ou fallback local).
 * @param {Date} [d]
 */
export function ticketMinEur(d = new Date()) {
  if (pricingState.email && pricingState.minEur > 0) {
    return pricingState.minEur
  }
  return defaultTicketMinEur(d)
}

/**
 * @param {string} email
 * @param {number} [eventId]
 */
export async function refreshTicketPricing(email, eventId = 0, eventSlug = '', phone = '') {
  const trimmed = String(email || '').trim()
  const phoneTrim = String(phone || '').trim()
  if ((!trimmed || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) && !phoneTrim) {
    pricingState = {
      minEur: defaultTicketMinEur(),
      tier: isEarlyBirdPeriod() ? 'early_bird' : 'standard',
      isReturning: false,
      eventId: eventId || 0,
      email: '',
    }
    return pricingState
  }

  const q = new URLSearchParams()
  if (trimmed && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) q.set('email', trimmed)
  if (phoneTrim) q.set('phone', phoneTrim)
  if (eventId > 0) q.set('event_id', String(eventId))
  if (eventSlug) q.set('event_slug', eventSlug)

  try {
    const res = await fetch(`/api/get-ticket-pricing.php?${q}`)
    const data = await res.json()
    if (data.ok) {
      pricingState = {
        minEur: Number(data.min_eur) || defaultTicketMinEur(),
        tier: String(data.tier || 'standard'),
        isReturning: Boolean(data.is_returning),
        eventId: eventId || 0,
        email: trimmed,
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
    eventId: eventId || 0,
    email: trimmed,
  }
  return pricingState
}

export function minPriceLabelPt() {
  const min = ticketMinEur()
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
