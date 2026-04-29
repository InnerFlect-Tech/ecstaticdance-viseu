/**
 * Preços alinhados com server/api/create-checkout.php (early bird até fim de 3 mai, Lisboa).
 * Mín. 20€ (early bird) ou 25€; máx. 200€; de 5 em 5.
 */

export const TICKET_MAX_EUR = 200
export const TICKET_STEP = 5
export const EARLY_BIRD_MIN_EUR = 20
export const STANDARD_MIN_EUR = 25
/** "Até" = inclusive 3 maio; a partir de 4 mai, min standard (sincronizado com PHP). */
const EARLY_BIRD_END_YMD = '2026-05-04'

/**
 * @param {Date} [d]
 * @returns {string} YYYY-MM-DD no fuso de Lisboa
 */
function lisbonYmd(d = new Date()) {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Europe/Lisbon',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(d)
}

/**
 * @param {Date} [d]
 */
export function isEarlyBird(d = new Date()) {
  return lisbonYmd(d) < EARLY_BIRD_END_YMD
}

/**
 * @param {Date} [d]
 */
export function ticketMinEur(d = new Date()) {
  return isEarlyBird(d) ? EARLY_BIRD_MIN_EUR : STANDARD_MIN_EUR
}

/**
 * Ajusta o valor do slider ao intervalo e ao step.
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

export const DEFAULT_TICKET_EUR = 30

/**
 * bilhetes.html — aplica min/max e rótulo early bird no slider Stripe.
 */
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
  if (!Number.isFinite(v)) v = DEFAULT_TICKET_EUR
  v = snapTicketEur(v)
  range.value = String(v)
  disp.textContent = String(v)

  if (minLb) {
    if (isEarlyBird()) {
      minLb.textContent = '20€ — early bird'
    } else {
      minLb.textContent = '25€ — mínimo'
    }
  }

  const note = document.getElementById('bilhetes-early-bird')
  if (note) {
    if (isEarlyBird()) {
      note.removeAttribute('hidden')
    } else {
      note.setAttribute('hidden', '')
    }
  }
}
