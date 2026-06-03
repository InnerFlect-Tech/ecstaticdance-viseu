<?php
declare(strict_types=1);
/* ── Admin shared nav styles (topbar + bottom-tab-bar) ── */
?>
  /* ─────────────────────────────────────────────
     TOP BAR — brand row + contextual actions
  ───────────────────────────────────────────── */
  .topbar {
    background: var(--dark-m);
    border-bottom: 1px solid rgba(245,239,230,.07);
    padding: 0 1rem;
    height: 52px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    position: sticky;
    top: 0;
    z-index: 40;
  }
  @media (min-width: 768px) {
    .topbar { padding: 0 1.5rem; height: 56px; }
  }
  @media (min-width: 1200px) {
    .topbar { padding: 0 2rem; }
  }

  .topbar-brand {
    font-size: 0.6rem;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: var(--gold);
    font-weight: 400;
    flex-shrink: 1;
    min-width: 0;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
  }

  /* Desktop inline nav (hidden on mobile — replaced by bottom tabs) */
  .topbar-nav {
    display: none;
  }
  @media (min-width: 768px) {
    .topbar-nav {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 0.3rem;
      row-gap: 0.35rem;
      flex: 1;
      justify-content: center;
    }
  }

  /* Right-side contextual actions */
  .topbar-ctx {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    flex-shrink: 0;
  }

  /* ── shared button base ── */
  .tb-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    height: 36px;
    border-radius: 10px;
    font-size: 0.68rem;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    font-family: inherit;
    font-weight: 500;
    border: 1px solid transparent;
    cursor: pointer;
    white-space: nowrap;
    flex-shrink: 0;
    -webkit-tap-highlight-color: transparent;
    transition: background 0.15s, border-color 0.15s, color 0.15s;
    text-decoration: none;
  }
  .tb-btn svg { width: 17px; height: 17px; flex-shrink: 0; }

  /* icon-only (top-bar secondary) */
  .tb-icon-btn {
    width: 36px;
    padding: 0;
    background: rgba(245,239,230,0.04);
    border-color: rgba(245,239,230,0.1);
    color: rgba(245,239,230,0.6);
  }
  .tb-icon-btn:hover { background: rgba(245,239,230,0.09); color: var(--bone); }

  /* pill (top-bar inline nav on desktop) */
  .tb-pill {
    padding: 0 0.9rem;
    background: transparent;
    color: rgba(245,239,230,0.55);
    border-color: transparent;
    gap: 0.35rem;
  }
  .tb-pill:hover { background: rgba(245,239,230,0.06); color: var(--bone); }
  .tb-pill.is-active {
    background: rgba(184,146,74,0.1);
    border-color: rgba(184,146,74,0.28);
    color: var(--gold-l);
  }

  /* Scan button (desktop nav centre + legacy fab style) */
  .tb-scan-fab {
    padding: 0 0.75rem;
    background: linear-gradient(145deg, #2a4a3d, var(--verde));
    border-color: rgba(64,145,108,0.3);
    color: var(--bone);
    height: 36px;
    font-size: 0.62rem;
  }
  .tb-scan-fab:hover { filter: brightness(1.1); }
  .tb-scan-fab.is-active {
    box-shadow: 0 0 0 1px rgba(212,168,90,0.45);
    color: var(--gold-l);
  }
  /* Compact label on narrow desktop nav */
  @media (min-width: 768px) and (max-width: 900px) {
    .tb-scan-nav .tb-scan-label { display: none; }
    .tb-scan-nav { padding: 0 0.65rem; min-width: 36px; }
  }

  /* Mobile: barra inferior visível — sem Scan nem Sair no topo (ficam na bottom bar) */
  @media (max-width: 767px) {
    .topbar-nav .tb-scan-nav {
      display: none !important;
    }
    .topbar-ctx .tb-logout {
      display: none !important;
    }
  }

  /* Sair no topo: só em desktop (em mobile usa-se a bottom tab) */
  .tb-logout-label { display: none; }
  @media (min-width: 768px) {
    .tb-logout-label { display: inline; }
    .tb-icon-btn.tb-logout { width: auto; padding: 0 0.8rem; }
  }

  /* ─────────────────────────────────────────────
     BOTTOM TAB BAR — mobile primary navigation
     Hidden ≥ 768 px (replaced by topbar-nav)
  ───────────────────────────────────────────── */
  .bottom-tabs {
    display: flex;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 50;
    background: var(--dark-m);
    border-top: 1px solid rgba(245,239,230,0.08);
    /* iPhone home-indicator safe area */
    padding-bottom: env(safe-area-inset-bottom, 0px);
  }
  @media (min-width: 768px) {
    .bottom-tabs { display: none; }
  }

  .btab {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    min-height: 58px;
    padding: 8px 4px 6px;
    color: rgba(245,239,230,0.45);
    font-size: 0.55rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    font-weight: 500;
    -webkit-tap-highlight-color: transparent;
    text-decoration: none;
    cursor: pointer;
    border: none;
    background: none;
    font-family: inherit;
    position: relative;
    transition: color 0.15s;
  }
  .btab svg {
    width: 22px;
    height: 22px;
    stroke-width: 1.75;
    flex-shrink: 0;
  }
  .btab .btab-label { line-height: 1; }

  /* active tab — filled icon tint + label highlight */
  .btab.is-active { color: var(--gold-l); }
  .btab.is-active::after {
    content: '';
    position: absolute;
    top: 0;
    left: 18%;
    right: 18%;
    height: 2px;
    background: var(--gold-l);
    border-radius: 0 0 2px 2px;
  }

  .btab:active { opacity: 0.7; }

  /* Analytics tab — slightly brighter accent */
  .btab.btab-analytics.is-active { color: var(--terra-l); }
  .btab.btab-analytics.is-active::after { background: var(--terra-l); }

  /* Scan tab in bottom bar (special — prominent) */
  .btab.btab-scan {
    flex: none;
    width: 64px;
    background: none;
  }
  .btab.btab-scan .btab-icon-wrap {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    background: linear-gradient(145deg, var(--verde-m), #162820);
    border: 1px solid rgba(64,145,108,0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 0;
    box-shadow: 0 2px 12px rgba(0,0,0,0.35);
    transition: filter 0.15s;
  }
  .btab.btab-scan:active .btab-icon-wrap { filter: brightness(1.15); }
  .btab.btab-scan svg { width: 22px; height: 22px; color: #5dc29a; }
  .btab.btab-scan.is-active .btab-icon-wrap {
    border-color: rgba(212,168,90,0.55);
    box-shadow: 0 0 0 1px rgba(212,168,90,0.25);
  }
  .btab-scan-sub {
    font-size: 0.5rem !important;
    color: rgba(245,239,230,0.35) !important;
    letter-spacing: 0.1em;
  }

  /* ── Safe-area body padding (so content isn't hidden behind bottom tabs) ── */
  .has-bottom-tabs {
    padding-bottom: calc(58px + env(safe-area-inset-bottom, 0px));
  }
  @media (min-width: 768px) {
    .has-bottom-tabs { padding-bottom: 0; }
  }
