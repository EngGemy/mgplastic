<x-filament-panels::page>
@php
    $catalog = $this->filteredCatalog;
    $cartPts = $this->cartPoints;
    $cartQty = $this->cartQuantity;
    $cats = $this->categories;
@endphp

<style>
.ord-layout{display:grid;grid-template-columns:1fr 340px;gap:16px;direction:rtl;font-family:'Cairo',sans-serif;min-height:75vh}
@media(max-width:960px){.ord-layout{grid-template-columns:1fr}.ord-side{order:-1}}
.ord-banner{background:linear-gradient(135deg,#0f3d91,#1a56db);color:#fff;border-radius:14px;padding:16px 20px;display:flex;gap:18px;align-items:center;margin-bottom:14px;flex-wrap:wrap}
.ord-banner-stat{text-align:center;flex:1;min-width:90px}
.ord-banner-val{font-size:1.55rem;font-weight:900;line-height:1}
.ord-banner-lbl{font-size:11px;opacity:.85;margin-top:3px}
.ord-toolbar{display:flex;gap:10px;margin-bottom:12px;flex-wrap:wrap}
.ord-input{flex:1;min-width:200px;padding:9px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-family:'Cairo',sans-serif;font-size:13px;background:#fff}
.ord-input:focus{outline:none;border-color:#1a56db}
.ord-cats{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}
.ord-cat{padding:5px 14px;border-radius:999px;border:1.5px solid #e2e8f0;background:#fff;font-size:12px;font-weight:600;cursor:pointer;color:#475569}
.ord-cat:hover,.ord-cat.active{background:#1a56db;color:#fff;border-color:#1a56db}
.ord-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:12px}
.ord-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:12px;cursor:pointer;transition:.15s;text-align:center;position:relative}
.ord-card:hover{border-color:#1a56db;transform:translateY(-2px);box-shadow:0 8px 18px rgba(26,86,219,.12)}
.ord-card.in-cart{border-color:#1a56db;background:#eff6ff}
.ord-img{width:88px;height:88px;object-fit:cover;border-radius:10px;margin:0 auto 8px;display:block;background:#f1f5f9}
.ord-init{width:88px;height:88px;border-radius:10px;margin:0 auto 8px;display:flex;align-items:center;justify-content:center;font-size:1.45rem;font-weight:900;color:#fff}
.ord-init.blue{background:#1a56db}.ord-init.green{background:#059669}.ord-init.amber{background:#d97706}
.ord-init.purple{background:#7c3aed}.ord-init.teal{background:#0d9488}.ord-init.indigo{background:#4338ca}
.ord-init.pink{background:#db2777}.ord-init.red{background:#dc2626}
.ord-name{font-size:12px;font-weight:700;color:#0f172a;line-height:1.35;margin-bottom:4px;min-height:32px}
.ord-pts{font-size:11px;color:#d97706;font-weight:700}
.ord-badge{position:absolute;top:8px;left:8px;background:#1a56db;color:#fff;font-size:10px;font-weight:800;min-width:22px;height:22px;padding:0 6px;border-radius:999px;display:flex;align-items:center;justify-content:center}
.ord-side{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:16px;display:flex;flex-direction:column;gap:12px;position:sticky;top:80px;max-height:90vh;overflow-y:auto}
.ord-side-h{font-size:15px;font-weight:800;color:#0f172a;border-bottom:1px solid #f1f5f9;padding-bottom:10px}
.ord-empty{text-align:center;color:#94a3b8;font-size:13px;padding:28px 8px}
.ord-item{display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid #f8fafc}
.ord-ci-img{width:40px;height:40px;object-fit:cover;border-radius:8px;flex-shrink:0;background:#f1f5f9}
.ord-ci-init{width:40px;height:40px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff;flex-shrink:0}
.ord-ci-name{flex:1;font-size:12px;font-weight:700;color:#0f172a;line-height:1.3}
.ord-ci-pts{font-size:11px;color:#d97706;font-weight:600}
.ord-qty{display:flex;align-items:center;gap:4px}
.ord-qty-btn{width:26px;height:26px;border-radius:7px;border:1.5px solid #e2e8f0;background:#f8fafc;cursor:pointer;font-weight:800;font-size:14px;display:flex;align-items:center;justify-content:center}
.ord-qty-val{font-size:13px;font-weight:800;min-width:20px;text-align:center}
.ord-rm{color:#dc2626;border:none;background:none;cursor:pointer;font-size:12px;font-weight:700}
.ord-note{width:100%;min-height:70px;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-family:'Cairo',sans-serif;font-size:13px;resize:vertical}
.ord-note:focus{outline:none;border-color:#1a56db}
.ord-foot{padding-top:8px;border-top:2px solid #f1f5f9}
.ord-total{display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px}
.ord-total-pts{font-size:1.25rem;font-weight:900;color:#1a56db}
.ord-submit{width:100%;padding:12px;background:linear-gradient(135deg,#0f3d91,#1a56db);color:#fff;border:none;border-radius:11px;font-family:'Cairo',sans-serif;font-size:14px;font-weight:800;cursor:pointer;margin-top:8px}
.ord-submit:disabled{background:#94a3b8;cursor:not-allowed}
.ord-clear{width:100%;padding:8px;background:#fff;color:#dc2626;border:1.5px solid #fca5a5;border-radius:9px;font-family:'Cairo',sans-serif;font-size:12px;font-weight:700;cursor:pointer;margin-top:6px}
.ord-supplier{margin-bottom:14px;background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:14px 16px}
.ord-supplier-label{font-size:13px;font-weight:800;color:#0f172a;margin-bottom:8px}
.ord-supplier-hint{font-size:12px;color:#64748b;margin-bottom:8px}
.ord-select{width:100%;padding:10px 12px;border:1.5px solid #cbd5e1;border-radius:10px;font-family:'Cairo',sans-serif;font-size:13px;font-weight:700;background:#f8fafc;color:#0f172a}
.ord-select:focus{outline:none;border-color:#1a56db;background:#fff}
.ord-select.missing{border-color:#f87171;background:#fef2f2}
</style>

@if($this->needsWholesaleSupplier())
    @php $suppliers = $this->wholesaleSuppliers; @endphp
    <div class="ord-supplier">
        <div class="ord-supplier-label">موزّع الجملة</div>
        <div class="ord-supplier-hint">اختر المحل اللي هتبعت له الطلب من قائمة محلات الجملة المعتمدة.</div>
        @if($suppliers->isEmpty())
            <div style="color:#b91c1c;font-size:13px;font-weight:700">لا يوجد موزّع جملة معتمد حالياً — تواصل مع الإدارة.</div>
        @else
            <select wire:model.live="supplierId" class="ord-select {{ $this->supplierId ? '' : 'missing' }}">
                <option value="">— اختر موزّع الجملة —</option>
                @foreach($suppliers as $supplier)
                    <option value="{{ $supplier['id'] }}">{{ $supplier['label'] }}</option>
                @endforeach
            </select>
        @endif
    </div>
@endif

<div class="ord-banner">
    <div class="ord-banner-stat">
        <div class="ord-banner-val">{{ $catalog->count() }}</div>
        <div class="ord-banner-lbl">منتج ظاهر</div>
    </div>
    <div class="ord-banner-stat">
        <div class="ord-banner-val">{{ count($this->cart) }}</div>
        <div class="ord-banner-lbl">أصناف في الطلب</div>
    </div>
    <div class="ord-banner-stat">
        <div class="ord-banner-val">{{ number_format($cartQty) }}</div>
        <div class="ord-banner-lbl">إجمالي الكمية</div>
    </div>
    <div class="ord-banner-stat">
        <div class="ord-banner-val">{{ number_format($cartPts) }}</div>
        <div class="ord-banner-lbl">نقاط الطلب</div>
    </div>
</div>

<div class="ord-layout">
    <div>
        <div class="ord-toolbar">
            <input type="search" class="ord-input" wire:model.live.debounce.300ms="search" placeholder="ابحث عن منتج بالاسم...">
        </div>

        <div class="ord-cats">
            <button type="button" class="ord-cat {{ $this->selectedCategoryId === null ? 'active' : '' }}" wire:click="selectCategory(null)">الكل</button>
            @foreach($cats as $cat)
                <button type="button" class="ord-cat {{ (int)$this->selectedCategoryId === (int)$cat->id ? 'active' : '' }}" wire:click="selectCategory({{ $cat->id }})">{{ $cat->display_name }}</button>
            @endforeach
        </div>

        @if($catalog->isEmpty())
            <div class="ord-empty">لا توجد منتجات مطابقة للبحث</div>
        @else
            <div class="ord-grid">
                @foreach($catalog as $row)
                    @php $key = (string) $row['product_id']; $inCart = isset($this->cart[$key]); @endphp
                    <div class="ord-card {{ $inCart ? 'in-cart' : '' }}" wire:click="addToCart({{ $row['product_id'] }})" title="اضغط للإضافة">
                        @if($inCart)
                            <div class="ord-badge">
                                @if(!empty($this->cart[$key]['pieces_per_box']))
                                    {{ $this->cart[$key]['boxes'] }}ص
                                @else
                                    {{ $this->cart[$key]['quantity'] }}
                                @endif
                            </div>
                        @endif
                        @if($row['image'])
                            <img src="{{ $row['image'] }}" alt="" class="ord-img" loading="lazy">
                        @else
                            <div class="ord-init {{ $row['color_class'] }}">{{ $row['initials'] }}</div>
                        @endif
                        <div class="ord-name">{{ $row['name'] }}</div>
                        @if(!empty($row['pieces_per_box']))
                            <div class="ord-pts">صندوق × {{ $row['pieces_per_box'] }} قطعة</div>
                        @elseif(($row['points_per_unit'] ?? 0) > 0)
                            <div class="ord-pts">{{ number_format($row['points_per_unit'], 1) }} نقطة / قطعة</div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <aside class="ord-side">
        <div class="ord-side-h">سلة الطلب</div>

        @if(empty($this->cart))
            <div class="ord-empty">اضغط على أي منتج لإضافته للطلب</div>
        @else
            @foreach($this->cart as $key => $line)
                <div class="ord-item" wire:key="cart-{{ $key }}">
                    @if($line['image'])
                        <img src="{{ $line['image'] }}" class="ord-ci-img" alt="">
                    @else
                        <div class="ord-ci-init {{ $line['color_class'] }}">{{ $line['initials'] }}</div>
                    @endif
                    <div style="flex:1;min-width:0">
                        <div class="ord-ci-name">{{ $line['name'] }}</div>
                        @if(!empty($line['pieces_per_box']))
                            <div class="ord-ci-pts">{{ $line['boxes'] }} صندوق × {{ $line['pieces_per_box'] }} = {{ number_format($line['quantity']) }} قطعة</div>
                        @endif
                    </div>
                    <div class="ord-qty">
                        <button type="button" class="ord-qty-btn" wire:click="decrement('{{ $key }}')">−</button>
                        @if(!empty($line['pieces_per_box']))
                            <input type="number" min="1" class="ord-qty-val"
                                style="width:56px;height:28px;text-align:center;font-weight:800;border:1.5px solid #cbd5e1;border-radius:7px;font-family:inherit;background:#fff"
                                value="{{ $line['boxes'] }}"
                                wire:change="setBoxes('{{ $key }}', $event.target.value)"
                                title="عدد الصناديق">
                        @else
                            <input type="number" min="1" class="ord-qty-val"
                                style="width:56px;height:28px;text-align:center;font-weight:800;border:1.5px solid #cbd5e1;border-radius:7px;font-family:inherit;background:#fff"
                                value="{{ $line['quantity'] }}"
                                wire:change="setQuantity('{{ $key }}', $event.target.value)"
                                title="عدد القطع">
                        @endif
                        <button type="button" class="ord-qty-btn" wire:click="increment('{{ $key }}')">+</button>
                    </div>
                    <button type="button" class="ord-rm" wire:click="removeFromCart('{{ $key }}')">حذف</button>
                </div>
            @endforeach
        @endif

        <textarea class="ord-note" wire:model.blur="note" placeholder="ملاحظات للمورّد (اختياري)..."></textarea>

        <div class="ord-foot">
            <div class="ord-total"><span>الكمية (قطع)</span><strong>{{ number_format($cartQty) }}</strong></div>
            <div class="ord-total"><span>النقاط</span><span class="ord-total-pts">{{ number_format($cartPts) }}</span></div>
            <button type="button" class="ord-submit" wire:click="submitOrder"
                @disabled(empty($this->cart) || ($this->needsWholesaleSupplier() && ! $this->supplierId))
                wire:loading.attr="disabled">
                إرسال الطلب
            </button>
            @if(! empty($this->cart))
                <button type="button" class="ord-clear" wire:click="clearCart">تفريغ السلة</button>
            @endif
        </div>
    </aside>
</div>
</x-filament-panels::page>
