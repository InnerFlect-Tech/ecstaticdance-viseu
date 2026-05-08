/** MB Way — número de telemóvel do destinatário (confere na app antes de pagar). */
const MBWAY_PHONE = '+351 910 458 858'

/** IBAN para Multibanco (homebanking / caixa) ou transferência SEPA. */
const BANK_IBAN = 'PT50 0035 0836 0069 4266 33047'

/** SWIFT/BIC — Caixa Geral de Depósitos. */
const BANK_BIC = 'CGDIPTPL'

const BANK_NAME = 'Caixa Geral de Depósitos'

const BANK_BENEFICIARY = 'CAROLINA ISABEL NOGUEIRA FERREIRA GOMES'

/** Revolut — @username / tag (confirma na app antes de enviar). */
const REVOLUT_HANDLE = '@carolina_gomes92' // TODO: tag Revolut real da equipa

/**
 * @param {Array<{label: string, value: string, highlight?: boolean, mono?: boolean}>} rows
 */
function buildDetailBlock(rows) {
  const wrap = document.createElement('div')
  wrap.className = 'links-payment-detail-block'
  for (const row of rows) {
    const dl = document.createElement('div')
    dl.className =
      'links-payment-detail-row' + (row.highlight ? ' links-payment-detail-row--ref' : '')
    const dt = document.createElement('span')
    dt.className = 'links-payment-detail-label'
    dt.textContent = row.label
    const dd = document.createElement('span')
    dd.className =
      'links-payment-detail-value' + (row.mono ? ' links-payment-detail-value--mono' : '')
    if (row.highlight) {
      const strong = document.createElement('strong')
      strong.className = 'links-payment-ref'
      strong.textContent = row.value
      dd.appendChild(strong)
    } else {
      dd.textContent = row.value
    }
    dl.append(dt, dd)
    wrap.appendChild(dl)
  }
  return wrap
}

/**
 * @param {HTMLElement} container
 * @param {string} intro
 * @param {Array<{ text: string, rows?: Array<{label: string, value: string, highlight?: boolean, mono?: boolean}>}>} steps
 * @param {string} afterNote
 */
function appendStepByStep(container, intro, steps, afterNote) {
  const introEl = document.createElement('p')
  introEl.className = 'links-payment-steps-intro'
  introEl.textContent = intro
  container.appendChild(introEl)

  const ol = document.createElement('ol')
  ol.className = 'links-payment-steps'
  for (const step of steps) {
    const li = document.createElement('li')
    const p = document.createElement('p')
    p.className = 'links-payment-step-text'
    p.textContent = step.text
    li.appendChild(p)
    if (step.rows && step.rows.length) {
      li.appendChild(buildDetailBlock(step.rows))
    }
    ol.appendChild(li)
  }
  container.appendChild(ol)

  const after = document.createElement('p')
  after.className = 'links-payment-after-steps'
  after.textContent = afterNote
  container.appendChild(after)
}

/**
 * Renders localised payment copy for the link-booking flow (no HTML from server).
 * @param {'mbway' | 'transfer' | 'revolut'} method
 * @param {{ paymentRef: string, totalLabel: string, infoEmail: string }} ctx
 * @param {HTMLElement} el
 * @param {boolean | null} [langPt] — se null, usa `document.body` (.lang-pt)
 */
