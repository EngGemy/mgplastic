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
let slides = document.querySelectorAll('.slide').length || 1;
let sliderTimer;
function showSlide(n) {
  document.querySelectorAll('.slide').forEach((s, i) => s.classList.toggle('active', i === n));
  document.querySelectorAll('.sdot').forEach((d, i) => d.classList.toggle('active', i === n));
  curSlide = n;
}
function nextSlide() { showSlide((curSlide + 1) % slides); resetTimer(); }
function prevSlide() { showSlide((curSlide - 1 + slides) % slides); resetTimer(); }
function goSlide(n) { showSlide(n); resetTimer(); }
function resetTimer() {
  clearInterval(sliderTimer);
  if (slides > 1) sliderTimer = setInterval(nextSlide, 5000);
}
if (slides > 1) sliderTimer = setInterval(nextSlide, 5000);

/* PRODUCTS */
const products = window.MG_PRODUCTS || [];
const categoryLabels = window.MG_CATEGORY_LABELS || {};

function renderProducts(cat = 'all') {
  const grid = document.getElementById('prod-grid');
  if (!grid) return;
  const filtered = cat === 'all' ? products : products.filter(p => p.cat === cat);
  if (!filtered.length) {
    grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted)">لا توجد منتجات في هذا التصنيف حالياً.</div>';
    return;
  }
  grid.innerHTML = filtered.map(p => `
    <div class="prod-card" onclick="openModal(${p.id})">
      <div class="prod-img" style="background:${p.color}22">
        ${p.image ? `<img src="${p.image}" alt="" style="width:100%;height:100%;object-fit:cover">` : `<div class="prod-initials" style="color:${p.color}">${p.initials}</div>`}
        <div class="prod-cat-badge">${p.catName || categoryLabels[p.cat] || ''}</div>
      </div>
      <div class="prod-body">
        <div class="prod-name">${p.name}</div>
        <div class="prod-en fm">${p.en || ''}</div>
        <div><span class="prod-pts"><i class="ti ti-star" style="font-size:10px"></i> ${p.pts} نقطة/وحدة</span></div>
        <div class="prod-action">
          <span class="prod-btn prod-btn-blue">عرض التفاصيل</span>
          ${p.pdf ? `<a href="${p.pdf}" class="prod-btn prod-btn-out" onclick="event.stopPropagation()" target="_blank"><i class="ti ti-download" style="font-size:11px"></i> PDF</a>` : ''}
        </div>
      </div>
    </div>
  `).join('');
}
renderProducts();

function filterCat(el, cat) {
  document.querySelectorAll('.cat-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  renderProducts(cat);
}

function openModal(id) {
  const p = products.find(x => x.id === id);
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
  document.getElementById('modal-tag').textContent = p.catName || categoryLabels[p.cat] || '';
  document.getElementById('modal-title').textContent = p.name;
  document.getElementById('modal-desc').textContent = p.desc || '';
  const specs = (p.specs || []).map(s => `<div class="spec-item"><div class="spec-label">${s.l}</div><div class="spec-val">${s.v}</div></div>`).join('');
  document.getElementById('modal-specs').innerHTML = specs + `<div class="spec-item"><div class="spec-label">النقاط</div><div class="spec-val" style="color:#d97706">⭐ ${p.pts}/وحدة</div></div>`;
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
