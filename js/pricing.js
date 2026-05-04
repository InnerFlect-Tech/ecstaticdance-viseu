/**
 * Preços alinhados com server/api/ticket-pricing.php (PHP).
 * Early bird: piso 20€ até ao fim do dia 9 de maio de 2026 (Europe/Lisbon); depois 30€.
 * Máx. 200€; de 5 em 5 no slider até 100€.
 */

export const TICKET_MAX_EUR = 200
/** Teto do range visual (hub + reserva manual); acima disto usar campo livre 101–200. */
export const TICKET_SLIDER_CAP_EUR = 100
export const TICKET_STEP = 5
export const STANDARD_MIN_EUR = 30
export const EARLY_BIRD_MIN_EUR = 20

/**
 * Early bird até ao fim do dia 9 de maio de 2026 (calendário em Europe/Lisbon; alinhado a ticket-pricing.php).
 * @param {Date} [d]
 */
export function isEarlyBirdPeriod(d = new Date()) {
  const ymd = d.toLocaleDateString('en-CA', { timeZone: 'Europe/Lisbon' })
  return ymd <= '2026-05-09'
}

/**
 * @param {Date} [d]
 */
export function ticketMinEur(d = new Date()) {
  return isEarlyBirdPeriod(d) ? EARLY_BIRD_MIN_EUR : STANDARD_MIN_EUR
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

/**
 * Valores do slider hub / bilhete manual: entre mínimo e {@link TICKET_SLIDER_CAP_EUR}, passo 5€.
 */
export function snapTicketSliderEur(raw, d = new Date()) {
  const min = ticketMinEur(d)
  const cap = TICKET_SLIDER_CAP_EUR
  let n = Math.round((Number(raw) - min) / TICKET_STEP) * TICKET_STEP + min
  if (!Number.isFinite(n)) n = min
  return Math.min(cap, Math.max(min, n))
}

/**
 * Preferência do hub ou valor final: até 100€ alinha ao slider; acima, qualquer inteiro 101–200.
 */
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

/**
 * bilhetes.html — aplica min/max e rótulo do piso no slider Stripe.
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
    minLb.textContent = `${min}€ — mínimo`
  }
}