export function renderPaymentInstructions(method, ctx, el, langPt = null) {
  if (!el) return
  const { paymentRef, totalLabel, infoEmail } = ctx
  const isPt = langPt !== null && langPt !== undefined ? Boolean(langPt) : document.body.classList.contains('lang-pt')
  el.innerHTML = ''

  if (method === 'mbway') {
    if (isPt) {
      appendStepByStep(
        el,
        'MB Way — confere o número do destinatário antes de confirmar.',
        [
          {
            text: 'Envia dinheiro para o número:',
            rows: [{ label: 'Número', value: MBWAY_PHONE }],
          },
          {
            text: 'Montante certo; na descrição/nota, só esta ref.:',
            rows: [
              { label: 'Ref.', value: paymentRef, highlight: true },
              { label: 'Montante', value: totalLabel },
            ],
          },
          { text: 'Confirma e guarda o ecrã de confirmação.' },
        ],
        'Em baixo: anexa foto ou PDF do comprovativo.'
      )
    } else {
      appendStepByStep(
        el,
        'MB Way — verify the recipient number before you confirm.',
        [
          {
            text: 'Send to this number:',
            rows: [{ label: 'Number', value: MBWAY_PHONE }],
          },
          {
            text: 'Correct amount; in the note, only this ref.:',
            rows: [
              { label: 'Ref.', value: paymentRef, highlight: true },
              { label: 'Amount', value: totalLabel },
            ],
          },
          { text: 'Confirm and keep the confirmation screen.' },
        ],
        'Below: attach a screenshot or PDF of the proof.'
      )
    }
    return
  }

  if (method === 'transfer') {
    if (isPt) {
      appendStepByStep(
        el,
        'Multibanco ou transferência — confere IBAN, SWIFT/BIC e beneficiário letra a letra.',
        [
          {
            text: 'Dados do destino:',
            rows: [
              { label: 'Banco', value: BANK_NAME },
              { label: 'SWIFT/BIC', value: BANK_BIC, mono: true },
              { label: 'IBAN', value: BANK_IBAN, mono: true },
              { label: 'Beneficiário', value: BANK_BENEFICIARY },
            ],
          },
          {
            text: 'Montante; na descrição ao destinatário, só esta ref.:',
            rows: [
              { label: 'Ref.', value: paymentRef, highlight: true },
              { label: 'Montante', value: totalLabel },
            ],
          },
          { text: 'Autoriza e guarda o comprovativo do banco.' },
        ],
        'Em baixo: PDF ou foto legível (IBAN, montante, ref.).'
      )
    } else {
      appendStepByStep(
        el,
        'Multibanco or bank transfer — verify IBAN, SWIFT/BIC, and beneficiary character by character.',
        [
          {
            text: 'Destination:',
            rows: [
              { label: 'Bank', value: BANK_NAME },
              { label: 'SWIFT/BIC', value: BANK_BIC, mono: true },
              { label: 'IBAN', value: BANK_IBAN, mono: true },
              { label: 'Beneficiary', value: BANK_BENEFICIARY },
            ],
          },
          {
            text: 'Amount; in the transfer reference/description, only:',
            rows: [
              { label: 'Ref.', value: paymentRef, highlight: true },
              { label: 'Amount', value: totalLabel },
            ],
          },
          { text: 'Authorise and save the bank receipt.' },
        ],
        'Below: PDF or clear photo (IBAN, amount, ref.).'
      )
    }
    return
  }

  if (method === 'revolut') {
    if (isPt) {
      appendStepByStep(
        el,
        'Revolut — confere a @tag antes de enviar.',
        [
          {
            text: 'Envia para:',
            rows: [{ label: 'Tag', value: REVOLUT_HANDLE, mono: true }],
          },
          {
            text: 'Montante; na nota do pagamento, só esta ref.:',
            rows: [
              { label: 'Ref.', value: paymentRef, highlight: true },
              { label: 'Montante', value: totalLabel },
            ],
          },
          { text: 'Confirma e guarda o ecrã final.' },
        ],
        'Em baixo: captura ou foto do comprovativo.'
      )
    } else {
      appendStepByStep(
        el,
        'Revolut — confirm the @tag before you send.',
        [
          {
            text: 'Send to:',
            rows: [{ label: 'Tag', value: REVOLUT_HANDLE, mono: true }],
          },
          {
            text: 'Amount; in the payment note, only:',
            rows: [
              { label: 'Ref.', value: paymentRef, highlight: true },
              { label: 'Amount', value: totalLabel },
            ],
          },
          { text: 'Confirm and save the success screen.' },
        ],
        'Below: screenshot or photo of the proof.'
      )
    }
    return
  }

  const p = document.createElement('p')
  p.textContent = isPt ? `Questões? Escreve para ${infoEmail}.` : `Questions? Email ${infoEmail}.`
  el.appendChild(p)
}
