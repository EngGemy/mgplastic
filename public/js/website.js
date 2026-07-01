/* NAV */
window.addEventListener('scroll', () => {
  document.getElementById('nav')?.classList.toggle('scrolled', window.scrollY > 20);
});
function toggleNav() {
  const m = document.getElementById('mnav');
  m.style.display = m.style.display === 'flex' ? 'none' : 'flex';
}
function closeNav() {
  const m = document.getElementById('mnav');
  if (m) m.style.display = 'none';
}

/* REVEAL */
const revObs = new IntersectionObserver(entries => {
  entries.forEach(e => {
    if (e.isIntersecting) {
      e.target.classList.add('visible');
      revObs.unobserve(e.target);
    }
  });
}, { threshold: 0.08 });
document.querySelectorAll('.reveal').forEach(el => revObs.observe(el));

/* STATS */
const arDigits = ['\u0660','\u0661','\u0662','\u0663','\u0664','\u0665','\u0666','\u0667','\u0668','\u0669'];
const toAr = n => String(n).split('').map(d => arDigits[+d] ?? d).join('');
const cntObs = new IntersectionObserver(entries => {
  entries.forEach(e => {
    if (!e.isIntersecting) return;
    const el = e.target;
    const target = parseInt(el.dataset.target || '0', 10);
    const sup = el.querySelector('span');
    const supHTML = sup ? sup.outerHTML : '';
    const t0 = performance.now();
    const dur = 1400;
    const tick = now => {
      const p = Math.min((now - t0) / dur, 1);
      const val = Math.round(p * p * (3 - 2 * p) * target);
      el.innerHTML = toAr(val) + supHTML;
      if (p < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
    cntObs.unobserve(el);
  });
}, { threshold: 0.5 });
document.querySelectorAll('.stat-num[data-target]').forEach(el => cntObs.observe(el));

/* HERO SLIDER */
let curSlide = 0;
let slides = 1;
let sliderTimer;

function showSlide(n) {
  document.querySelectorAll('#slider .slide').forEach((s, i) => s.classList.toggle('active', i === n));
  document.querySelectorAll('#slider .sdot').forEach((d, i) => d.classList.toggle('active', i === n));
  curSlide = n;
}

function nextSlide() { showSlide((curSlide + 1) % slides); resetSliderTimer(); }
function prevSlide() { showSlide((curSlide - 1 + slides) % slides); resetSliderTimer(); }
function goSlide(n) { showSlide(n); resetSliderTimer(); }

function resetSliderTimer() {
  clearInterval(sliderTimer);
  if (slides > 1) sliderTimer = setInterval(nextSlide, 5000);
}

function initHeroSlider() {
  const wrap = document.getElementById('slider');
  if (!wrap) return;

  slides = wrap.querySelectorAll('.slide').length || 1;
  showSlide(0);
  resetSliderTimer();
}

window.nextSlide = nextSlide;
window.prevSlide = prevSlide;
window.goSlide = goSlide;

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initHeroSlider);
} else {
  initHeroSlider();
}

/* PRODUCTS CAROUSEL */
const catalogCategories = window.MG_CATEGORIES || [];
const catalogState = {
  categoryId: 'all',
  page: 1,
  lastPage: 1,
  total: 0,
  items: [],
  slide: 0,
  perView: 4,
  loading: false,
  timer: null,
};

const productsById = new Map();

function findCategoryById(id) {
  const num = Number(id);
  for (const parent of catalogCategories) {
    if (parent.id === num) return parent;
    const child = (parent.children || []).find(c => c.id === num);
    if (child) return { ...child, parentName: parent.name };
  }
  return null;
}

