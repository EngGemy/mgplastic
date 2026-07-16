<x-filament-panels::page>
@php
    $stock = $this->filteredStock;
    $cartPts = $this->cartPoints;
    $plumbers = $this->plumbers;
    $cats = $this->categories;
    $counts = $this->filterCounts;
    $catalogAll = $this->catalogAll;
    $modalProduct = $this->qtyModalProduct;
    $activeCat = $cats->firstWhere('id', $selectedCategoryId);
@endphp

<style>
:root {
    --pos-ink: #0b1f17;
    --pos-muted: #5b6b63;
    --pos-line: #d7e3db;
    --pos-bg: #f3f7f4;
    --pos-card: #ffffff;
    --pos-accent: #0f766e;
    --pos-accent-2: #115e59;
    --pos-warn: #b45309;
    --pos-warn-bg: #fff7ed;
    --pos-ok: #047857;
    --pos-ok-bg: #ecfdf5;
}
.pos-shell { direction:rtl; font-family:'Cairo',sans-serif; color:var(--pos-ink); }
.pos-banner {
    background: linear-gradient(120deg, #064e3b 0%, #0f766e 55%, #134e4a 100%);
    color:#fff; border-radius:18px; padding:16px 18px; margin-bottom:14px;
    display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px;
}
@media(max-width:900px){ .pos-banner{grid-template-columns:repeat(2,1fr)} }
.pos-banner-stat { background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.16); border-radius:14px; padding:10px 12px; text-align:center; }
.pos-banner-val { font-size:1.45rem; font-weight:900; line-height:1.1; }
.pos-banner-lbl { font-size:11px; opacity:.85; margin-top:3px; font-weight:700; }

.pos-layout { display:grid; grid-template-columns:minmax(0,1fr) 340px; gap:14px; min-height:78vh; }
@media(max-width:1100px){ .pos-layout{grid-template-columns:1fr} .pos-cart{order:-1} }

.pos-main { display:flex; flex-direction:column; gap:12px; min-width:0; }
.pos-topbar {
    display:grid; grid-template-columns:1.1fr 1fr; gap:10px; background:var(--pos-card);
    border:1px solid var(--pos-line); border-radius:16px; padding:12px;
}
@media(max-width:700px){ .pos-topbar{grid-template-columns:1fr} }
.pos-field label { display:block; font-size:11px; font-weight:800; color:var(--pos-muted); margin-bottom:6px; }
.pos-input {
    width:100%; padding:10px 12px; border:1.5px solid var(--pos-line); border-radius:12px;
    background:#fff; font:inherit; font-size:13px; font-weight:600;
}
.pos-input:focus { outline:none; border-color:var(--pos-accent); box-shadow:0 0 0 3px rgba(15,118,110,.12); }

