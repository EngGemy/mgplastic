<x-filament-panels::page>
@php
    $stock    = $this->filteredStock;
    $cartPts  = $this->cartPoints;
    $plumbers = $this->plumbers;
    $cats     = $this->categories;
    $modalProduct = $this->qtyModalProduct;
@endphp

<style>
.pos-layout { display:grid; grid-template-columns:1fr 320px; gap:16px; direction:rtl; font-family:'Cairo',sans-serif; min-height:80vh; }
@media(max-width:900px){ .pos-layout{grid-template-columns:1fr} .pos-sidebar{order:-1} }
.pos-banner { background:linear-gradient(135deg,#064e3b,#059669); color:white; border-radius:12px; padding:14px 20px; display:flex; gap:20px; align-items:center; margin-bottom:16px; flex-wrap:wrap; }
.pos-banner-stat { text-align:center; flex:1; min-width:100px; }
.pos-banner-val  { font-size:1.6rem; font-weight:900; line-height:1; }
.pos-banner-lbl  { font-size:11px; opacity:.8; margin-top:2px; }
.pos-toolbar { display:flex; gap:10px; align-items:center; margin-bottom:14px; flex-wrap:wrap; }
.pos-input  { flex:2; min-width:200px; padding:8px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-family:'Cairo',sans-serif; font-size:13px; }
.pos-input:focus { outline:none; border-color:#059669; }
.pos-cats { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
.pos-cat  { padding:5px 14px; border-radius:999px; border:1.5px solid #e2e8f0; background:#fff; font-size:12px; font-weight:600; cursor:pointer; color:#475569; transition:.15s; }
.pos-cat:hover, .pos-cat.active { background:#059669; color:white; border-color:#059669; }
.pos-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:12px; }
.pos-card { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; padding:12px; cursor:pointer; transition:.15s; text-align:center; position:relative; }
.pos-card:hover { border-color:#059669; transform:translateY(-2px); box-shadow:0 4px 12px rgba(5,150,105,.1); }
.pos-card.order-only { border-style:dashed; border-color:#f59e0b; background:#fffbeb; }
.pos-card.order-only:hover { border-color:#d97706; box-shadow:0 4px 12px rgba(217,119,6,.12); }
.pos-img  { width:80px; height:80px; object-fit:cover; border-radius:8px; margin:0 auto 8px; display:block; }
.pos-init { width:80px; height:80px; border-radius:8px; margin:0 auto 8px; display:flex; align-items:center; justify-content:center; font-size:1.5rem; font-weight:900; color:white; }
.pos-init.blue{background:#1a56db}.pos-init.green{background:#059669}.pos-init.amber{background:#d97706}.pos-init.purple{background:#7c3aed}.pos-init.teal{background:#0d9488}.pos-init.indigo{background:#4338ca}.pos-init.pink{background:#db2777}.pos-init.red{background:#dc2626}
.pos-name  { font-size:12px; font-weight:600; color:#1e293b; margin-bottom:4px; line-height:1.3; }
.pos-pts   { font-size:11px; color:#d97706; font-weight:700; }
.pos-avail { font-size:10px; color:#94a3b8; margin-top:2px; }
.pos-order-hint { font-size:10px; color:#b45309; font-weight:700; margin-top:4px; }
.pos-badge { position:absolute; top:6px; left:6px; background:#059669; color:white; font-size:10px; font-weight:700; width:22px; height:22px; border-radius:50%; display:flex; align-items:center; justify-content:center; }
.pos-sidebar  { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; padding:16px; display:flex; flex-direction:column; gap:12px; position:sticky; top:80px; max-height:90vh; overflow-y:auto; }
.pos-cart-hdr { font-size:14px; font-weight:700; color:#1e293b; border-bottom:1px solid #f1f5f9; padding-bottom:8px; }
.pos-cart-empty { text-align:center; color:#94a3b8; font-size:13px; padding:20px 0; }
.pos-cart-item { display:flex; align-items:center; gap:8px; padding:8px 0; border-bottom:1px solid #f8fafc; }
.pos-ci-img  { width:36px; height:36px; object-fit:cover; border-radius:6px; flex-shrink:0; }
.pos-ci-init { width:36px; height:36px; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:800; color:white; flex-shrink:0; }
.pos-ci-name { flex:1; font-size:12px; font-weight:600; color:#1e293b; line-height:1.3; }
.pos-ci-pts  { font-size:11px; color:#d97706; font-weight:600; }
.pos-qty-ctrl { display:flex; align-items:center; gap:4px; }
.pos-qty-btn  { width:24px; height:24px; border-radius:6px; border:1.5px solid #e2e8f0; background:#f8fafc; cursor:pointer; font-weight:700; font-size:13px; display:flex; align-items:center; justify-content:center; }
.pos-qty-btn.maxed { opacity:.35; cursor:not-allowed; border-color:#fecaca; background:#fff5f5; color:#dc2626; }
.pos-qty-input { width:56px; height:28px; text-align:center; font-size:13px; font-weight:800; color:#0f172a; border:1.5px solid #cbd5e1; border-radius:7px; font-family:'Cairo',sans-serif; background:#fff; }
.pos-qty-input:focus { outline:none; border-color:#059669; }
.pos-rm-btn { color:#dc2626; cursor:pointer; font-size:12px; border:none; background:none; }
.pos-cart-footer { padding-top:8px; border-top:2px solid #f1f5f9; }
.pos-total-row { display:flex; justify-content:space-between; font-size:13px; margin-bottom:6px; }
.pos-total-pts { font-size:1.3rem; font-weight:900; color:#059669; }
.pos-btn-checkout { width:100%; padding:12px; background:linear-gradient(135deg,#064e3b,#059669); color:white; border:none; border-radius:10px; font-family:'Cairo',sans-serif; font-size:14px; font-weight:700; cursor:pointer; margin-top:8px; }
.pos-btn-checkout:disabled { background:#94a3b8; cursor:not-allowed; }
.pos-btn-clear { width:100%; padding:8px; background:#fff; color:#dc2626; border:1.5px solid #fca5a5; border-radius:8px; font-family:'Cairo',sans-serif; font-size:12px; font-weight:600; cursor:pointer; margin-top:6px; }
.pos-plumber-box { position:relative; flex:1.2; min-width:220px; }
.pos-plumber-selected { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 10px; border:1.5px solid #059669; border-radius:8px; background:#ecfdf5; }
.pos-plumber-selected-name { font-size:13px; font-weight:800; color:#065f46; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pos-plumber-selected-meta { font-size:11px; color:#047857; margin-top:1px; }
.pos-plumber-clear { border:none; background:#fee2e2; color:#b91c1c; width:28px; height:28px; border-radius:7px; cursor:pointer; font-weight:800; flex-shrink:0; }
.pos-plumber-dropdown { position:absolute; z-index:40; top:calc(100% + 4px); right:0; left:0; background:#fff; border:1.5px solid #e2e8f0; border-radius:10px; box-shadow:0 10px 30px rgba(15,23,42,.12); max-height:280px; overflow:auto; }
.pos-plumber-option { width:100%; text-align:right; padding:10px 12px; border:none; background:#fff; cursor:pointer; border-bottom:1px solid #f1f5f9; font-family:'Cairo',sans-serif; }
.pos-plumber-option:hover { background:#f0fdf4; }
.pos-plumber-option-name { font-size:13px; font-weight:700; color:#0f172a; display:block; }
.pos-plumber-option-meta { font-size:11px; color:#64748b; margin-top:2px; display:block; }
.pos-plumber-empty { padding:14px; text-align:center; color:#94a3b8; font-size:12px; }
.pos-modal-backdrop { position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:80; display:flex; align-items:center; justify-content:center; padding:16px; }
.pos-modal { background:#fff; border-radius:16px; width:100%; max-width:380px; padding:18px; box-shadow:0 20px 50px rgba(15,23,42,.25); direction:rtl; font-family:'Cairo',sans-serif; }
.pos-modal h3 { margin:0 0 6px; font-size:16px; font-weight:900; color:#0f172a; }
.pos-modal p { margin:0 0 14px; font-size:12px; color:#64748b; }
.pos-modal-actions { display:flex; gap:8px; margin-top:14px; }
.pos-modal-actions button { flex:1; padding:10px; border-radius:10px; border:none; font-family:inherit; font-weight:800; cursor:pointer; }
</style>

<div class="pos-banner">
    <div class="pos-banner-stat">
        <div class="pos-banner-val">{{ number_format($stock->sum('available_qty')) }}</div>
        <div class="pos-banner-lbl">وحدة نقاط متاحة</div>
    </div>
    <div class="pos-banner-stat">
        <div class="pos-banner-val">{{ $stock->where('can_distribute', true)->count() }}</div>
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
    <div>
        <div class="pos-toolbar">
            <div class="pos-plumber-box" x-data="{ open: false }" @click.outside="open = false">
                @if($this->selectedPlumber)
                    <div class="pos-plumber-selected">
                        <div>
                            <div class="pos-plumber-selected-name">🔧 {{ $this->selectedPlumber->name }}</div>
                            <div class="pos-plumber-selected-meta">
                                @if($this->selectedPlumber->phone)📞 {{ $this->selectedPlumber->phone }}@endif
                                @if($this->selectedPlumber->network_code) · {{ $this->selectedPlumber->network_code }}@endif
                            </div>
                        </div>
                        <button type="button" class="pos-plumber-clear" wire:click="clearPlumber" title="تغيير السباك">✕</button>
                    </div>
                @else
                    <input type="text" class="pos-input" style="width:100%"
                        wire:model.live.debounce.200ms="plumberSearch"
                        @focus="open = true" @input="open = true"
                        placeholder="🔍 ابحث عن سباك بالاسم أو الهاتف أو الرقم الموحّد...">
                    <div class="pos-plumber-dropdown" x-show="open" x-cloak>
                        @forelse($plumbers as $p)
                            <button type="button" class="pos-plumber-option"
                                wire:click="selectPlumber({{ $p->id }})" @click="open = false">
                                <span class="pos-plumber-option-name">{{ $p->name }}</span>
                                <span class="pos-plumber-option-meta">
                                    @if($p->phone)📞 {{ $p->phone }}@endif
                                    @if($p->network_code) · {{ $p->network_code }}@endif
                                </span>
                            </button>
                        @empty
                            <div class="pos-plumber-empty">
                                {{ filled($plumberSearch) ? 'لا نتائج' : 'لا يوجد سباكون نشطون' }}
                            </div>
                        @endforelse
                    </div>
                @endif
            </div>
            <input type="text" wire:model.live.debounce.250ms="search" class="pos-input" placeholder="🔍 ابحث عن منتج...">
        </div>

        @if($cats->isNotEmpty())
        <div class="pos-cats">
            <button type="button" wire:click="selectCategory(null)" class="pos-cat {{ is_null($selectedCategoryId) ? 'active' : '' }}">الكل</button>
            @foreach($cats as $cat)
                <button type="button" wire:click="selectCategory({{ $cat->id }})"
                    class="pos-cat {{ $selectedCategoryId == $cat->id ? 'active' : '' }}">
                    {{ $cat->translate('ar')?->name ?? $cat->name }}
                </button>
            @endforeach
        </div>
        @endif

        @if($stock->isEmpty())
            <div style="text-align:center;padding:3rem;color:#94a3b8;">
                <div style="font-size:3rem;margin-bottom:8px;">📦</div>
                <div style="font-weight:600;">لا توجد منتجات في الكتالوج</div>
            </div>
        @else
        <div class="pos-grid">
            @foreach($stock as $row)
                @php
                    $inCart = isset($this->cart[(string)$row['product_id']]);
                    $can = (bool) ($row['can_distribute'] ?? false);
                @endphp
                <div class="pos-card {{ $can ? '' : 'order-only' }}"
                     wire:click="handleProductClick({{ $row['product_id'] }})">
                    @if($inCart)
                        <div class="pos-badge">{{ $this->cart[(string)$row['product_id']]['quantity'] }}</div>
                    @endif

                    @if($row['image'])
                        <img src="{{ $row['image'] }}" class="pos-img" alt="{{ $row['name'] }}"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <div class="pos-init {{ $row['color_class'] }}" style="display:none;">{{ $row['initials'] }}</div>
                    @else
                        <div class="pos-init {{ $row['color_class'] }}">{{ $row['initials'] }}</div>
                    @endif

                    <div class="pos-name">{{ $row['name'] }}</div>
                    <div class="pos-pts">⭐ {{ number_format((float)$row['points_per_unit'], 2) }} نقطة/وحدة</div>
                    @if($can)
                        <div class="pos-avail">متاح للتوزيع: {{ number_format($row['available_qty']) }}</div>
                    @else
                        <div class="pos-order-hint">لا نقاط — اضغط للطلب من الجملة</div>
                    @endif
                </div>
            @endforeach
        </div>
        @endif
    </div>

    <aside class="pos-sidebar">
        <div class="pos-cart-hdr">🛒 السلة ({{ count($this->cart) }} صنف)</div>

        @if(empty($this->cart))
            <div class="pos-cart-empty">اضغط على المنتج وأدخل الكمية</div>
        @else
            @foreach($this->cart as $key => $line)
            <div class="pos-cart-item" wire:key="cart-{{ $key }}">
                @if($line['image'])
                    <img src="{{ $line['image'] }}" class="pos-ci-img" alt="">
                @else
                    <div class="pos-ci-init {{ $line['color_class'] ?? 'green' }}">{{ $line['initials'] ?? '؟' }}</div>
                @endif

                <div style="flex:1;min-width:0;">
                    <div class="pos-ci-name">{{ $line['name'] }}</div>
                    <div class="pos-ci-pts">{{ number_format(floor($line['quantity'] * $line['points_per_unit'])) }} نقطة</div>
                </div>

                <div class="pos-qty-ctrl">
                    <button type="button" class="pos-qty-btn" wire:click="decrement('{{ $key }}')">−</button>
                    <input type="number" min="1" max="{{ $line['available_qty'] ?? '' }}"
                        class="pos-qty-input"
                        value="{{ $line['quantity'] }}"
                        wire:change="setQuantity('{{ $key }}', $event.target.value)"
                        title="اكتب الكمية يدوياً">
                    @php $atMax = $line['quantity'] >= ($line['available_qty'] ?? 0); @endphp
                    <button type="button" class="pos-qty-btn {{ $atMax ? 'maxed' : '' }}"
                        wire:click="increment('{{ $key }}')">+</button>
                </div>

                <button type="button" class="pos-rm-btn" wire:click="removeFromCart('{{ $key }}')">✕</button>
            </div>
            @endforeach

            <div class="pos-cart-footer">
                <div class="pos-total-row">
                    <span style="font-weight:600;color:#64748b;">إجمالي النقاط:</span>
                    <span class="pos-total-pts">{{ number_format($cartPts) }}</span>
                </div>
                <div class="pos-total-row" style="font-size:11px;color:#94a3b8;">
                    <span>السباك:</span>
                    <span>{{ $this->selectedPlumber?->name ?? '—' }}</span>
                </div>

                <button type="button" class="pos-btn-checkout"
                    wire:click="checkout" wire:loading.attr="disabled"
                    @disabled(empty($this->cart) || !$plumberId)>
                    <span wire:loading.remove wire:target="checkout">✓ تأكيد التوزيع</span>
                    <span wire:loading wire:target="checkout">جارٍ المعالجة...</span>
                </button>
                <button type="button" class="pos-btn-clear" wire:click="clearCart">مسح السلة</button>
            </div>
        @endif
    </aside>
</div>

@if($modalProduct)
<div class="pos-modal-backdrop" wire:click.self="closeQtyModal">
    <div class="pos-modal">
        <h3>{{ $modalProduct['name'] }}</h3>
        <p>أدخل الكمية للتوزيع (الحد الأقصى {{ number_format($modalProduct['available_qty']) }})</p>
        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:6px">الكمية</label>
        <input type="number" min="1" max="{{ $modalProduct['available_qty'] }}"
            class="pos-qty-input" style="width:100%;height:42px;font-size:16px"
            wire:model="qtyModalAmount"
            wire:keydown.enter="confirmQtyModal">
        <div class="pos-modal-actions">
            <button type="button" wire:click="closeQtyModal" style="background:#f1f5f9;color:#475569">إلغاء</button>
            <button type="button" wire:click="confirmQtyModal" style="background:#059669;color:#fff">إضافة للسلة</button>
        </div>
    </div>
</div>
@endif
</x-filament-panels::page>
