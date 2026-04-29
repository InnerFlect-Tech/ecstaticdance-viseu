/**
 * links.html — language toggle (body .lang-pt / .lang-en), pricing cards sync,
 * buy flow in <dialog> + iframe, sticky mobile CTA when the primary CTA scrolls out of view.
 */
import { isEarlyBird, ticketMinEur } from './pricing.js'

function setBodyLang(lang) {
  document.body.classList.remove('lang-pt', 'lang-en')
  document.body.classList.add(lang === 'en' ? 'lang-en' : 'lang-pt')
  try {
    localStorage.setItem('edv_lang', lang === 'en' ? 'en' : 'pt')
  } catch {
    // ignore
  }
}

function syncPricingCards() {
  const early = document.getElementById('links-pricing-early')
  const regular = document.getElementById('links-pricing-regular')
  if (!early || !regular) return
  if (isEarlyBird()) {
    early.removeAttribute('hidden')
    regular.setAttribute('hidden', '')
  } else {
    early.setAttribute('hidden', '')
    regular.removeAttribute('hidden')
  }
}

function syncScaleMinLabels() {
  const min = String(ticketMinEur())
  document.getElementById('links-scale-min-val')?.replaceChildren(document.createTextNode(min))
  document.getElementById('links-scale-min-val-en')?.replaceChildren(document.createTextNode(min))
  document.getElementById('links-scale-tick-min')?.replaceChildren(document.createTextNode(min))
}

function initBuyDialog() {
  const dialog = document.getElementById('links-buy-dialog')
  const iframe = document.getElementById('links-buy-dialog-iframe')
  const scrim = document.getElementById('links-buy-dialog-scrim')
  const panel = dialog?.querySelector('.links-buy-dialog-panel')
  if (!dialog || !iframe || !panel || !(dialog instanceof HTMLDialogElement)) return

  /** @type {HTMLElement | null} */
  let triggerEl = null

  function openWithTarget(/** @type {'reservar' | 'reserva-manual'} */ target) {
    const hash = target === 'reserva-manual' ? 'reserva-manual' : 'reservar'
    const src = new URL(`/buy?modal=1#${hash}`, window.location.href).href

    const openTab = document.getElementById('links-buy-dialog-open-tab')
    if (openTab instanceof HTMLAnchorElement) {
      openTab.href = new URL(`/buy#${hash}`, window.location.href).href
    }

    iframe.setAttribute('src', src)

    queueMicrotask(() => {
      requestAnimationFrame(() => {
        dialog.showModal()
      })
    })
  }

  function onDialogClose() {
    if (triggerEl && typeof triggerEl.focus === 'function') {
      triggerEl.focus()
    }
    triggerEl = null
  }

  const closeBtns = dialog.querySelectorAll('.links-buy-dialog-close, .links-buy-dialog-scrim')
  closeBtns.forEach((btn) => btn.addEventListener('click', () => dialog.close()))
  dialog.addEventListener('close', onDialogClose)
  dialog.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') dialog.close()
  })

  document.querySelectorAll('a.js-buy-dialog-trigger').forEach((a) => {
    if (!(a instanceof HTMLAnchorElement)) return
    a.addEventListener('click', (e) => {
      const raw = a.dataset.edvBuyTarget
      const target = raw === 'reserva-manual' ? 'reserva-manual' : 'reservar'
      if (typeof HTMLDialogElement === 'undefined') return
      e.preventDefault()
      triggerEl = a
      openWithTarget(target)
    })
  })
}

function initStickyCta() {
  const page = document.getElementById('links-page')
  const sentinel = document.getElementById('links-primary-cta')
  const bar = document.getElementById('links-sticky-cta')
  if (!page || !sentinel || !bar || typeof IntersectionObserver === 'undefined') return

  const mqWide = window.matchMedia('(min-width: 768px)')
  let observer = null

  function setBarVisible(visible) {
    bar.classList.toggle('links-sticky-cta--visible', visible)
    bar.setAttribute('aria-hidden', visible ? 'false' : 'true')
    page.classList.toggle('has-sticky-cta', visible)
  }

  function attach() {
    if (observer) {
      observer.disconnect()
      observer = null
    }
    if (mqWide.matches) {
      setBarVisible(false)
      return
    }
    observer = new IntersectionObserver(
      (entries) => {
        const entry = entries[0]
        setBarVisible(!entry.isIntersecting)
      },
      { root: null, rootMargin: '0px', threshold: 0 }
    )
    observer.observe(sentinel)
  }

  attach()
  mqWide.addEventListener('change', attach)
}

function init() {
  let stored = 'pt'
  try {
    stored = localStorage.getItem('edv_lang') === 'en' ? 'en' : 'pt'
  } catch {
    // ignore
  }
  setBodyLang(stored)
  syncPricingCards()
  syncScaleMinLabels()
  document.getElementById('lang-pt')?.addEventListener('click', () => setBodyLang('pt'))
  document.getElementById('lang-en')?.addEventListener('click', () => setBodyLang('en'))
  initStickyCta()
  initBuyDialog()
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init)
} else {
  init()
}