.pos-plumber-box { position:relative; }
.pos-plumber-selected {
    display:flex; align-items:center; justify-content:space-between; gap:8px;
    padding:9px 11px; border:1.5px solid #99f6e4; border-radius:12px; background:var(--pos-ok-bg);
}
.pos-plumber-selected-name { font-size:13px; font-weight:900; color:#065f46; }
.pos-plumber-selected-meta { font-size:11px; color:#047857; margin-top:2px; }
.pos-plumber-clear { border:none; background:#fee2e2; color:#b91c1c; width:28px; height:28px; border-radius:8px; cursor:pointer; font-weight:800; }
.pos-plumber-dropdown {
    position:absolute; z-index:40; top:calc(100% + 4px); inset-inline:0; background:#fff;
    border:1.5px solid var(--pos-line); border-radius:12px; box-shadow:0 16px 40px rgba(15,23,42,.12); max-height:280px; overflow:auto;
}
.pos-plumber-option { width:100%; text-align:right; padding:10px 12px; border:none; background:#fff; cursor:pointer; border-bottom:1px solid #f1f5f9; font:inherit; }
.pos-plumber-option:hover { background:#f0fdfa; }
.pos-plumber-option-name { font-size:13px; font-weight:800; display:block; }
.pos-plumber-option-meta { font-size:11px; color:var(--pos-muted); margin-top:2px; display:block; }
.pos-plumber-empty { padding:14px; text-align:center; color:#94a3b8; font-size:12px; }

.pos-filters {
    background:var(--pos-card); border:1px solid var(--pos-line); border-radius:16px; padding:12px;
    display:flex; flex-direction:column; gap:10px;
}
.pos-seg {
    display:grid; grid-template-columns:repeat(3,1fr); gap:6px; background:var(--pos-bg);
    padding:5px; border-radius:12px;
}
.pos-seg-btn {
    border:none; background:transparent; border-radius:9px; padding:9px 8px; cursor:pointer;
    font:inherit; font-size:12px; font-weight:800; color:var(--pos-muted); transition:.15s;
}
.pos-seg-btn.active { background:#fff; color:var(--pos-accent-2); box-shadow:0 1px 3px rgba(15,23,42,.08); }
.pos-seg-count {
    display:inline-block; margin-inline-start:4px; min-width:18px; padding:1px 6px; border-radius:999px;
    background:rgba(15,118,110,.1); color:var(--pos-accent); font-size:10px; font-weight:900;
}
.pos-seg-btn.active .pos-seg-count { background:rgba(15,118,110,.16); }

.pos-chips-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.pos-chip {
    display:inline-flex; align-items:center; gap:6px; padding:5px 10px; border-radius:999px;
    background:#ecfdf5; color:#065f46; font-size:11px; font-weight:800; border:1px solid #a7f3d0;
}
.pos-chip button { border:none; background:transparent; cursor:pointer; color:#047857; font-weight:900; padding:0; line-height:1; }
.pos-clear {
    margin-inline-start:auto; border:none; background:#f8fafc; color:#64748b; border:1px solid #e2e8f0;
    border-radius:999px; padding:6px 12px; font:inherit; font-size:11px; font-weight:800; cursor:pointer;
}

.pos-browse {
    display:grid; grid-template-columns:220px minmax(0,1fr); gap:12px; min-height:520px;
}
@media(max-width:900px){ .pos-browse{grid-template-columns:1fr} }

.pos-cats-panel {
    background:var(--pos-card); border:1px solid var(--pos-line); border-radius:16px;
    padding:12px; display:flex; flex-direction:column; gap:8px; max-height:70vh; position:sticky; top:76px;
}
.pos-cats-title { font-size:12px; font-weight:900; color:var(--pos-ink); }
.pos-cats-list { overflow:auto; display:flex; flex-direction:column; gap:4px; padding-left:2px; }
.pos-cat-btn {
    width:100%; text-align:right; border:1px solid transparent; background:transparent; border-radius:12px;
    padding:10px 10px; cursor:pointer; font:inherit; display:flex; align-items:center; justify-content:space-between; gap:8px;
    transition:.12s;
}
.pos-cat-btn:hover { background:#f0fdfa; }
.pos-cat-btn.active { background:#0f766e; color:#fff; border-color:#0f766e; }
.pos-cat-name { font-size:12px; font-weight:800; line-height:1.3; }
.pos-cat-meta { font-size:10px; font-weight:800; opacity:.75; white-space:nowrap; }
.pos-cat-btn.active .pos-cat-meta { opacity:.9; }

.pos-products-panel {
    background:var(--pos-card); border:1px solid var(--pos-line); border-radius:16px; padding:12px;
    min-height:420px;
}
.pos-products-head {
    display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:10px; flex-wrap:wrap;
}
.pos-products-head h3 { margin:0; font-size:14px; font-weight:900; }
.pos-products-head span { font-size:11px; font-weight:700; color:var(--pos-muted); }

.pos-list { display:flex; flex-direction:column; gap:8px; }
.pos-row {
    display:grid; grid-template-columns:56px minmax(0,1fr) auto; gap:10px; align-items:center;
    border:1.5px solid var(--pos-line); border-radius:14px; padding:10px; cursor:pointer; background:#fff;
    transition: border-color .12s, transform .12s, box-shadow .12s;
}
.pos-row:hover { border-color:#5eead4; transform:translateY(-1px); box-shadow:0 8px 20px rgba(15,118,110,.08); }
.pos-row.is-order { border-style:dashed; border-color:#fdba74; background:var(--pos-warn-bg); }
.pos-row.is-order:hover { border-color:#fb923c; box-shadow:0 8px 20px rgba(180,83,9,.08); }
.pos-thumb, .pos-init {
    width:56px; height:56px; border-radius:12px; object-fit:cover; display:flex; align-items:center; justify-content:center;
    font-size:15px; font-weight:900; color:#fff; flex-shrink:0;
}
.pos-init.blue{background:#1a56db}.pos-init.green{background:#059669}.pos-init.amber{background:#d97706}.pos-init.purple{background:#7c3aed}.pos-init.teal{background:#0d9488}.pos-init.indigo{background:#4338ca}.pos-init.pink{background:#db2777}.pos-init.red{background:#dc2626}
.pos-row-name { font-size:13px; font-weight:900; line-height:1.35; margin:0 0 4px; }
.pos-row-meta { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
.pos-pill {
    display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:800; padding:3px 8px; border-radius:999px;
}
.pos-pill-pts { background:#fffbeb; color:#b45309; }
.pos-pill-ok { background:var(--pos-ok-bg); color:var(--pos-ok); }
.pos-pill-warn { background:#ffedd5; color:#9a3412; }
.pos-row-action {
    border:none; border-radius:11px; padding:9px 12px; font:inherit; font-size:11px; font-weight:900; cursor:pointer; white-space:nowrap;
}
.pos-row-action.add { background:#0f766e; color:#fff; }
.pos-row-action.order { background:#fff; color:#9a3412; border:1px solid #fdba74; }
.pos-badge {
    position:absolute; top:-6px; left:-6px; background:#0f766e; color:#fff; font-size:10px; font-weight:900;
    min-width:22px; height:22px; border-radius:999px; display:flex; align-items:center; justify-content:center;
}
.pos-row-media { position:relative; }

.pos-empty {
    text-align:center; padding:3rem 1rem; color:#94a3b8;
}
.pos-empty strong { display:block; color:#64748b; margin-top:6px; }

.pos-cart {
    background:var(--pos-card); border:1px solid var(--pos-line); border-radius:16px; padding:14px;
    display:flex; flex-direction:column; gap:10px; position:sticky; top:76px; max-height:90vh; overflow:auto;
}
.pos-cart-hdr { font-size:14px; font-weight:900; border-bottom:1px solid #eef2f0; padding-bottom:8px; }
.pos-cart-empty { text-align:center; color:#94a3b8; font-size:13px; padding:28px 0; }
.pos-cart-item { display:flex; align-items:center; gap:8px; padding:8px 0; border-bottom:1px solid #f8fafc; }
.pos-ci-img, .pos-ci-init { width:36px; height:36px; border-radius:8px; object-fit:cover; flex-shrink:0; }
.pos-ci-init { display:flex; align-items:center; justify-content:center; color:#fff; font-size:11px; font-weight:900; }
.pos-ci-name { font-size:12px; font-weight:800; line-height:1.3; }
.pos-ci-pts { font-size:11px; color:#d97706; font-weight:700; }
.pos-qty-ctrl { display:flex; align-items:center; gap:4px; }
.pos-qty-btn { width:24px; height:24px; border-radius:7px; border:1.5px solid #e2e8f0; background:#f8fafc; cursor:pointer; font-weight:800; }
.pos-qty-btn.maxed { opacity:.35; cursor:not-allowed; }
.pos-qty-input { width:56px; height:28px; text-align:center; font-size:13px; font-weight:900; border:1.5px solid #cbd5e1; border-radius:7px; font-family:inherit; }
.pos-rm-btn { color:#dc2626; cursor:pointer; font-size:12px; border:none; background:none; font-weight:800; }
.pos-cart-footer { padding-top:8px; border-top:2px solid #f1f5f9; }
.pos-total-row { display:flex; justify-content:space-between; font-size:13px; margin-bottom:6px; }
.pos-total-pts { font-size:1.25rem; font-weight:900; color:#0f766e; }
.pos-btn-checkout {
    width:100%; padding:12px; background:linear-gradient(135deg,#064e3b,#0f766e); color:#fff; border:none;
    border-radius:12px; font:inherit; font-size:14px; font-weight:900; cursor:pointer; margin-top:8px;
}
.pos-btn-checkout:disabled { background:#94a3b8; cursor:not-allowed; }
.pos-btn-clear {
    width:100%; padding:8px; background:#fff; color:#dc2626; border:1.5px solid #fca5a5; border-radius:10px;
    font:inherit; font-size:12px; font-weight:800; cursor:pointer; margin-top:6px;
}
.pos-modal-backdrop { position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:80; display:flex; align-items:center; justify-content:center; padding:16px; }
.pos-modal { background:#fff; border-radius:18px; width:100%; max-width:400px; padding:18px; box-shadow:0 24px 60px rgba(15,23,42,.28); }
.pos-modal h3 { margin:0 0 6px; font-size:16px; font-weight:900; }
.pos-modal p { margin:0 0 14px; font-size:12px; color:var(--pos-muted); }
.pos-modal-actions { display:flex; gap:8px; margin-top:14px; }
.pos-modal-actions button { flex:1; padding:11px; border-radius:12px; border:none; font:inherit; font-weight:900; cursor:pointer; }
</style>

<div class="pos-shell">
    <div class="pos-banner">
        <div class="pos-banner-stat">
            <div class="pos-banner-val">{{ number_format($catalogAll->sum('available_qty')) }}</div>
            <div class="pos-banner-lbl">وحدة نقاط متاحة</div>
        </div>
        <div class="pos-banner-stat">
            <div class="pos-banner-val">{{ number_format($counts['available']) }}</div>
            <div class="pos-banner-lbl">صنف جاهز للتوزيع</div>
        </div>
        <div class="pos-banner-stat">
            <div class="pos-banner-val">{{ number_format($cartPts) }}</div>
            <div class="pos-banner-lbl">نقاط السلة</div>
        </div>
        <div class="pos-banner-stat">
            <div class="pos-banner-val">{{ number_format($this->plumbersCount) }}</div>
            <div class="pos-banner-lbl">سباك بالنظام</div>
        </div>
    </div>

    <div class="pos-layout">
        <div class="pos-main">
            <div class="pos-topbar">
                <div class="pos-field">
                    <label>السباك</label>
                    <div class="pos-plumber-box" x-data="{ open: false }" @click.outside="open = false">
                        @if($this->selectedPlumber)
                            <div class="pos-plumber-selected">
                                <div>
                                    <div class="pos-plumber-selected-name">{{ $this->selectedPlumber->name }}</div>
                                    <div class="pos-plumber-selected-meta">
                                        @if($this->selectedPlumber->phone){{ $this->selectedPlumber->phone }}@endif
                                        @if($this->selectedPlumber->network_code) · {{ $this->selectedPlumber->network_code }}@endif
                                    </div>
                                </div>
                                <button type="button" class="pos-plumber-clear" wire:click="clearPlumber">✕</button>
                            </div>
                        @else
                            <input type="text" class="pos-input"
                                wire:model.live.debounce.200ms="plumberSearch"
                                @focus="open = true" @input="open = true"
                                placeholder="اسم / هاتف / رقم موحّد...">
                            <div class="pos-plumber-dropdown" x-show="open" x-cloak>
                                @forelse($plumbers as $p)
                                    <button type="button" class="pos-plumber-option"
                                        wire:click="selectPlumber({{ $p->id }})" @click="open = false">
                                        <span class="pos-plumber-option-name">{{ $p->name }}</span>
                                        <span class="pos-plumber-option-meta">
                                            @if($p->phone){{ $p->phone }}@endif
                                            @if($p->network_code) · {{ $p->network_code }}@endif
                                        </span>
                                    </button>
                                @empty
                                    <div class="pos-plumber-empty">لا نتائج</div>
                                @endforelse
                            </div>
                        @endif
                    </div>
                </div>
                <div class="pos-field">
                    <label>بحث منتج</label>
                    <input type="text" class="pos-input" wire:model.live.debounce.200ms="search"
                        placeholder="اكتب اسم المنتج مباشرة...">
                </div>
            </div>

            <div class="pos-filters">
                <div class="pos-seg">
                    <button type="button" class="pos-seg-btn {{ $stockFilter === 'available' ? 'active' : '' }}"
                        wire:click="setStockFilter('available')">
                        متاح للتوزيع <span class="pos-seg-count">{{ $counts['available'] }}</span>
                    </button>
                    <button type="button" class="pos-seg-btn {{ $stockFilter === 'all' ? 'active' : '' }}"
                        wire:click="setStockFilter('all')">
                        الكل <span class="pos-seg-count">{{ $counts['all'] }}</span>
                    </button>
                    <button type="button" class="pos-seg-btn {{ $stockFilter === 'order' ? 'active' : '' }}"
                        wire:click="setStockFilter('order')">
                        اطلب من الجملة <span class="pos-seg-count">{{ $counts['order'] }}</span>
                    </button>
                </div>

                <div class="pos-chips-row">
                    @if($stockFilter !== 'all')
                        <span class="pos-chip">
                            {{ $stockFilter === 'available' ? 'متاح للتوزيع' : 'بدون نقاط' }}
                            <button type="button" wire:click="setStockFilter('all')" title="إزالة">×</button>
                        </span>
                    @endif
                    @if($activeCat)
                        <span class="pos-chip">
                            {{ $activeCat['name'] }}
                            <button type="button" wire:click="selectCategory(null)" title="إزالة">×</button>
                        </span>
                    @endif
                    @if(filled($search))
                        <span class="pos-chip">
                            بحث: {{ $search }}
                            <button type="button" wire:click="$set('search','')" title="إزالة">×</button>
                        </span>
                    @endif
                    <button type="button" class="pos-clear" wire:click="clearFilters">مسح الفلاتر</button>
                </div>
            </div>

            <div class="pos-browse">
                <aside class="pos-cats-panel">
                    <div class="pos-cats-title">التصنيفات</div>
                    <input type="text" class="pos-input" style="padding:8px 10px;font-size:12px"
                        wire:model.live.debounce.200ms="categorySearch" placeholder="فلترة تصنيف...">
                    <div class="pos-cats-list">
                        <button type="button" class="pos-cat-btn {{ is_null($selectedCategoryId) ? 'active' : '' }}"
                            wire:click="selectCategory(null)">
                            <span class="pos-cat-name">كل التصنيفات</span>
                            <span class="pos-cat-meta">{{ $counts['all'] }}</span>
                        </button>
                        @foreach($cats as $cat)
                            <button type="button" class="pos-cat-btn {{ $selectedCategoryId == $cat['id'] ? 'active' : '' }}"
                                wire:click="selectCategory({{ $cat['id'] }})">
                                <span class="pos-cat-name">{{ $cat['name'] }}</span>
                                <span class="pos-cat-meta">{{ $cat['available'] }}/{{ $cat['total'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </aside>

                <section class="pos-products-panel">
                    <div class="pos-products-head">
                        <h3>المنتجات</h3>
                        <span>{{ number_format($stock->count()) }} نتيجة معروضة</span>
                    </div>

                    @if($stock->isEmpty())
                        <div class="pos-empty">
                            <div style="font-size:2.4rem">📦</div>
                            <strong>لا توجد منتجات بهذه الفلاتر</strong>
                            <div style="margin-top:6px;font-size:12px">جرّب «الكل» أو امسح البحث/التصنيف</div>
                            <button type="button" class="pos-clear" style="margin-top:12px" wire:click="clearFilters">عرض الكل</button>
                        </div>
                    @else
                        <div class="pos-list">
                            @foreach($stock as $row)
                                @php
                                    $can = (bool) ($row['can_distribute'] ?? false);
                                    $inCart = isset($this->cart[(string)$row['product_id']]);
                                @endphp
                                <div class="pos-row {{ $can ? '' : 'is-order' }}"
                                     wire:click="handleProductClick({{ $row['product_id'] }})"
                                     wire:key="p-{{ $row['product_id'] }}">
                                    <div class="pos-row-media">
                                        @if($inCart)
                                            <div class="pos-badge">{{ $this->cart[(string)$row['product_id']]['quantity'] }}</div>
                                        @endif
                                        @if($row['image'])
                                            <img src="{{ $row['image'] }}" class="pos-thumb" alt=""
                                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                                            <div class="pos-init {{ $row['color_class'] }}" style="display:none">{{ $row['initials'] }}</div>
                                        @else
                                            <div class="pos-init {{ $row['color_class'] }}">{{ $row['initials'] }}</div>
                                        @endif
                                    </div>
                                    <div>
                                        <p class="pos-row-name">{{ $row['name'] }}</p>
                                        <div class="pos-row-meta">
                                            <span class="pos-pill pos-pill-pts">⭐ {{ number_format((float)$row['points_per_unit'], 2) }} / وحدة</span>
                                            @if($can)
                                                <span class="pos-pill pos-pill-ok">متاح {{ number_format($row['available_qty']) }}</span>
                                            @else
                                                <span class="pos-pill pos-pill-warn">لا نقاط — اطلب من الجملة</span>
                                            @endif
                                        </div>
                                    </div>
                                    <button type="button" class="pos-row-action {{ $can ? 'add' : 'order' }}" tabindex="-1">
                                        {{ $can ? 'إضافة + كمية' : 'طلب جملة' }}
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>
            </div>
        </div>

        <aside class="pos-cart">
            <div class="pos-cart-hdr">السلة ({{ count($this->cart) }} صنف)</div>

            @if(empty($this->cart))
                <div class="pos-cart-empty">اختر منتجاً متاحاً ثم أدخل الكمية</div>
            @else
                @foreach($this->cart as $key => $line)
                    <div class="pos-cart-item" wire:key="cart-{{ $key }}">
                        @if($line['image'])
                            <img src="{{ $line['image'] }}" class="pos-ci-img" alt="">
                        @else
                            <div class="pos-ci-init {{ $line['color_class'] ?? 'green' }}">{{ $line['initials'] ?? '؟' }}</div>
                        @endif
                        <div style="flex:1;min-width:0">
                            <div class="pos-ci-name">{{ $line['name'] }}</div>
                            <div class="pos-ci-pts">{{ number_format(floor($line['quantity'] * $line['points_per_unit'])) }} نقطة</div>
                        </div>
                        <div class="pos-qty-ctrl">
                            <button type="button" class="pos-qty-btn" wire:click="decrement('{{ $key }}')">−</button>
                            <input type="number" min="1" max="{{ $line['available_qty'] ?? '' }}" class="pos-qty-input"
                                value="{{ $line['quantity'] }}"
                                wire:change="setQuantity('{{ $key }}', $event.target.value)">
                            @php $atMax = $line['quantity'] >= ($line['available_qty'] ?? 0); @endphp
                            <button type="button" class="pos-qty-btn {{ $atMax ? 'maxed' : '' }}"
                                wire:click="increment('{{ $key }}')">+</button>
                        </div>
                        <button type="button" class="pos-rm-btn" wire:click="removeFromCart('{{ $key }}')">✕</button>
                    </div>
                @endforeach

                <div class="pos-cart-footer">
                    <div class="pos-total-row">
                        <span style="font-weight:700;color:#64748b">إجمالي النقاط</span>
                        <span class="pos-total-pts">{{ number_format($cartPts) }}</span>
                    </div>
                    <div class="pos-total-row" style="font-size:11px;color:#94a3b8">
                        <span>السباك</span>
                        <span>{{ $this->selectedPlumber?->name ?? '—' }}</span>
                    </div>
                    <button type="button" class="pos-btn-checkout" wire:click="checkout" wire:loading.attr="disabled"
                        @disabled(empty($this->cart) || !$plumberId)>
                        <span wire:loading.remove wire:target="checkout">تأكيد التوزيع</span>
                        <span wire:loading wire:target="checkout">جارٍ المعالجة...</span>
                    </button>
                    <button type="button" class="pos-btn-clear" wire:click="clearCart">مسح السلة</button>
                </div>
            @endif
        </aside>
    </div>
</div>

@if($modalProduct)
<div class="pos-modal-backdrop" wire:click.self="closeQtyModal">
    <div class="pos-modal">
        <h3>{{ $modalProduct['name'] }}</h3>
        <p>أدخل الكمية (الحد الأقصى {{ number_format($modalProduct['available_qty']) }})</p>
        <label style="display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">الكمية</label>
        <input type="number" min="1" max="{{ $modalProduct['available_qty'] }}" class="pos-qty-input"
            style="width:100%;height:44px;font-size:16px" wire:model="qtyModalAmount"
            wire:keydown.enter="confirmQtyModal">
        <div class="pos-modal-actions">
            <button type="button" wire:click="closeQtyModal" style="background:#f1f5f9;color:#475569">إلغاء</button>
            <button type="button" wire:click="confirmQtyModal" style="background:#0f766e;color:#fff">إضافة للسلة</button>
        </div>
    </div>
</div>
@endif
</x-filament-panels::page>
