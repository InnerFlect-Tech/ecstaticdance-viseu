import { syncManualBookingLang } from './manual-booking.js'
import {
  getPricingState,
  getEventPricingConfig,
  isEarlyBirdPeriod,
  isEarlyBirdConfigured,
  defaultTicketMinEur,
  formatEarlyBirdUntil,
  loadActiveEventPricing,
} from './pricing.js'

/**
 * links.html — language toggle, sticky mobile CTA, inline booking panel (same page).
 */
function prefersReducedMotion() {
  return typeof matchMedia !== 'undefined' && matchMedia('(prefers-reduced-motion: reduce)').matches
}

function paintLinksDynamicPricing() {
  const min = defaultTicketMinEur()
  const early = isEarlyBirdPeriod()
  const returning = getPricingState().isReturning
  const cfg = getEventPricingConfig()
  const untilPt = formatEarlyBirdUntil(cfg.earlyBirdUntil, 'pt')
  const untilEn = formatEarlyBirdUntil(cfg.earlyBirdUntil, 'en')
  const pt = returning
    ? `Sliding scale deste ${min}€ — dançarino·a de regresso.`
    : early
      ? `Sliding scale desde ${min}€${untilPt ? ` · early bird até ${untilPt}` : ' · early bird'}`
      : `Sliding scale desde ${min}€`
  const en = returning
    ? `Sliding scale from €${min} — returning dancer`
    : early
      ? `Sliding scale from €${min}${untilEn ? ` · early bird through ${untilEn}` : ' · early bird'}`
      : `Sliding scale from €${min}`
  document.querySelectorAll('[data-links-price-pt]').forEach((el) => {
    el.textContent = pt
  })
  document.querySelectorAll('[data-links-price-en]').forEach((el) => {
    el.textContent = en
  })
}

function setLinksContribHintVisible(visible) {
  document.querySelectorAll('[data-links-contrib-hint]').forEach((el) => {
    el.hidden = !visible
  })
}

function paintLinksContributionEarly() {
  const early = isEarlyBirdPeriod()
  const returning = getPricingState().isReturning
  const cfg = getEventPricingConfig()
  const untilPt = formatEarlyBirdUntil(cfg.earlyBirdUntil, 'pt')
  const untilEn = formatEarlyBirdUntil(cfg.earlyBirdUntil, 'en')
  const standard = cfg.standardMinEur
  const earlyMin = cfg.earlyBirdMinEur

  let pt = ''
  let en = ''
  let showHint = false

  if (returning) {
    showHint = true
    pt = 'Já dançaste connosco? Com o mesmo email aplica-se o preço de regresso (desde 15€).'
    en = 'Already danced with us? Use the same email for the returning-dancer rate (from €15).'
  } else if (early && isEarlyBirdConfigured()) {
    showHint = true
    pt = untilPt
      ? `Early bird até ${untilPt} (inclusivo): sliding scale desde ${earlyMin}€. Depois passa para ${standard}€.`
      : `Early bird: sliding scale desde ${earlyMin}€. Depois passa para ${standard}€.`
    en = untilEn
      ? `Early bird through ${untilEn} (inclusive): sliding scale from €${earlyMin}. Then €${standard}.`
      : `Early bird: sliding scale from €${earlyMin}. Then €${standard}.`
  } else if (isEarlyBirdConfigured()) {
    showHint = true
    pt = untilPt
      ? `O early bird (desde ${earlyMin}€ até ${untilPt}) terminou. Sliding scale desde ${standard}€.`
      : `Sliding scale desde ${standard}€.`
    en = untilEn
      ? `Early bird (€${earlyMin} through ${untilEn}) has ended. Sliding scale from €${standard}.`
      : `Sliding scale from €${standard}.`
  }

  setLinksContribHintVisible(showHint)
  document.querySelectorAll('[data-links-contrib-early-pt]').forEach((el) => {
    el.textContent = pt
  })
  document.querySelectorAll('[data-links-contrib-early-en]').forEach((el) => {
    el.textContent = en
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
  const bar = document.getElementById('links-sticky-cta')
  // Observe the masthead element. With threshold:0, isIntersecting stays true while
  // any pixel of the masthead is visible, so the sticky bar only appears once the
  // entire masthead (including the CTA button) has scrolled completely out of view.
  // Observing only the CTA button or the sentinel div fails on narrow screens because
  // a tall masthead can push them below the fold even on page load.
  const target =
    document.querySelector('.links-masthead') || document.getElementById('links-primary-cta')
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
  window.addEventListener('edv:pricing-updated', () => {
    paintLinksDynamicPricing()
    paintLinksContributionEarly()
  })

  let stored = 'pt'
  try {
    stored = localStorage.getItem('edv_lang') === 'en' ? 'en' : 'pt'
  } catch {
    // ignore
  }
  setBodyLang(stored)
  loadActiveEventPricing().then(() => {
    paintLinksDynamicPricing()
    paintLinksContributionEarly()
  })
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