function renderSubCategoryTabs(parentId) {
  const sub = document.getElementById('cat-subtabs');
  if (!sub) return;

  if (parentId === 'all') {
    sub.hidden = true;
    sub.innerHTML = '';
    return;
  }

  const parent = catalogCategories.find(c => c.id === Number(parentId));
  const children = parent?.children || [];

  if (!children.length) {
    sub.hidden = true;
    sub.innerHTML = '';
    return;
  }

  sub.hidden = false;
  sub.innerHTML = `
    <button type="button" class="cat-tab cat-sub active" data-id="${parent.id}" onclick="filterCatSub(this, ${parent.id})">كل ${escapeHtml(parent.name)}</button>
    ${children.map(ch => `
      <button type="button" class="cat-tab cat-sub" data-id="${ch.id}" onclick="filterCatSub(this, ${ch.id})">${escapeHtml(ch.name)}</button>
    `).join('')}
  `;
}

function setActiveTab(containerId, id) {
  document.querySelectorAll(`#${containerId} .cat-tab`).forEach(btn => {
    btn.classList.toggle('active', String(btn.dataset.id) === String(id) || (id === 'all' && btn.dataset.id === 'all'));
  });
}

function catalogPerView() {
  const w = window.innerWidth;
  if (w < 560) return 1;
  if (w < 900) return 2;
  if (w < 1200) return 3;
  return 4;
}

