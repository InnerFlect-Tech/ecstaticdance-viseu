/* ============================================================
   ECSTATIC DANCE VISEU — Main JavaScript (home page)
   ============================================================ */

/* ─── NAV: bg on scroll ─── */
const nav = document.getElementById('nav');
function handleNavScroll() {
  nav.classList.toggle('bg', window.scrollY > 80);
}
window.addEventListener('scroll', handleNavScroll, { passive: true });

/* ─── PARALLAX ─── */
const heroBg       = document.getElementById('heroBg');
const pb1          = document.getElementById('pb1');
const parallaxImg1 = document.getElementById('parallaxImg1');
const pb2          = document.getElementById('pb2');
const parallaxImg2 = document.getElementById('parallaxImg2');

function handleParallax() {
  const y = window.scrollY;

  if (heroBg) {
    heroBg.style.transform = `scale(1.08) translateY(${y * 0.25}px)`;
  }
  if (parallaxImg1 && pb1) {
    const rect = pb1.getBoundingClientRect();
    parallaxImg1.style.transform = `translateY(${-rect.top * 0.2}px)`;
  }
  if (parallaxImg2 && pb2) {
    const rect = pb2.getBoundingClientRect();
    parallaxImg2.style.transform = `translateY(${-rect.top * 0.2}px)`;
  }
}
window.addEventListener('scroll', handleParallax, { passive: true });

/* ─── SCROLL REVEAL ─── */
const revealEls = document.querySelectorAll('.reveal, .reveal-left, .reveal-right');
const revealObserver = new IntersectionObserver(
  (entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        revealObserver.unobserve(entry.target);
      }
    });
  },
  { threshold: 0.12, rootMargin: '0px 0px -40px 0px' }
);
revealEls.forEach((el) => revealObserver.observe(el));

/* ─── SMOOTH SCROLL for anchor links ─── */
document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
  anchor.addEventListener('click', (e) => {
    const target = document.querySelector(anchor.getAttribute('href'));
    if (target) {
      e.preventDefault();
      target.scrollIntoView({ behavior: 'smooth' });
    }
  });
});

/* ─── MOBILE NAV TOGGLE ─── */
const menuToggle = document.getElementById('menuToggle');
const mobileMenu = document.getElementById('mobileMenu');

if (menuToggle && mobileMenu) {
  menuToggle.addEventListener('click', () => {
    const isOpen = mobileMenu.classList.toggle('open');
    menuToggle.setAttribute('aria-expanded', String(isOpen));
    document.body.style.overflow = isOpen ? 'hidden' : '';
  });

  mobileMenu.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => {
      mobileMenu.classList.remove('open');
      menuToggle.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
    });
  });
}

/* ─── INITIAL CALL (in case page loaded mid-scroll) ─── */
handleNavScroll();
handleParallax();
