<x-filament-panels::page>
@php
    $items = $this->myProducts;
    $editingItem = $this->editingItem;
    $total = $this->productsCount;
    $visible = $this->visibleCount;
    $panelId = filament()->getCurrentPanel()?->getId() ?? 'trader';
    $isDistributor = $panelId === 'distributor';
@endphp

<style>
:root {
    --mp-ink: #0b1220;
    --mp-muted: #5b6b7c;
    --mp-line: #e6edf5;
    --mp-card: #ffffff;
    --mp-accent: {{ $isDistributor ? '#1a56db' : '#0f766e' }};
    --mp-accent-2: {{ $isDistributor ? '#1e40af' : '#115e59' }};
    --mp-accent-soft: {{ $isDistributor ? '#eff6ff' : '#f0fdfa' }};
    --mp-accent-line: {{ $isDistributor ? '#bfdbfe' : '#99f6e4' }};
    --mp-warm: #b45309;
    --mp-warm-bg: #fffbeb;
}
.mp-shell {
    direction: rtl;
    font-family: 'Cairo', sans-serif;
    color: var(--mp-ink);
    position: relative;
}
.mp-shell::before {
    content: '';
    position: absolute;
    inset: -12px -8px auto -8px;
    height: 220px;
    background:
        radial-gradient(ellipse 80% 60% at 10% 20%, color-mix(in srgb, var(--mp-accent) 18%, transparent), transparent 60%),
        radial-gradient(ellipse 70% 50% at 90% 10%, rgba(180,83,9,.10), transparent 55%),
        linear-gradient(180deg, #f7fafc 0%, transparent 100%);
    pointer-events: none;
    z-index: 0;
    border-radius: 24px;
}
.mp-shell > * { position: relative; z-index: 1; }

.mp-top {
    display: grid;
    grid-template-columns: minmax(0,1.4fr) auto;
    gap: 14px;
    align-items: stretch;
    margin-bottom: 16px;
}
@media (max-width: 820px) {
    .mp-top { grid-template-columns: 1fr; }
}
.mp-hero {
    background:
        linear-gradient(135deg, color-mix(in srgb, var(--mp-accent) 92%, #000) 0%, var(--mp-accent-2) 55%, #0b1f2a 100%);
    color: #fff;
    border-radius: 22px;
    padding: 22px 24px;
    overflow: hidden;
    position: relative;
    min-height: 132px;
}
.mp-hero::after {
    content: '';
    position: absolute;
    width: 220px; height: 220px;
    right: -60px; top: -80px;
    background: radial-gradient(circle, rgba(255,255,255,.18), transparent 70%);
    pointer-events: none;
}
.mp-kicker {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 11px; font-weight: 800; letter-spacing: .04em;
    background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.18);
    padding: 4px 10px; border-radius: 999px; margin-bottom: 10px;
}
.mp-hero h2 { margin: 0; font-size: 1.55rem; font-weight: 900; letter-spacing: -.02em; }
.mp-hero p { margin: 8px 0 0; opacity: .9; font-size: 13px; font-weight: 600; max-width: 520px; line-height: 1.6; }

.mp-stats {
    display: grid; grid-template-columns: 1fr 1fr; gap: 10px; min-width: 220px;
}
.mp-stat {
    background: var(--mp-card);
    border: 1px solid var(--mp-line);
    border-radius: 18px;
    padding: 14px 16px;
    display: flex; flex-direction: column; justify-content: center;
    box-shadow: 0 10px 30px -22px rgba(15,23,42,.45);
}
.mp-stat .n { font-size: 1.55rem; font-weight: 900; color: var(--mp-accent-2); line-height: 1; }
.mp-stat .l { font-size: 11px; font-weight: 800; color: var(--mp-muted); margin-top: 6px; }

.mp-toolbar {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    margin-bottom: 16px;
    background: var(--mp-card);
    border: 1px solid var(--mp-line);
    border-radius: 16px;
    padding: 10px 12px;
}
.mp-search {
    flex: 1; min-width: 200px; position: relative;
}
.mp-search input {
    width: 100%;
    border: 1.5px solid var(--mp-line);
    border-radius: 12px;
    padding: 10px 14px 10px 38px;
    font: inherit; font-size: 13px; font-weight: 700;
    background: #f8fafc;
}
.mp-search input:focus {
    outline: none;
    border-color: var(--mp-accent);
    background: #fff;
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--mp-accent) 16%, transparent);
}
.mp-search-ico {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    color: var(--mp-muted); font-size: 14px; pointer-events: none;
}
.mp-add {
    border: none;
    background: var(--mp-accent);
    color: #fff;
    font: inherit; font-weight: 900; font-size: 13px;
    padding: 11px 18px;
    border-radius: 12px;
    cursor: pointer;
    box-shadow: 0 10px 24px color-mix(in srgb, var(--mp-accent) 35%, transparent);
    white-space: nowrap;
}
.mp-add:hover { filter: brightness(1.05); }