function escapeHtml(str) {
  return String(str ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function productCardHtml(p) {
  const imgBlock = p.image
    ? `<img src="${escapeHtml(p.image)}" alt="" loading="lazy" decoding="async" onerror="this.closest('.prod-img')?.classList.add('no-img')">`
    : '';
  const initialsClass = p.image ? 'prod-initials' : 'prod-initials show';
  const specsPreview = (p.specs || []).slice(0, 2).map(s =>
    `<span class="prod-spec-chip">${escapeHtml(s.l)}: ${escapeHtml(s.v)}</span>`
  ).join('');
  const descPreview = p.desc ? `<p class="prod-desc-preview">${escapeHtml(p.desc).slice(0, 90)}${p.desc.length > 90 ? '…' : ''}</p>` : '';

  return `
    <article class="prod-card" onclick="openModal(${p.id})">
      <div class="prod-img" style="background:linear-gradient(145deg,${p.color}33,${p.color}11)">
        ${imgBlock}
        <div class="${initialsClass}" style="color:${p.color}">${escapeHtml(p.initials)}</div>
        <div class="prod-cat-badge">${escapeHtml(p.breadcrumb || p.catName || '')}</div>
      </div>
      <div class="prod-body">
        <div class="prod-name">${escapeHtml(p.name)}</div>
        <div class="prod-en fm">${escapeHtml(p.en || '')}</div>
        ${descPreview}
        ${specsPreview ? `<div class="prod-specs-preview">${specsPreview}</div>` : ''}
        <div class="prod-meta-row">
          <span class="prod-pts"><i class="ti ti-star" style="font-size:10px"></i> ${p.pts} نقطة/وحدة</span>
          ${p.classification ? `<span class="prod-class-badge">${escapeHtml(p.classification)}</span>` : ''}
        </div>
        <div class="prod-action">
          <span class="prod-btn prod-btn-blue">عرض كل التفاصيل</span>
          ${p.pdf ? `<a href="${escapeHtml(p.pdf)}" class="prod-btn prod-btn-out" onclick="event.stopPropagation()" target="_blank" rel="noopener"><i class="ti ti-download" style="font-size:11px"></i> PDF</a>` : ''}
        </div>
      </div>
    </article>
  `;
}

function updateCatalogCounter() {
  const el = document.getElementById('catalog-counter');
  if (!el) return;
  if (!catalogState.total) {
    el.textContent = 'لا توجد منتجات';
    return;
  }
  const from = catalogState.slide + 1;
  const to = Math.min(catalogState.slide + catalogState.perView, catalogState.total);
  el.textContent = `عرض ${from}–${to} من ${catalogState.total} منتج`;
}

function updateCatalogDots() {
  const dots = document.getElementById('pc-dots');
  const track = document.getElementById('prod-carousel-track');
  if (!dots || !track) return;

  const maxSlide = Math.max(0, catalogState.items.length - catalogState.perView);
  const groups = Math.min(12, Math.max(1, Math.ceil((maxSlide + 1) / catalogState.perView)));

  dots.innerHTML = Array.from({ length: groups }, (_, i) => {
    const target = i * catalogState.perView;
    const active = catalogState.slide >= target && catalogState.slide < target + catalogState.perView;
    return `<button type="button" class="pc-dot ${active ? 'active' : ''}" onclick="catalogGo(${target})" aria-label="صفحة ${i + 1}"></button>`;
  }).join('');
}

function renderCatalogTrack(animate = true) {
  const track = document.getElementById('prod-carousel-track');
  if (!track) return;

  catalogState.perView = catalogPerView();

  const viewport = document.getElementById('prod-carousel-viewport');
  const gap = 20;
  const viewportWidth = viewport?.clientWidth || track.clientWidth;
  const cardWidth = (viewportWidth - gap * (catalogState.perView - 1)) / catalogState.perView;

  track.style.setProperty('--pc-per-view', String(catalogState.perView));

  if (!catalogState.items.length) {
    track.innerHTML = '<div class="catalog-empty">لا توجد منتجات في هذا التصنيف حالياً.</div>';
    track.style.transform = 'none';
    updateCatalogCounter();
    updateCatalogDots();
    return;
  }

  track.innerHTML = catalogState.items.map(productCardHtml).join('');

  const offset = catalogState.slide * (cardWidth + gap);
  track.classList.toggle('pc-animate', animate);
  track.style.transform = `translate3d(${-offset}px, 0, 0)`;

  updateCatalogCounter();
  updateCatalogDots();

  const maxSlide = Math.max(0, catalogState.items.length - catalogState.perView);
  if (catalogState.slide > maxSlide) {
    catalogState.slide = maxSlide;
    renderCatalogTrack(false);
  }
}

async function fetchCatalogPage(page = 1, append = false) {
  if (catalogState.loading) return;
  const url = new URL(window.MG_CATALOG_URL || '/catalog/products', window.location.origin);
  if (catalogState.categoryId !== 'all') {
    url.searchParams.set('category_id', String(catalogState.categoryId));
  }
  url.searchParams.set('page', String(page));
  url.searchParams.set('per_page', '24');

  catalogState.loading = true;
  document.getElementById('catalog-loading')?.removeAttribute('hidden');

  try {
    const res = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
    const json = await res.json();
    const rows = json.data || [];

    catalogState.page = json.meta?.current_page || page;
    catalogState.lastPage = json.meta?.last_page || 1;
    catalogState.total = json.meta?.total || rows.length;

    rows.forEach(p => productsById.set(p.id, p));
    catalogState.items = append ? [...catalogState.items, ...rows] : rows;

    if (!append) catalogState.slide = 0;
    renderCatalogTrack(!append);
  } catch (e) {
    const track = document.getElementById('prod-carousel-track');
    if (track && !append) {
      track.innerHTML = '<div class="catalog-empty">تعذر تحميل المنتجات. حدّث الصفحة وحاول مرة أخرى.</div>';
    }
  } finally {
    catalogState.loading = false;
    document.getElementById('catalog-loading')?.setAttribute('hidden', '');
  }
}

function maybeLoadMoreCatalog() {
  const maxSlide = Math.max(0, catalogState.items.length - catalogState.perView);
  if (
    catalogState.page < catalogState.lastPage
    && catalogState.slide >= maxSlide - catalogState.perView
    && !catalogState.loading
  ) {
    fetchCatalogPage(catalogState.page + 1, true);
  }
}

function catalogGo(index) {
  catalogState.slide = Math.max(0, index);
  renderCatalogTrack();
  maybeLoadMoreCatalog();
  resetCatalogTimer();
}

function catalogNext() {
  const maxSlide = Math.max(0, catalogState.items.length - catalogState.perView);
  catalogState.slide = catalogState.slide >= maxSlide ? 0 : catalogState.slide + 1;
  renderCatalogTrack();
  maybeLoadMoreCatalog();
  resetCatalogTimer();
}

function catalogPrev() {
  const maxSlide = Math.max(0, catalogState.items.length - catalogState.perView);
  catalogState.slide = catalogState.slide <= 0 ? maxSlide : catalogState.slide - 1;
  renderCatalogTrack();
  resetCatalogTimer();
}

function resetCatalogTimer() {
  clearInterval(catalogState.timer);
  catalogState.timer = setInterval(catalogNext, 4500);
}

function filterCatMain(el, id) {
  setActiveTab('cat-tabs-main', id);
  catalogState.categoryId = id;
  catalogState.page = 1;
  catalogState.lastPage = 1;
  catalogState.items = [];
  catalogState.slide = 0;
  renderSubCategoryTabs(id);
  fetchCatalogPage(1, false);
  resetCatalogTimer();
}

function filterCatSub(el, id) {
  document.querySelectorAll('#cat-subtabs .cat-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  catalogState.categoryId = id;
  catalogState.page = 1;
  catalogState.lastPage = 1;
  catalogState.items = [];
  catalogState.slide = 0;
  fetchCatalogPage(1, false);
  resetCatalogTimer();
}

function initCatalogCarousel() {
  const wrap = document.getElementById('prod-carousel-wrap');
  if (!wrap || !window.MG_CATALOG_URL) return;

  fetchCatalogPage(1, false);
  resetCatalogTimer();

  wrap.addEventListener('mouseenter', () => clearInterval(catalogState.timer));
  wrap.addEventListener('mouseleave', resetCatalogTimer);

  let touchX = 0;
  wrap.addEventListener('touchstart', e => { touchX = e.changedTouches[0].screenX; }, { passive: true });
  wrap.addEventListener('touchend', e => {
    const diff = e.changedTouches[0].screenX - touchX;
    if (Math.abs(diff) > 40) diff > 0 ? catalogPrev() : catalogNext();
  }, { passive: true });

  window.addEventListener('resize', () => renderCatalogTrack(false));
}

window.catalogNext = catalogNext;
window.catalogPrev = catalogPrev;
window.catalogGo = catalogGo;
window.filterCatMain = filterCatMain;
window.filterCatSub = filterCatSub;

function openModal(id) {
  const p = productsById.get(id);
  if (!p) return;
  const img = document.getElementById('modal-img');
  const initials = document.getElementById('modal-initials');
  if (p.image) {
    img.style.background = `url('${p.image}') center/cover`;
    initials.style.display = 'none';
  } else {
    img.style.background = `linear-gradient(135deg,${p.color}dd,${p.color}88)`;
    initials.style.display = 'flex';
    initials.textContent = p.initials;
  }
  document.getElementById('modal-tag').textContent = p.breadcrumb || p.catName || '';
  document.getElementById('modal-title').textContent = p.name;
  const modalEn = document.getElementById('modal-en');
  if (modalEn) modalEn.textContent = p.en || '';
  document.getElementById('modal-desc').textContent = p.desc || '—';

  const usageEl = document.getElementById('modal-usage');
  if (usageEl) {
    if (p.usage) {
      usageEl.hidden = false;
      usageEl.innerHTML = `<strong>الاستخدام:</strong> ${escapeHtml(p.usage)}`;
    } else {
      usageEl.hidden = true;
      usageEl.innerHTML = '';
    }
  }

  const specs = (p.specs || []).map(s => `<div class="spec-item"><div class="spec-label">${escapeHtml(s.l)}</div><div class="spec-val">${escapeHtml(s.v)}</div></div>`).join('');
  document.getElementById('modal-specs').innerHTML = specs
    + `<div class="spec-item"><div class="spec-label">النقاط</div><div class="spec-val" style="color:#d97706">⭐ ${p.pts}/وحدة</div></div>`
    + `<div class="spec-item"><div class="spec-label">تحويل النقاط</div><div class="spec-val">${escapeHtml(p.pointConversion || '—')}</div></div>`;

  const notesEl = document.getElementById('modal-notes');
  if (notesEl) {
    if (p.notes) {
      notesEl.hidden = false;
      notesEl.innerHTML = `<strong>ملاحظات:</strong> ${escapeHtml(p.notes)}`;
    } else {
      notesEl.hidden = true;
      notesEl.innerHTML = '';
    }
  }
  const pdfBtn = document.getElementById('modal-pdf-btn');
  if (pdfBtn) {
    pdfBtn.href = p.pdf || '#';
    pdfBtn.style.display = p.pdf ? 'inline-flex' : 'none';
  }
  document.getElementById('prod-modal').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeModal(e) {
  if (e.target === document.getElementById('prod-modal')) {
    document.getElementById('prod-modal').classList.remove('open');
    document.body.style.overflow = '';
  }
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.getElementById('prod-modal')?.classList.remove('open');
    document.body.style.overflow = '';
  }
});

/* MAP */
document.addEventListener('DOMContentLoaded', () => {
  initCatalogCarousel();

  const mapEl = document.getElementById('map');
  if (!mapEl || typeof L === 'undefined') return;
  const cfg = window.MG_MAP || { lat: 32.8872, lng: 13.1913, zoom: 12 };
  const map = L.map('map', { zoomControl: true, scrollWheelZoom: false }).setView([cfg.lat, cfg.lng], cfg.zoom || 12);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap',
    maxZoom: 18,
  }).addTo(map);

  const icon = L.divIcon({
    html: `<div style="width:44px;height:44px;background:#1a56db;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:3px solid #fff;box-shadow:0 4px 12px rgba(26,86,219,.4);display:flex;align-items:center;justify-content:center"><div style="transform:rotate(45deg);color:#fff;font-family:monospace;font-weight:700;font-size:10px">MG</div></div>`,
    iconSize: [44, 44],
    iconAnchor: [22, 44],
    popupAnchor: [0, -48],
    className: '',
  });

  const popup = cfg.popup || 'مصنع MG Plastic';
  L.marker([cfg.lat, cfg.lng], { icon }).addTo(map).bindPopup(popup).openPopup();
});

