import { syncManualBookingLang } from './manual-booking.js'
import { isEarlyBirdPeriod, ticketMinEur } from './pricing.js'

/**
 * links.html — language toggle, sticky mobile CTA, inline booking panel (same page).
 */
function prefersReducedMotion() {
  return typeof matchMedia !== 'undefined' && matchMedia('(prefers-reduced-motion: reduce)').matches
}

function paintLinksDynamicPricing() {
  const min = ticketMinEur()
  const early = isEarlyBirdPeriod()
  const pt = early
    ? `Sliding scale desde ${min}€ · early bird até 5 de maio`
    : `Sliding scale desde ${min}€`
  const en = early
    ? `Sliding scale from €${min} · early bird through 5 May`
    : `Sliding scale from €${min}`
  document.querySelectorAll('[data-links-price-pt]').forEach((el) => {
    el.textContent = pt
  })
  document.querySelectorAll('[data-links-price-en]').forEach((el) => {
    el.textContent = en
  })
}

function paintLinksContributionEarly() {
  const early = isEarlyBirdPeriod()
  document.querySelectorAll('[data-links-contrib-early-pt]').forEach((el) => {
    el.textContent = early
      ? 'Early bird: o bilhete é 20€ até 5 de maio.'
      : 'Piso mínimo 30€ (early bird de 20€ até 5 de maio já terminou).'
  })
  document.querySelectorAll('[data-links-contrib-early-en]').forEach((el) => {
    el.textContent = early
      ? 'Early bird: the ticket is €20 through 5 May.'
      : 'Minimum €30 (early bird at €20 through 5 May has ended).'
  })
}

function initHeroVisualFallback() {
  const img = document.querySelector('.links-hero-visual-img')
  const fig = img?.closest('.links-hero-visual')
  if (!img || !fig) return
  img.addEventListener('error', () => {
    fig.classList.add('links-hero-visual--fallback')
  })
}

/** Retorno ao /links após compra online: não abrir o painel «Pedir bilhete». */
function isLinksReturnAfterOnlineCheckout() {
  const p = new URLSearchParams(window.location.search)
  return p.has('session_id') || p.get('checkout_success') === '1'
}

function closeLinksInlineBooking() {
  const shell = document.getElementById('links-inline-booking-mount')
  if (!shell) return

  document.body.classList.remove('links-hub-booking-open')

  shell.classList.add('links-inline-booking-shell--collapsed')
  shell.classList.remove('links-inline-booking-shell--open')
  shell.setAttribute('aria-hidden', 'true')
  shell.setAttribute('inert', '')

  shell.querySelectorAll('.links-inline-booking-content .reveal').forEach((el) => {
    el.classList.remove('visible')
  })
}

/**
 * @param {{ scroll?: boolean }} [opts]
 */
function openLinksInlineBooking(opts) {
  const shell = document.getElementById('links-inline-booking-mount')
  if (!shell) return

  const scroll = opts?.scroll !== false
  const reduced = prefersReducedMotion()

  document.body.classList.add('links-hub-booking-open')

  shell.classList.remove('links-inline-booking-shell--collapsed')
  shell.classList.add('links-inline-booking-shell--open')
  shell.removeAttribute('inert')
  shell.setAttribute('aria-hidden', 'false')

  shell.querySelectorAll('.links-inline-booking-content .reveal').forEach((el) => {
    el.classList.add('visible')
  })

  const heading = document.getElementById('lb_step_1_title')
  if (heading) {
    heading.setAttribute('tabindex', '-1')
    if (!reduced) {
      requestAnimationFrame(() => heading.focus({ preventScroll: true }))
    } else {
      heading.focus({ preventScroll: true })
    }
  }

  if (scroll) {
    const runScroll = () => {
      const target = document.getElementById('reserva-manual')
      if (!target) return
      target.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' })
    }
    if (reduced) runScroll()
    else requestAnimationFrame(() => requestAnimationFrame(runScroll))
  }

  try {
    const u = new URL(window.location.href)
    u.hash = 'reserva-manual'
    history.replaceState(null, '', u.pathname + u.search + u.hash)
  } catch {
    // ignore
  }
}

function initInlineBooking() {
  const shell = document.getElementById('links-inline-booking-mount')
  if (!shell) return

  const go = (ev) => {
    ev.preventDefault()
    openLinksInlineBooking({ scroll: true })
  }

  document.getElementById('links-primary-cta')?.addEventListener('click', go)
  document.getElementById('links-sticky-booking-btn')?.addEventListener('click', go)
  document.getElementById('links-footer-cta')?.addEventListener('click', go)

  const skipOpenAfterCheckout = isLinksReturnAfterOnlineCheckout()
  if (skipOpenAfterCheckout) {
    closeLinksInlineBooking()
    if (location.hash === '#reserva-manual') {
      try {
        const u = new URL(window.location.href)
        u.hash = ''
        history.replaceState(null, '', u.pathname + u.search + u.hash)
      } catch {
        // ignore
      }
    }
  } else if (location.hash === '#reserva-manual') {
    openLinksInlineBooking({ scroll: true })
  }
}

function setBodyLang(lang) {
  document.body.classList.remove('lang-pt', 'lang-en')
  document.body.classList.add(lang === 'en' ? 'lang-en' : 'lang-pt')
  try {
    localStorage.setItem('edv_lang', lang === 'en' ? 'en' : 'pt')
  } catch {
    // ignore
  }
  syncManualBookingLang(lang === 'en' ? 'en' : 'pt')
  paintLinksDynamicPricing()
  paintLinksContributionEarly()
}

function initStickyCta() {
  const page = document.getElementById('links-page')
  const sentinel = document.getElementById('links-sticky-sentinel')
  const fallbackSentinel = document.getElementById('links-primary-cta')
  const bar = document.getElementById('links-sticky-cta')
  const target = sentinel || fallbackSentinel
  if (!page || !target || !bar || typeof IntersectionObserver === 'undefined') return

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
    observer.observe(target)
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
  document.getElementById('lang-pt')?.addEventListener('click', () => setBodyLang('pt'))
  document.getElementById('lang-en')?.addEventListener('click', () => setBodyLang('en'))
  initHeroVisualFallback()
  initStickyCta()
  initInlineBooking()
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init)
} else {
  init()
}
