/**
 * Frases do sliding scale (hub /links + reserva manual).
 * Limites em € alinhados com manual-booking.js.
 */

/** @typedef {{ max: number, pt: string, en: string }} TierPhrase */

export const TIER_PHRASES = /** @type {const TierPhrase[]} */ ([
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
])

/**
 * @param {number} euros
 * @returns {TierPhrase}
 */
export function phraseForAmount(euros) {
  const n = Number(euros)
  const v = Number.isFinite(n) ? n : 0
  for (const row of TIER_PHRASES) {
    if (v <= row.max) return row
  }
  return TIER_PHRASES[TIER_PHRASES.length - 1]
}
