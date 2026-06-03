/**
 * Formatação e pintura do evento activo em /links (alinhado com admin → eventos).
 */

/** @param {string | null | undefined} hm */
export function formatTimePt(hm) {
  if (!hm) return ''
  const [h, m] = hm.split(':')
  return `${parseInt(h, 10)}h${m}`
}

/** @param {string | null | undefined} hm */
export function formatTimeEn24(hm) {
  if (!hm) return ''
  return hm
}

/** @param {string | null | undefined} dateYmd */
export function formatDatePt(dateYmd) {
  if (!dateYmd) return ''
  const [y, m, d] = dateYmd.split('-').map(Number)
  const date = new Date(Date.UTC(y, m - 1, d, 12, 0, 0))
  return date.toLocaleDateString('pt-PT', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' })
}

/** @param {string | null | undefined} dateYmd */
export function formatDateEn(dateYmd) {
  if (!dateYmd) return ''
  const [y, m, d] = dateYmd.split('-').map(Number)
  const date = new Date(Date.UTC(y, m - 1, d, 12, 0, 0))
  return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' })
}

/** @param {string | null | undefined} dateYmd */
export function formatDateShortPt(dateYmd) {
  if (!dateYmd) return ''
  const [y, m, d] = dateYmd.split('-').map(Number)
  const date = new Date(Date.UTC(y, m - 1, d, 12, 0, 0))
  const month = date.toLocaleDateString('pt-PT', { month: 'short', timeZone: 'UTC' }).replace('.', '')
  return `${d} ${month}`
}

/** @param {string | null | undefined} dateYmd */
export function formatDateShortEn(dateYmd) {
  if (!dateYmd) return ''
  const [y, m, d] = dateYmd.split('-').map(Number)
  const date = new Date(Date.UTC(y, m - 1, d, 12, 0, 0))
  return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' })
}

/** @param {string} text */
function escHtml(text) {
  return String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

/**
 * @param {string | null | undefined} name
 * @param {string | null | undefined} ig
 * @param {'pt' | 'en'} lang
 */
function facilitatorLine(name, ig, lang) {
  const n = (name || '').trim()
  if (!n) {
    return lang === 'pt' ? 'a anunciar' : 'to be announced'
  }
  if (ig && ig.trim()) {
    const handle = ig.trim().replace(/^@/, '')
    return `${escHtml(n)} <a class="links-step-ig" href="https://www.instagram.com/${encodeURIComponent(handle)}/" target="_blank" rel="noopener noreferrer">@${escHtml(handle)}</a>`
  }
  return escHtml(n)
}

/**
 * @param {Record<string, unknown> | null | undefined} ev
 */
export function buildProgramSlots(ev) {
  if (!ev) return []
  const doorsOpen = /** @type {string | null} */ (ev.doors_open_hm ?? null)
  const warmup = /** @type {string | null} */ (ev.time_start_hm ?? null)
  const danceStart = /** @type {string | null} */ (ev.dance_start_hm ?? null)
  const danceEnd = /** @type {string | null} */ (ev.dance_end_hm ?? null)
  const integration = /** @type {string | null} */ (ev.integration_time_hm ?? null)
  const tea = /** @type {string | null} */ (ev.time_end_hm ?? null)
  const djName = String(ev.dj_name || '').trim()
  const djIg = String(ev.dj_instagram || '').trim()
  const warmupName = String(ev.warmup_name || '').trim()
  const warmupIg = String(ev.warmup_instagram || '').trim()
  const intName = String(ev.integration_name || '').trim()
  const intIg = String(ev.integration_instagram || '').trim()

  /** @type {Array<{ key: string, time: string, timeEnd?: string, titlePt: string, titleEn: string, bodyPt: string, bodyEn: string, spotlight?: boolean }>} */
  const slots = []

  if (doorsOpen) {
    slots.push({
      key: 'doors',
      time: doorsOpen,
      titlePt: 'Abertura de portas',
      titleEn: 'Doors open',
      bodyPt: 'Chegada ao espaço, acolhimento e tempo para instalares antes do warm-up.',
      bodyEn: 'Arrival, welcoming, and time to settle in before warm-up.',
    })
  }
  if (warmup) {
    const facPt = facilitatorLine(warmupName, warmupIg, 'pt')
    const facEn = facilitatorLine(warmupName, warmupIg, 'en')
    slots.push({
      key: 'warmup',
      time: warmup,
      titlePt: 'Warm-up corporal',
      titleEn: 'Body warm-up',
      bodyPt: warmupName
        ? `${facPt} — ativação corporal para chegares ao espaço.`
        : 'Facilitador·a a anunciar — ativação corporal para chegares ao espaço.',
      bodyEn: warmupName
        ? `${facEn} — body activation to arrive in the space.`
        : 'Facilitator to be announced — body activation to arrive in the space.',
    })
  }
  if (danceStart && danceEnd) {
    const danceLabel = djName || 'DJ a anunciar'
    const igPart =
      djIg !== ''
        ? ` <a class="links-step-ig" href="https://www.instagram.com/${encodeURIComponent(djIg.replace(/^@/, ''))}/" target="_blank" rel="noopener noreferrer">@${escHtml(djIg.replace(/^@/, ''))}</a>`
        : ''
    slots.push({
      key: 'dance',
      time: danceStart,
      timeEnd: danceEnd,
      titlePt: 'Ecstatic Dance',
      titleEn: 'Ecstatic Dance',
      bodyPt: `${escHtml(danceLabel)} — DJ set de dança ecstática. Pista livre, sem conversa na dança.${igPart}`,
      bodyEn: `${escHtml(danceLabel)} — ecstatic dance DJ set. Open floor, silence on the dance floor.${igPart}`,
      spotlight: true,
    })
  }
  if (integration) {
    const facPt = facilitatorLine(intName, intIg, 'pt')
    const facEn = facilitatorLine(intName, intIg, 'en')
    slots.push({
      key: 'integration',
      time: integration,
      titlePt: 'Integração',
      titleEn: 'Integration',
      bodyPt: intName
        ? `${facPt} — espaço de integração após a dança.`
        : 'Facilitação a anunciar — espaço de integração após a dança.',
      bodyEn: intName
        ? `${facEn} — integration space after the dance.`
        : 'Facilitation to be announced — integration space after the dance.',
    })
  }
  if (tea) {
    slots.push({
      key: 'tea',
      time: tea,
      titlePt: 'Chá e convívio',
      titleEn: 'Tea & gathering',
      bodyPt:
        'Espaço de partilha, descanso e presença. Quem reservar jantar ou quiser comprá-lo no espaço pode ficar a jantar.',
      bodyEn:
        'Sharing, rest and presence. If you book dinner or want to buy it on-site, you can stay for your meal.',
    })
  }

  return slots
}

/**
 * @param {string | null | undefined} location
 * @param {string | null | undefined} url
 */
function locationHtml(location, url) {
  const loc = (location || 'Viseu').trim()
  if (url && url.trim()) {
    return `<a href="${escHtml(url.trim())}" target="_blank" rel="noopener noreferrer">${escHtml(loc)}</a>`
  }
  return escHtml(loc)
}

/**
 * @param {Record<string, unknown> | null | undefined} ev
 */
export function buildLineupSummary(ev) {
  if (!ev) return { pt: '', en: '' }
  const dj = String(ev.dj_name || '').trim()
  const warmup = String(ev.warmup_name || '').trim()
  const integration = String(ev.integration_name || '').trim()

  const partsPt = []
  const partsEn = []
  if (dj) {
    partsPt.push(`${escHtml(dj)} <span class="links-masthead-lineup-role">DJ</span>`)
    partsEn.push(`${escHtml(dj)} <span class="links-masthead-lineup-role">DJ</span>`)
  }
  const warmPt = warmup
    ? `${escHtml(warmup)} <span class="links-masthead-lineup-role">warm-up</span>`
    : `<span class="links-masthead-lineup-role">warm-up a anunciar</span>`
  const warmEn = warmup
    ? `${escHtml(warmup)} <span class="links-masthead-lineup-role">warm-up</span>`
    : `<span class="links-masthead-lineup-role">warm-up to be announced</span>`
  const intPt = integration
    ? `${escHtml(integration)} <span class="links-masthead-lineup-role">integração</span>`
    : `<span class="links-masthead-lineup-role">integração a anunciar</span>`
  const intEn = integration
    ? `${escHtml(integration)} <span class="links-masthead-lineup-role">integration</span>`
    : `<span class="links-masthead-lineup-role">integration to be announced</span>`

  partsPt.push(warmPt, intPt)
  partsEn.push(warmEn, intEn)

  return {
    pt: partsPt.join(' · '),
    en: partsEn.join(' · '),
  }
}

/**
 * @param {HTMLElement | null} mount
 * @param {ReturnType<typeof buildProgramSlots>} slots
 */
function renderTimeline(mount, slots) {
  if (!mount) return
  const icons = {
    doors: '<path d="M4 15s3-4 8-4 8 4 8 4M4 15v3h16v-3"/><path d="M12 8V5M9 8h6"/>',
    warmup: '<path d="M4 15s3-4 8-4 8 4 8 4M4 15v3h16v-3"/><path d="M9 10V6l2-2h2l2 2v4"/>',
    dance: '<path d="M2 12c4-6 7-6 10 0s6 6 10 0"/>',
    integration: '<path d="M12 3c-4 8-4 12 0 18 4-6 4-10 0-18z"/>',
    tea: '<path d="M18 8h1a3 3 0 0 1 0 6h-1M2 8h16v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2"/><path d="M6 8V6a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
  }
  mount.innerHTML = slots
    .map((slot) => {
      const timeLabel =
        slot.timeEnd && slot.timeEnd !== slot.time
          ? `${formatTimePt(slot.time)}–${formatTimePt(slot.timeEnd)}`
          : formatTimePt(slot.time)
      const icon = icons[/** @type {keyof typeof icons} */ (slot.key)] || icons.doors
      return `<div class="links-timeline-h-item" role="listitem">
        <svg class="links-timeline-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" aria-hidden="true">${icon}</svg>
        <span class="links-timeline-time">${timeLabel}</span>
        <span class="links-timeline-label lang-pt">${escHtml(slot.titlePt)}</span>
        <span class="links-timeline-label lang-en">${escHtml(slot.titleEn)}</span>
      </div>`
    })
    .join('')
}

/**
 * @param {HTMLElement | null} mount
 * @param {ReturnType<typeof buildProgramSlots>} slots
 */
function renderProgramSteps(mount, slots) {
  if (!mount) return
  const dotIcons = {
    doors: '<path d="M3.5 7h7M7 3.5v7"/><circle cx="7" cy="7" r="5.5"/>',
    warmup: '<circle cx="7" cy="7" r="5.5"/><path d="M7 4v3.2l1.8 1.1"/>',
    dance: '<path d="M1 8 Q3.5 3 7 8 Q10.5 13 13 8"/><path d="M4 5.5 Q5.5 2.5 7 5.5"/>',
    integration: '<circle cx="7" cy="5" r="3"/><path d="M3 13c0-2.2 1.8-4 4-4s4 1.8 4 4"/>',
    tea: '<path d="M2 7 Q7 3 12 7"/><path d="M4 7v4M10 7v4M2 11h10"/>',
  }
  mount.innerHTML = slots
    .map((slot, i) => {
      const delay = i > 0 ? ` reveal-delay-${Math.min(i, 4)}` : ''
      const spotlight =
        slot.spotlight ? ' links-experience-step--spotlight' : slot.key === 'integration' || slot.key === 'tea' ? ' links-experience-step--wind-down' : ''
      const timeWhen =
        slot.timeEnd && slot.timeEnd !== slot.time
          ? `<span class="links-experience-time">${formatTimePt(slot.time)} – ${formatTimePt(slot.timeEnd)}</span>`
          : `<span class="links-experience-time">${formatTimePt(slot.time)}</span>`
      const icon = dotIcons[/** @type {keyof typeof dotIcons} */ (slot.key)] || dotIcons.doors
      return `<li class="links-experience-step${spotlight} reveal${delay}">
        <span class="links-experience-dot" aria-hidden="true">
          <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round">${icon}</svg>
        </span>
        <div class="links-experience-step-surface">
          <span class="links-experience-when">
            ${timeWhen}<span class="links-experience-when-sep"> — </span><span class="links-experience-slot-title"><span class="lang-pt">${escHtml(slot.titlePt)}</span><span class="lang-en">${escHtml(slot.titleEn)}</span></span>
          </span>
          <p class="lang-pt">${slot.bodyPt}</p>
          <p class="lang-en">${slot.bodyEn}</p>
        </div>
      </li>`
    })
    .join('')
}

/**
 * @param {Record<string, unknown> | null | undefined} ev
 */
export function paintLinksPageFromEvent(ev) {
  const page = document.getElementById('links-page')
  const noEvent = document.getElementById('links-no-event')
  const masthead = document.querySelector('.links-masthead-body')
  const bookingShell = document.getElementById('links-inline-booking-mount')

  if (!ev) {
    page?.classList.add('links-page--no-event')
    if (noEvent) noEvent.hidden = false
    if (masthead) masthead.hidden = true
    if (bookingShell) bookingShell.hidden = true
    document.querySelectorAll('#links-primary-cta, #links-sticky-cta, .links-foot-cta').forEach((el) => {
      if (el instanceof HTMLElement) el.hidden = true
    })
    return
  }

  page?.classList.remove('links-page--no-event')
  if (noEvent) noEvent.hidden = true
  if (masthead) masthead.hidden = false
  if (bookingShell) bookingShell.hidden = false
  document.querySelectorAll('#links-primary-cta, #links-sticky-cta, .links-foot-cta').forEach((el) => {
    if (el instanceof HTMLElement) el.hidden = false
  })

  const title = String(ev.title || 'Ecstatic Dance Viseu')
  const description = String(ev.description || '').trim()
  const date = String(ev.date || '')
  const location = String(ev.location || 'Viseu')
  const locationUrl = String(ev.location_url || '')
  const doorsOpen = /** @type {string | null} */ (ev.doors_open_hm ?? null)
  const doorsClose = /** @type {string | null} */ (ev.doors_close_hm ?? null)
  const slug = String(ev.slug || (date ? `edv-${date}` : ''))

  const slugInput = document.getElementById('lb_event_slug')
  if (slugInput instanceof HTMLInputElement && slug) {
    slugInput.value = slug
  }

  document.querySelectorAll('[data-links-field="title"]').forEach((el) => {
    el.textContent = title
  })

  document.querySelectorAll('[data-links-field="tagline-pt"]').forEach((el) => {
    el.textContent =
      description ||
      'Uma jornada livre ao som da música — presença plena, em comunidade.'
  })
  document.querySelectorAll('[data-links-field="tagline-en"]').forEach((el) => {
    el.textContent =
      description ||
      'A free-form journey in music — full presence, together.'
  })

  const lineup = buildLineupSummary(ev)
  const lineupPt = document.querySelector('[data-links-field="lineup-pt"]')
  const lineupEn = document.querySelector('[data-links-field="lineup-en"]')
  if (lineupPt) lineupPt.innerHTML = lineup.pt
  if (lineupEn) lineupEn.innerHTML = lineup.en

  const venueHoursPt = []
  const venueHoursEn = []
  if (doorsOpen) {
    venueHoursPt.push(`${formatTimePt(doorsOpen)} — o evento começa (abertura de portas)`)
    venueHoursEn.push(`${formatTimeEn24(doorsOpen)} — event starts (doors open)`)
  }
  if (doorsClose) {
    venueHoursPt.push(`${formatTimePt(doorsClose)} fecho de portas`)
    venueHoursEn.push(`doors close ${formatTimeEn24(doorsClose)}`)
  }
  document.querySelectorAll('[data-links-field="venue-hours-pt"]').forEach((el) => {
    el.textContent = venueHoursPt.join(' · ')
  })
  document.querySelectorAll('[data-links-field="venue-hours-en"]').forEach((el) => {
    el.textContent = venueHoursEn.join(' · ')
  })

  document.querySelectorAll('[data-links-field="date-pt"]').forEach((el) => {
    el.textContent = formatDatePt(date)
  })
  document.querySelectorAll('[data-links-field="date-en"]').forEach((el) => {
    el.textContent = formatDateEn(date)
  })
  document.querySelectorAll('[data-links-field="date-short-pt"]').forEach((el) => {
    el.textContent = formatDateShortPt(date)
  })
  document.querySelectorAll('[data-links-field="date-short-en"]').forEach((el) => {
    el.textContent = formatDateShortEn(date)
  })

  document.querySelectorAll('[data-links-field="location-pt"], [data-links-field="location-en"]').forEach((el) => {
    el.innerHTML = locationHtml(location, locationUrl)
  })

  const stickyLoc = document.querySelector('[data-links-field="sticky-location"]')
  if (stickyLoc) {
    const shortLoc = location.split(',')[0].trim() || location
    stickyLoc.textContent = shortLoc
  }

  const slots = buildProgramSlots(ev)
  renderTimeline(document.getElementById('links-timeline-mount'), slots)
  renderProgramSteps(document.getElementById('links-program-mount'), slots)

  const details = document.getElementById('links-programa-completo')
  if (details) details.hidden = slots.length === 0

  const descCard = document.querySelector('[data-links-field="description-pt"]')
  const descCardEn = document.querySelector('[data-links-field="description-en"]')
  if (description) {
    if (descCard) descCard.textContent = description
    if (descCardEn) descCardEn.textContent = description
  }

  if (title && document.title) {
    document.title = `${title} · Links — Ecstatic Dance Viseu`
  }

  window.dispatchEvent(new CustomEvent('edv:active-event-loaded', { detail: ev }))
}