/* REGISTER */
let fRole = null;
let fStep = 1;
const FPANELS = {
  wholesale_distributor: { link: '/distributor', msg: 'يمكنك الدخول للوحة تحكم الموزع بعد الموافقة' },
  retail_trader: { link: '/trader', msg: 'يمكنك الدخول للوحة تحكم التاجر بعد الموافقة' },
  plumber: { link: window.MG_PORTAL_URL || '/portal', msg: 'حمّل التطبيق وابدأ كسب النقاط بعد الموافقة' },
};

function fSelectRole(el) {
  document.querySelectorAll('.role-pill').forEach(p => {
    p.className = 'role-pill';
    p.querySelector('.rp-check').style.opacity = '0';
  });
  fRole = el.dataset.role;
  const cls = fRole === 'wholesale_distributor' ? 'sel-wholesale' : fRole === 'retail_trader' ? 'sel-retail' : 'sel-plumber';
  el.classList.add(cls);
  el.querySelector('.rp-check').style.opacity = '1';
  document.getElementById('fp1-btn').disabled = false;
}

function fGo(n) {
  document.getElementById('fp' + fStep).style.display = 'none';
  fStep = n;
  document.getElementById('fp' + n).style.display = 'block';
  fUpdateBar();
  if (n === 3) {
    const isP = fRole === 'plumber';
    document.getElementById('fp3-biz').style.display = isP ? 'none' : 'block';
    document.getElementById('fp3-plumb').style.display = isP ? 'block' : 'none';
  }
  const titles = { 1: 'انضم للشبكة', 2: 'بياناتك الأساسية', 3: 'تأكيد وإرسال' };
  const eyes = { 1: 'Step 01 — Account Type', 2: 'Step 02 — Personal Info', 3: 'Step 03 — Confirm' };
  document.getElementById('fp-title').textContent = titles[n];
  document.getElementById('fp-eyebrow').textContent = eyes[n];
}