.mp-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 16px;
}
.mp-card {
    background: var(--mp-card);
    border: 1px solid var(--mp-line);
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 14px 36px -24px rgba(15,23,42,.5);
    transition: transform .18s ease, box-shadow .18s ease;
    animation: mpIn .45s ease both;
}
.mp-card:nth-child(1) { animation-delay: .02s; }
.mp-card:nth-child(2) { animation-delay: .06s; }
.mp-card:nth-child(3) { animation-delay: .10s; }
.mp-card:nth-child(4) { animation-delay: .14s; }
.mp-card:nth-child(5) { animation-delay: .18s; }
.mp-card:nth-child(6) { animation-delay: .22s; }
@keyframes mpIn {
    from { opacity: 0; transform: translateY(10px) scale(.98); }
    to { opacity: 1; transform: none; }
}
.mp-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 22px 40px -22px rgba(15,23,42,.45);
}
.mp-card.inactive { opacity: .58; filter: grayscale(.15); }
.mp-media {
    position: relative;
    aspect-ratio: 1;
    background:
        linear-gradient(145deg, var(--mp-accent-soft), #fff),
        repeating-linear-gradient(45deg, transparent, transparent 8px, rgba(0,0,0,.015) 8px, rgba(0,0,0,.015) 16px);
}
.mp-media img {
    width: 100%; height: 100%; object-fit: cover; display: block;
}
.mp-media-tag {
    position: absolute; top: 10px; right: 10px;
    background: rgba(15,23,42,.72); color: #fff;
    font-size: 10px; font-weight: 800;
    padding: 4px 9px; border-radius: 999px;
    backdrop-filter: blur(4px);
}
.mp-media-tag.hide {
    background: rgba(185,28,28,.85);
}
.mp-body { padding: 14px; }
.mp-name {
    margin: 0 0 6px;
    font-size: 15px; font-weight: 900; line-height: 1.35;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.mp-desc {
    margin: 0;
    font-size: 12px; font-weight: 600; color: var(--mp-muted); line-height: 1.55;
    min-height: 38px;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.mp-meta {
    display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 10px;
}
.mp-badge {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 10px; font-weight: 800;
    background: var(--mp-warm-bg); color: var(--mp-warm);
    border: 1px solid #fde68a;
    padding: 3px 8px; border-radius: 999px;
}
.mp-actions {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 6px;
    margin-top: 12px;
}
.mp-btn {
    border: 1px solid var(--mp-line);
    background: #f8fafc;
    color: #334155;
    border-radius: 11px;
    padding: 8px 10px;
    font: inherit; font-size: 11px; font-weight: 800;
    cursor: pointer;
}
.mp-btn:hover { background: var(--mp-accent-soft); border-color: var(--mp-accent-line); color: var(--mp-accent-2); }
.mp-btn.danger { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
.mp-btn.danger:hover { background: #fee2e2; }

.mp-empty {
    text-align: center;
    padding: 56px 24px;
    background:
        linear-gradient(180deg, #fff, #f8fafc);
    border: 1.5px dashed #cbd5e1;
    border-radius: 24px;
}
.mp-empty-art {
    width: 88px; height: 88px; margin: 0 auto 14px;
    border-radius: 24px;
    background: var(--mp-accent-soft);
    border: 1px solid var(--mp-accent-line);
    display: grid; place-items: center;
    font-size: 36px;
}
.mp-empty h3 { margin: 0 0 6px; font-size: 1.15rem; font-weight: 900; }
.mp-empty p { margin: 0 0 18px; color: var(--mp-muted); font-size: 13px; font-weight: 600; }

.mp-modal-bg {
    position: fixed; inset: 0;
    background: rgba(11,18,32,.55);
    z-index: 80;
    display: flex; align-items: center; justify-content: center;
    padding: 16px;
    backdrop-filter: blur(4px);
    animation: mpFade .18s ease;
}
@keyframes mpFade { from { opacity: 0; } to { opacity: 1; } }
.mp-modal {
    width: min(760px, 100%);
    max-height: 92vh;
    overflow: auto;
    background: #fff;
    border-radius: 24px;
    box-shadow: 0 30px 80px rgba(15,23,42,.35);
    animation: mpPop .22s ease;
}
@keyframes mpPop {
    from { opacity: 0; transform: translateY(12px) scale(.98); }
    to { opacity: 1; transform: none; }
}
.mp-modal-head {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    padding: 16px 20px;
    border-bottom: 1px solid #eef2f7;
    background: linear-gradient(180deg, var(--mp-accent-soft), #fff);
    position: sticky; top: 0; z-index: 2;
}
.mp-modal-head h3 { margin: 0; font-size: 1.12rem; font-weight: 900; }
.mp-x {
    width: 36px; height: 36px; border: none; border-radius: 11px;
    background: #f1f5f9; color: #64748b; font-size: 20px; font-weight: 800; cursor: pointer; line-height: 1;
}
.mp-modal-body { padding: 20px; }
.mp-form {
    display: grid;
    grid-template-columns: 220px minmax(0, 1fr);
    gap: 20px;
    align-items: start;
}
@media (max-width: 700px) {
    .mp-form { grid-template-columns: 1fr; }
}
.mp-field label {
    display: block;
    font-size: 11px; font-weight: 800; color: var(--mp-muted);
    margin-bottom: 7px;
}
.mp-field input[type=text],
.mp-field textarea {
    width: 100%;
    border: 1.5px solid var(--mp-line);
    border-radius: 13px;
    padding: 12px 13px;
    font: inherit; font-size: 13px; font-weight: 700;
    background: #fff;
}
.mp-field input:focus,
.mp-field textarea:focus {
    outline: none;
    border-color: var(--mp-accent);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--mp-accent) 16%, transparent);
}
.mp-drop {
    position: relative;
    border: 2px dashed var(--mp-accent-line);
    background: var(--mp-accent-soft);
    border-radius: 18px;
    overflow: hidden;
    min-height: 220px;
}
.mp-drop img {
    width: 100%; height: 220px; object-fit: cover; display: block;
}
.mp-drop input[type=file] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.mp-drop-empty {
    height: 220px;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 6px; padding: 14px; pointer-events: none; text-align: center;
}
.mp-drop-change {
    position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%);
    background: rgba(15,23,42,.8); color: #fff;
    border-radius: 999px; padding: 6px 12px;
    font-size: 11px; font-weight: 800; pointer-events: none; white-space: nowrap;
}
.mp-fields { display: flex; flex-direction: column; gap: 14px; min-width: 0; }
.mp-note {
    font-size: 11px; font-weight: 700; color: #94a3b8;
    background: #f8fafc; border: 1px dashed #e2e8f0;
    border-radius: 13px; padding: 11px 12px; line-height: 1.5;
}
.mp-modal-actions {
    display: flex; gap: 8px; margin-top: 18px;
}
.mp-modal-actions button {
    flex: 1; border: none; border-radius: 13px; padding: 13px;
    font: inherit; font-weight: 900; font-size: 13px; cursor: pointer;
}
.mp-save { background: var(--mp-accent); color: #fff; }
.mp-save:disabled { opacity: .7; cursor: wait; }
.mp-cancel { background: #f1f5f9; color: #475569; }
.mp-error { color: #dc2626; font-size: 11px; font-weight: 700; margin-top: 5px; }
</style>

<div class="mp-shell">
    <div class="mp-top">
        <div class="mp-hero">
            <div class="mp-kicker">معرض المتجر · بدون نقاط</div>
            <h2>منتجاتي</h2>
            <p>أضف منتجاتك الخاصة كمعرض صور أنيق: صورة واضحة، اسم المنتج، ووصف قصير يظهر في صفحة متجرك عبر الـ API.</p>
        </div>
        <div class="mp-stats">
            <div class="mp-stat">
                <div class="n">{{ number_format($total) }}</div>
                <div class="l">إجمالي المنتجات</div>
            </div>
            <div class="mp-stat">
                <div class="n">{{ number_format($visible) }}</div>
                <div class="l">ظاهر في المعرض</div>
            </div>
        </div>
    </div>

    <div class="mp-toolbar">
        <div class="mp-search">
            <span class="mp-search-ico">⌕</span>
            <input type="search" wire:model.live.debounce.250ms="search" placeholder="ابحث بالاسم أو الوصف...">
        </div>
        <button type="button" class="mp-add" wire:click="openCreateForm">＋ إضافة منتج</button>
    </div>

    @if($items->isEmpty())
        <div class="mp-empty">
            <div class="mp-empty-art">📷</div>
            <h3>{{ $search !== '' ? 'لا نتائج للبحث' : 'معرضك فارغ' }}</h3>
            <p>
                @if($search !== '')
                    جرّب كلمة أخرى أو امسح البحث
                @else
                    أضف أول منتج بصورة واسم — يظهر كمعرض في متجرك للعملاء
                @endif
            </p>
            @if($search === '')
                <button type="button" class="mp-add" wire:click="openCreateForm">إضافة أول منتج</button>
            @endif
        </div>
    @else
        <div class="mp-grid">
            @foreach($items as $item)
                <article class="mp-card {{ $item->is_active ? '' : 'inactive' }}">
                    <div class="mp-media">
                        <img src="{{ $item->url }}" alt="{{ $item->title }}">
                        <span class="mp-media-tag {{ $item->is_active ? '' : 'hide' }}">
                            {{ $item->is_active ? 'ظاهر' : 'مخفي' }}
                        </span>
                    </div>
                    <div class="mp-body">
                        <h3 class="mp-name">{{ $item->title ?: 'بدون اسم' }}</h3>
                        <p class="mp-desc">{{ $item->description ?: 'لا يوجد وصف' }}</p>
                        <div class="mp-meta">
                            <span class="mp-badge">بدون نقاط</span>
                        </div>
                        <div class="mp-actions">
                            <button type="button" class="mp-btn" wire:click="openEditForm({{ $item->id }})">تعديل</button>
                            <button type="button" class="mp-btn" wire:click="toggleActive({{ $item->id }})">
                                {{ $item->is_active ? 'إخفاء' : 'إظهار' }}
                            </button>
                            <button type="button" class="mp-btn danger"
                                wire:click="deleteProduct({{ $item->id }})"
                                wire:confirm="حذف هذا المنتج من المعرض؟">حذف</button>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</div>

@if($showForm)
    <div class="mp-modal-bg" wire:click.self="closeForm">
        <div class="mp-modal" wire:click.stop>
            <div class="mp-modal-head">
                <h3>{{ $editingId ? 'تعديل المنتج' : 'إضافة منتج للمعرض' }}</h3>
                <button type="button" class="mp-x" wire:click="closeForm" aria-label="إغلاق">×</button>
            </div>

            <div class="mp-modal-body">
                <div class="mp-form">
                    <div class="mp-field" style="margin:0">
                        <label>صورة المنتج {{ $editingId ? '' : '*' }}</label>
                        <div class="mp-drop">
                            @if($photo)
                                <img src="{{ $photo->temporaryUrl() }}" alt="">
                                <div class="mp-drop-change">تغيير الصورة</div>
                            @elseif($editingItem)
                                <img src="{{ $editingItem->url }}" alt="">
                                <div class="mp-drop-change">تغيير الصورة</div>
                            @else
                                <div class="mp-drop-empty">
                                    <div style="font-size:32px">📷</div>
                                    <div style="font-size:13px;font-weight:900;color:var(--mp-accent-2)">اسحب أو اختر صورة</div>
                                    <div style="font-size:11px;color:var(--mp-muted);font-weight:700">JPG / PNG / WebP — حتى 10MB</div>
                                </div>
                            @endif
                            <input type="file" accept="image/jpeg,image/png,image/webp" wire:model="photo">
                        </div>
                        @error('photo') <div class="mp-error">{{ $message }}</div> @enderror
                        <div wire:loading wire:target="photo" class="mp-error" style="color:var(--mp-accent-2)">جاري رفع المعاينة...</div>
                    </div>

                    <div class="mp-fields">
                        <div class="mp-field" style="margin:0">
                            <label>اسم المنتج *</label>
                            <input type="text" wire:model="productName" placeholder="مثال: محبس نحاس ½" autofocus>
                            @error('productName') <div class="mp-error">{{ $message }}</div> @enderror
                        </div>

                        <div class="mp-field" style="margin:0">
                            <label>ما هو المنتج؟ (وصف قصير)</label>
                            <textarea rows="6" wire:model="productDescription" placeholder="اكتب وصفًا بسيطًا يظهر تحت الصورة في المعرض..."></textarea>
                            @error('productDescription') <div class="mp-error">{{ $message }}</div> @enderror
                        </div>

                        <div class="mp-note">
                            هذا المنتج يظهر كصورة في معرض متجرك للعملاء عبر الـ API، ولا يدخل نظام نقاط MG Plastic.
                        </div>
                    </div>
                </div>

                <div class="mp-modal-actions">
                    <button type="button" class="mp-cancel" wire:click="closeForm">إلغاء</button>
                    <button type="button" class="mp-save" wire:click="saveProduct" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveProduct">{{ $editingId ? 'حفظ التعديلات' : 'إضافة للمعرض' }}</span>
                        <span wire:loading wire:target="saveProduct">جاري الحفظ...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif
</x-filament-panels::page>
