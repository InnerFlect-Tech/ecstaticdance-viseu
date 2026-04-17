/* ============================================================
   ECSTATIC DANCE VISEU — Pages JavaScript
   Shared interactions for all inner pages
   ============================================================ */

/* ─── NAV bg on scroll ─── */
const nav = document.getElementById('nav');
if (nav) {
  window.addEventListener('scroll', () => {
    nav.classList.toggle('bg', window.scrollY > 60);
  }, { passive: true });
}

/* ─── Scroll reveal ─── */
const revealEls = document.querySelectorAll('.reveal, .reveal-left, .reveal-right');
if (revealEls.length) {
  const io = new IntersectionObserver((entries) => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        e.target.classList.add('visible');
        io.unobserve(e.target);
      }
    });
  }, { threshold: 0.1, rootMargin: '0px 0px -30px 0px' });
  revealEls.forEach(el => io.observe(el));
}

/* ─── Smooth anchor scroll ─── */
document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', e => {
    const t = document.querySelector(a.getAttribute('href'));
    if (t) { e.preventDefault(); t.scrollIntoView({ behavior: 'smooth' }); }
  });
});

/* ─── Mobile menu ─── */
const toggle = document.getElementById('menuToggle');
const menu   = document.getElementById('mobileMenu');
if (toggle && menu) {
  toggle.addEventListener('click', () => {
    const open = menu.classList.toggle('open');
    toggle.setAttribute('aria-expanded', String(open));
    document.body.style.overflow = open ? 'hidden' : '';
  });
  menu.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', () => {
      menu.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
    });
  });
}

/* ─── FAQ accordion ─── */
document.querySelectorAll('.faq-question').forEach(btn => {
  btn.addEventListener('click', () => {
    const item = btn.closest('.faq-item');
    const isOpen = item.classList.contains('open');
    document.querySelectorAll('.faq-item.open').forEach(i => i.classList.remove('open'));
    if (!isOpen) item.classList.add('open');
  });
});

/* ─── Contact form — Web3Forms ─── */
const contactForm = document.getElementById('contactForm');
if (contactForm) {
  contactForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    const submitBtn = contactForm.querySelector('button[type="submit"]');
    const successEl = document.getElementById('formSuccess');

    submitBtn.disabled = true;
    submitBtn.textContent = 'A enviar…';

    const formData = new FormData(contactForm);
    const data = Object.fromEntries(formData.entries());

    try {
      const res = await fetch('https://api.web3forms.com/submit', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(data),
      });

      if (res.ok) {
        contactForm.style.opacity = '.4';
        contactForm.style.pointerEvents = 'none';
        if (successEl) successEl.classList.add('show');
      } else {
        throw new Error('Resposta inesperada do servidor.');
      }
    } catch {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Enviar mensagem';
      alert('Ocorreu um erro ao enviar. Por favor tenta novamente ou escreve para info@ecstaticdanceviseu.pt');
    }
  });
}

/* ─── Newsletter form (Web3Forms) ─── */
document.querySelectorAll('.newsletter-form').forEach(form => {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('button');
    if (!btn) return;

    btn.disabled = true;
    btn.textContent = 'A subscrever…';

    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());

    try {
      const res = await fetch('https://api.web3forms.com/submit', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(data),
      });
      if (res.ok) {
        btn.textContent = 'Subscrito ✓';
        btn.style.background = 'var(--verde-m)';
      } else {
        throw new Error();
      }
    } catch {
      btn.disabled = false;
      btn.textContent = 'Subscrever';
    }
  });
});

/* ─── Gallery filter ─── */
const filterBtns = document.querySelectorAll('.gallery-filter-btn');
if (filterBtns.length) {
  filterBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      filterBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const filter = btn.dataset.filter;
      document.querySelectorAll('.gitem').forEach(item => {
        item.style.display = (filter === 'all' || item.dataset.cat === filter) ? '' : 'none';
      });
    });
  });
}

/* ─── Parallax banners on inner pages ─── */
const pBanners = document.querySelectorAll('.parallax-banner');
if (pBanners.length) {
  window.addEventListener('scroll', () => {
    pBanners.forEach(banner => {
      const img = banner.querySelector('img');
      if (!img) return;
      const rect = banner.getBoundingClientRect();
      img.style.transform = `translateY(${-rect.top * 0.18}px)`;
    });
  }, { passive: true });
}