function fUpdateBar() {
  const nums = ['\u0661','\u0662','\u0663'];
  for (let i = 1; i <= 3; i++) {
    const sc = document.getElementById('fsc' + i);
    sc.className = 'step-circle';
    if (i < fStep) {
      sc.classList.add('sc-done');
      sc.innerHTML = '<i class="ti ti-check" style="font-size:11px"></i>';
    } else if (i === fStep) {
      sc.classList.add('sc-active');
      sc.textContent = nums[i - 1];
    } else {
      sc.classList.add('sc-idle');
      sc.textContent = nums[i - 1];
    }
    if (i < 3) {
      document.getElementById('fsl' + i).className = 'step-line' + (i < fStep ? ' done' : '');
    }
  }
}

function fValidate2() {
  const name = document.getElementById('fp-name').value.trim();
  const phone = document.getElementById('fp-phone').value.trim();
  const city = document.getElementById('fp-city').value;
  const pass = document.getElementById('fp-pass').value;
  const pass2 = document.getElementById('fp-pass2').value;
  if (!name) return fErr('fp-err2', 'الاسم الكامل مطلوب');
  if (phone.length < 9) return fErr('fp-err2', 'رقم هاتف غير صحيح');
  if (!city) return fErr('fp-err2', 'اختر المدينة');
  if (pass.length < 6) return fErr('fp-err2', 'كلمة المرور 6 أحرف+');
  if (pass !== pass2) return fErr('fp-err2', 'كلمتا المرور غير متطابقتين');
  document.getElementById('fp-err2').classList.remove('show');
  fGo(3);
}

