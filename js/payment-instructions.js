/** Revolut username shown in the link-booking payment step (no @ in some UIs, we keep @ in copy). */
const REVOLUT_HANDLE = '@carolina_gomes92'

/**
 * Renders localised payment copy for the link-booking flow (no HTML from server).
 * @param {'mbway' | 'transfer' | 'revolut'} method
 * @param {{ paymentRef: string, totalLabel: string, infoEmail: string }} ctx
 * @param {HTMLElement} el
 */
export function renderPaymentInstructions(method, ctx, el) {
  if (!el) return
  const { paymentRef, totalLabel, infoEmail } = ctx
  const isPt = document.body.classList.contains('lang-pt')
  const lines = {
    mbway: isPt
      ? [
          'MB Way: envia o valor indicado abaixo para o número de telemóvel que a equipa te partilhar (ou escreve para ' + infoEmail + ').',
          'Na descrição da transferência, usa obrigatoriamente a referência:',
        ]
      : [
          'MB Way: send the amount below to the phone number we share (or write to ' + infoEmail + ').',
          'In the transfer description, use this reference:',
        ],
    transfer: isPt
      ? [
          'Transferência bancária (SEPA): se precisares de IBAN, envia email para ' + infoEmail + ' ou responde à confirmação da equipa.',
          'Indica a referência no campo de notas do banco:',
        ]
      : [
          'Bank transfer (SEPA): if you need IBAN details, email ' + infoEmail + ' or reply to our confirmation message.',
          'Put the reference in the transfer notes:',
        ],
    revolut: isPt
      ? [
          'Revolut: envia o pagamento na app para ' + REVOLUT_HANDLE + '. Em caso de dúvida, ' + infoEmail + '.',
          'Na descrição ou nota do pagamento, indica obrigatoriamente a referência:',
        ]
      : [
          'Revolut: send the payment in the app to ' + REVOLUT_HANDLE + '. Questions: ' + infoEmail + '.',
          'In the payment description or note, include this reference:',
        ],
  }
  const key = method in lines ? method : 'transfer'
  const parts = lines[key]
  el.innerHTML = ''
  const p1 = document.createElement('p')
  p1.textContent = parts[0]
  const p2 = document.createElement('p')
  p2.textContent = parts[1]
  const pre = document.createElement('div')
  pre.className = 'links-ref-box'
  pre.setAttribute('role', 'status')
  const refSpan = document.createElement('strong')
  refSpan.className = 'links-payment-ref'
  refSpan.textContent = paymentRef
  pre.appendChild(refSpan)
  const p3 = document.createElement('p')
  p3.className = 'links-amount-confirm'
  p3.textContent = isPt ? 'Montante: ' + totalLabel : 'Amount: ' + totalLabel
  el.append(p1, p2, pre, p3)
}
