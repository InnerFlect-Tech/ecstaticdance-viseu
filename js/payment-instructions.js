/** MB Way — número de telemóvel do destinatário (confere na app antes de pagar). */
const MBWAY_PHONE = '+351 912 345 678' // TODO: substituir pelo número real MB Way da equipa

/** IBAN para transferência SEPA (verifica carácter a carácter no homebanking). */
const BANK_IBAN = 'PT50 0000 0000 0000 0000 0000 0' // TODO: substituir pelo IBAN real
const BANK_NAME = 'Banco CTT / CGD' // TODO: banco correto

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
        'Segue estes passos na app MB Way. Antes de confirmar, verifica sempre que o número do destinatário corresponde exatamente aos dados da equipa (evita erros e fraudes).',
        [
          {
            text: 'Abre o MB Way no telemóvel e inicia um envio de dinheiro para um número de telemóvel.',
          },
          {
            text: 'Confere o destinatário — o número de telemóvel tem de coincidir dígito a dígito com o indicado abaixo (não alteres espaços nem o indicativo se a app já o mostrar).',
            rows: [{ label: 'Número MB Way da equipa', value: MBWAY_PHONE }],
          },
          {
            text: 'Indica o montante exato. No campo de descrição, mensagem ou nota ao destinatário, escreve obrigatoriamente só a referência do teu pedido (a equipa cruza o pagamento com esta referência).',
            rows: [
              { label: 'Referência (obrigatória na descrição)', value: paymentRef, highlight: true },
              { label: 'Montante a enviar', value: totalLabel },
            ],
          },
          {
            text: 'Confirma o envio na app. No ecrã de confirmação ou na notificação, deves ver o montante e a referência. Guarda esse ecrã para o passo seguinte.',
          },
        ],
        'Depois de pagares: usa o campo «Comprovativo» mais abaixo para anexares uma foto do ecrã, uma captura de ecrã (printscreen) ou um PDF — o importante é que se leia o comprovativo da operação.'
      )
    } else {
      appendStepByStep(
        el,
        'Follow these steps in the MB Way app. Before you confirm, always check the recipient phone number matches the team details exactly.',
        [
          {
            text: 'Open MB Way and start sending money to a mobile number.',
          },
          {
            text: 'Verify the recipient — the phone number must match character for character:',
            rows: [{ label: 'Team MB Way number', value: MBWAY_PHONE }],
          },
          {
            text: 'Enter the exact amount. In the description or note to the recipient, you must enter only your booking reference:',
            rows: [
              { label: 'Reference (required in the note)', value: paymentRef, highlight: true },
              { label: 'Amount to send', value: totalLabel },
            ],
          },
          {
            text: "Confirm the payment. On the confirmation screen or notification you should see the amount and reference. Keep that screen for the next step.",
          },
        ],
        'After paying: use the «Proof of payment» field below to attach a screenshot, phone photo of the confirmation, or a PDF — it must clearly show the transaction proof.'
      )
    }
    return
  }

  if (method === 'transfer') {
    if (isPt) {
      appendStepByStep(
        el,
        'Faz uma transferência SEPA a partir do teu banco. Confirma IBAN e beneficiário carácter a carácter com os dados abaixo antes de autorizar.',
        [
          {
            text: 'Abre o homebanking ou a app do teu banco e escolhe transferência para um novo IBAN (ou gestão de beneficiários, se já tiveres o nosso IBAN guardado — confere na mesma se continua igual).',
          },
          {
            text: 'Verifica que o IBAN e o beneficiário são exatamente estes (erros no IBAN devolvem o dinheiro com atraso ou impedem o matching automático):',
            rows: [
              { label: 'Banco', value: BANK_NAME },
              { label: 'IBAN', value: BANK_IBAN, mono: true },
              { label: 'Beneficiário', value: 'Ecstatic Dance Viseu' },
            ],
          },
          {
            text: 'Preenche o montante e, no campo de descrição / informação ao destinatário, indica obrigatoriamente a referência abaixo (muitos bancos chamam-lhe «Descrição», «Informação» ou «Nota»).',
            rows: [
              { label: 'Referência (obrigatória)', value: paymentRef, highlight: true },
              { label: 'Montante', value: totalLabel },
            ],
          },
          {
            text: 'Autoriza a transferência. Quando o banco mostrar o comprovativo ou o PDF da operação, guarda-o ou faz captura de ecrã.',
          },
        ],
        'Anexa abaixo o PDF do banco ou uma imagem nítida do comprovativo / ecrã da app (o IBAN, montante e referência devem ser legíveis).'
      )
    } else {
      appendStepByStep(
        el,
        'Make a SEPA bank transfer. Verify the IBAN and beneficiary character by character before you authorise.',
        [
          {
            text: 'Open your bank’s website or app and start a transfer to a new IBAN.',
          },
          {
            text: 'The IBAN and beneficiary must match exactly:',
            rows: [
              { label: 'Bank', value: BANK_NAME },
              { label: 'IBAN', value: BANK_IBAN, mono: true },
              { label: 'Beneficiary', value: 'Ecstatic Dance Viseu' },
            ],
          },
          {
            text: 'Enter the amount and, in the transfer description / reference to beneficiary, include only:',
            rows: [
              { label: 'Reference (required)', value: paymentRef, highlight: true },
              { label: 'Amount', value: totalLabel },
            ],
          },
          {
            text: 'Authorise the transfer. Save the bank receipt or take a screenshot of the confirmation.',
          },
        ],
        'Below, upload the bank PDF or a clear image of the receipt (IBAN, amount and reference must be readable).'
      )
    }
    return
  }

  if (method === 'revolut') {
    if (isPt) {
      appendStepByStep(
        el,
        'Envia o valor pela Revolut. Confirma o utilizador (@tag) antes de tocar em enviar — deve ser exatamente o indicado abaixo.',
        [
          {
            text: 'Abre a Revolut, escolhe enviar dinheiro ou pagar a alguém e procura pelo utilizador pela tag.',
          },
          {
            text: 'Verifica a Revolut Tag do destinatário (atenção a @, maiúsculas e caracteres iguais):',
            rows: [{ label: 'Revolut — enviar para', value: REVOLUT_HANDLE, mono: true }],
          },
          {
            text: 'Indica o montante. No campo de nota do pagamento, escreve obrigatoriamente a referência do pedido — a equipa usa esta nota para identificar o teu pagamento.',
            rows: [
              { label: 'Nota do pagamento (obrigatória)', value: paymentRef, highlight: true },
              { label: 'Montante', value: totalLabel },
            ],
          },
          {
            text: 'Confirma o envio. No ecrã de conclusão, confirma montante e nota; guarda captura ou exporta recibo se a app permitir.',
          },
        ],
        'Carregas abaixo uma captura de ecrã ou foto do telemóvel com o comprovativo Revolut (montante e nota legíveis).'
      )
    } else {
      appendStepByStep(
        el,
        'Send via Revolut. Confirm the recipient @tag matches exactly before you send.',
        [
          {
            text: 'Open Revolut and start a payment to a Revolut user by tag.',
          },
          {
            text: 'The recipient tag must match:',
            rows: [{ label: 'Revolut — send to', value: REVOLUT_HANDLE, mono: true }],
          },
          {
            text: 'Enter the amount. In the payment note, you must include only your booking reference:',
            rows: [
              { label: 'Payment note (required)', value: paymentRef, highlight: true },
              { label: 'Amount', value: totalLabel },
            ],
          },
          {
            text: 'Confirm the payment. On the success screen, check amount and note; save a screenshot or receipt.',
          },
        ],
        'Upload below a screenshot or phone photo of the Revolut proof (amount and note must be readable).'
      )
    }
    return
  }

  const p = document.createElement('p')
  p.textContent = isPt ? `Questões? Escreve para ${infoEmail}.` : `Questions? Email ${infoEmail}.`
  el.appendChild(p)
}