async function fSubmit() {
  if (!document.getElementById('fp-terms').checked) return fErr('fp-err3', 'الموافقة على الشروط مطلوبة');
  const btn = document.querySelector('#fp3 .btn-primary');
  if (btn) btn.disabled = true;

  try {
    const res = await fetch(window.MG_REGISTER_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': window.MG_CSRF,
      },
      body: JSON.stringify({
        name: document.getElementById('fp-name').value.trim(),
        phone: document.getElementById('fp-phone').value.trim(),
        city_id: document.getElementById('fp-city').value,
        password: document.getElementById('fp-pass').value,
        password_confirmation: document.getElementById('fp-pass2').value,
        role: fRole,
        business_name: document.getElementById('fp-biz')?.value?.trim() || null,
        terms: true,
      }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || 'تعذر إرسال الطلب');

    document.getElementById('fp-err3').classList.remove('show');
    document.getElementById('fp3').style.display = 'none';
    document.getElementById('fp-success').style.display = 'block';
    const cfg = FPANELS[fRole] || { link: '/', msg: 'سيتم إشعارك عند الموافقة' };
    document.getElementById('fps-msg').textContent = '✓ ' + cfg.msg;
    document.getElementById('fps-link').href = data.data?.panel_url || cfg.link;
    for (let i = 1; i <= 3; i++) {
      const sc = document.getElementById('fsc' + i);
      sc.className = 'step-circle sc-done';
      sc.innerHTML = '<i class="ti ti-check" style="font-size:11px"></i>';
      if (i < 3) document.getElementById('fsl' + i).className = 'step-line done';
    }
  } catch (err) {
    fErr('fp-err3', err.message || 'حدث خطأ أثناء الإرسال');
  } finally {
    if (btn) btn.disabled = false;
  }
}

function fErr(id, msg) {
  const el = document.getElementById(id);
  el.textContent = msg;
  el.classList.add('show');
  setTimeout(() => el.classList.remove('show'), 5000);
}

function fTogglePass(inputId, iconId) {
  const inp = document.getElementById(inputId);
  const icon = document.getElementById(iconId);
  inp.type = inp.type === 'password' ? 'text' : 'password';
  icon.className = 'ti ti-' + (inp.type === 'text' ? 'eye-off' : 'eye') + ' pass-eye';
}
