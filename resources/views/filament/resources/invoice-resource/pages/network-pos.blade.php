<x-filament-panels::page dir="rtl">
    @php
        $isRetail = ($posMode ?? '') === 'retail';
        $isPlumber = ($posMode ?? '') === 'plumber';
        $stock = $this->filteredStock;
        $wallet = $this->walletBalance;
        $cartPts = $this->cartPoints;
        $pointsOk = $cartPts <= $wallet;
    @endphp

    <div class="pos-wrap">

        {{-- ملخص المخزون والنقاط --}}
        <div class="pos-warehouse-banner">
            <div class="pos-warehouse-stat">
                <span class="pos-warehouse-label">رصيد النقاط</span>
                <strong class="pos-warehouse-value pos-warehouse-value--points">{{ number_format($wallet) }}</strong>
                <span class="pos-warehouse-sub">نقطة متاحة للبيع</span>
            </div>
            <div class="pos-warehouse-stat">
                <span class="pos-warehouse-label">وحدات المخزن</span>
                <strong class="pos-warehouse-value">{{ number_format($summary['product_units'] ?? 0) }}</strong>
                <span class="pos-warehouse-sub">{{ $summary['product_types'] ?? 0 }} صنف</span>
            </div>
            <div class="pos-warehouse-stat">
                <span class="pos-warehouse-label">في السلة الآن</span>
                <strong class="pos-warehouse-value {{ $pointsOk ? '' : 'pos-warehouse-value--danger' }}">{{ number_format($cartPts) }}</strong>
                <span class="pos-warehouse-sub">نقطة @if(!$pointsOk) — يتجاوز رصيدك! @endif</span>
            </div>
        </div>

        <div class="pos-header">
            <div class="pos-header-field pos-invoice-no">
                <label class="pos-label">{{ $isPlumber ? 'مرجع العملية' : 'رقم الفاتورة (التالي)' }}</label>
                <div class="pos-invoice-number">{{ $this->nextInvoiceNumber }}</div>
                <p class="pos-invoice-no-hint">
                    {{ $isRetail ? 'فاتورة صادر — جملة → قطاعي' : 'توزيع نقاط — قطاعي → سباك' }}
                </p>
            </div>

            <div class="pos-header-field">
                <label class="pos-label">{{ $isRetail ? 'تاجر القطاعي (المشتري)' : 'السباك (المستفيد)' }}</label>
                @if($isRetail)
                    <select wire:model.live="retailTraderId" class="pos-select">
                        <option value="">— اختر تاجر قطاعي —</option>
                        @foreach($this->retailTraders as $t)
                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                        @endforeach
                    </select>
                @else
                    <div x-data="{ open: false }" @click.outside="open = false" style="position:relative">
                        @if($this->selectedPlumber)
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 10px;border:1.5px solid #059669;border-radius:8px;background:#ecfdf5">
                                <div style="min-width:0">
                                    <div style="font-size:13px;font-weight:800;color:#065f46">🔧 {{ $this->selectedPlumber->name }}</div>
                                    <div style="font-size:11px;color:#047857">
                                        @if($this->selectedPlumber->phone)📞 {{ $this->selectedPlumber->phone }}@endif
                                        @if($this->selectedPlumber->network_code) · {{ $this->selectedPlumber->network_code }}@endif
                                    </div>
                                </div>
                                <button type="button" wire:click="clearPlumber"
                                    style="border:none;background:#fee2e2;color:#b91c1c;width:28px;height:28px;border-radius:7px;cursor:pointer;font-weight:800">✕</button>
                            </div>
                        @else
                            <input type="text" class="pos-input" style="width:100%"
                                wire:model.live.debounce.200ms="plumberSearch"
                                @focus="open = true"
                                @input="open = true"
                                placeholder="ابحث بالاسم أو الهاتف أو الرقم الموحّد...">
                            <div x-show="open" x-cloak
                                style="position:absolute;z-index:40;top:calc(100% + 4px);right:0;left:0;background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;box-shadow:0 10px 30px rgba(15,23,42,.12);max-height:280px;overflow:auto">
                                @forelse($this->plumbers as $p)
                                    <button type="button" wire:click="selectPlumber({{ $p->id }})" @click="open = false"
                                        style="width:100%;text-align:right;padding:10px 12px;border:none;background:#fff;cursor:pointer;border-bottom:1px solid #f1f5f9;font-family:inherit">
                                        <span style="display:block;font-size:13px;font-weight:700">{{ $p->name }}</span>
                                        <span style="display:block;font-size:11px;color:#64748b;margin-top:2px">
                                            @if($p->phone)📞 {{ $p->phone }}@endif
                                            @if($p->network_code) · {{ $p->network_code }}@endif
                                        </span>
                                    </button>
                                @empty
                                    <div style="padding:14px;text-align:center;color:#94a3b8;font-size:12px">
                                        {{ filled($plumberSearch ?? '') ? 'لا نتائج' : 'لا يوجد سباكون نشطون' }}
                                    </div>
                                @endforelse
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <div class="pos-header-field pos-search-wrap">
                <label class="pos-label">بحث في المخزن</label>
                <input type="text" wire:model.live.debounce.300ms="search" class="pos-input" placeholder="ابحث بالاسم...">
            </div>
        </div>

        <div class="pos-body">

            @if($this->categories->isNotEmpty())
            <aside class="pos-categories">
                <p class="pos-side-title">التصنيفات</p>
                <button type="button" wire:click="selectCategory(null)"
                    class="pos-cat-btn {{ is_null($selectedCategoryId) ? 'pos-cat-btn--active' : '' }}">الكل</button>
                @foreach($this->categories as $cat)
                    <button type="button" wire:click="selectCategory({{ $cat->id }})"
                        class="pos-cat-btn {{ $selectedCategoryId === $cat->id ? 'pos-cat-btn--active' : '' }}">
                        {{ $cat->name }}
                    </button>
                @endforeach
            </aside>
            @endif

            <section class="pos-products" @if($this->categories->isEmpty()) style="grid-column: span 2" @endif>
                <p class="pos-side-title">مخزونك — المنتجات المتاحة فقط</p>
                <div class="pos-product-grid">
                    @forelse($stock as $row)
                        @php
                            $inCart = $cart[(string)$row['product_id']]['quantity'] ?? 0;
                            $remaining = (int)$row['available_qty'] - (int)$inCart;
                        @endphp
                        <button type="button"
                            wire:click="addProduct({{ $row['product_id'] }})"
                            class="pos-product-card {{ $remaining <= 0 ? 'pos-product-card--disabled' : '' }}"
                            @disabled($remaining <= 0)>
                            @if($row['image'])
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($row['image']) }}" alt="" class="pos-product-img">
                            @else
                                <div class="pos-product-img pos-product-img--empty">📦</div>
                            @endif
                            <span class="pos-product-name">{{ $row['name'] }}</span>
                            <span class="pos-stock-badge">متوفر: {{ number_format($row['available_qty']) }}</span>
                            <span class="pos-product-points">{{ number_format($row['points_per_unit'], 2) }} نقطة/وحدة</span>
                        </button>
                    @empty
                        <p class="pos-empty">لا يوجد مخزون متاح — استلم فواتير وارد أولاً</p>
                    @endforelse
                </div>
            </section>

            <aside class="pos-cart">
                <p class="pos-side-title">🧾 {{ $isRetail ? 'فاتورة الصادر' : 'توزيع النقاط' }}</p>

                @if(empty($cart))
                    <p class="pos-empty">السلة فارغة — اختر من المخزن</p>
                @else
                    <div class="pos-cart-lines">
                        @foreach($cart as $key => $line)
                            <div class="pos-cart-line" wire:key="cart-{{ $key }}">
                                <div class="pos-cart-line-top">
                                    <span class="pos-cart-name">{{ $line['name'] }}</span>
                                    <button type="button" wire:click="removeLine('{{ $key }}')" class="pos-remove">×</button>
                                </div>
                                <div class="pos-cart-line-mid">
                                    <div class="pos-qty">
                                        <button type="button" wire:click="decrementQty('{{ $key }}')">−</button>
                                        <span>{{ $line['quantity'] }}</span>
                                        <button type="button" wire:click="incrementQty('{{ $key }}')"
                                            @disabled($line['quantity'] >= $line['max_qty'])>+</button>
                                    </div>
                                    <span class="pos-stock-badge pos-stock-badge--sm">حد أقصى {{ $line['max_qty'] }}</span>
                                </div>
                                <div class="pos-cart-line-bot">
                                    <span class="pos-line-points">
                                        ⭐ {{ (int) floor($line['quantity'] * $line['points_per_unit']) }} نقطة
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="pos-totals">
                    <div class="pos-total-row pos-total-row--points">
                        <span>إجمالي النقاط</span>
                        <strong class="{{ $pointsOk ? '' : 'pos-text-danger' }}">{{ number_format($cartPts) }} نقطة</strong>
                    </div>
                    <div class="pos-total-row">
                        <span>رصيدك بعد البيع</span>
                        <strong>{{ number_format(max(0, $wallet - $cartPts)) }} نقطة</strong>
                    </div>
                </div>

                @php
                    $canIssue = !empty($cart)
                        && ($isRetail ? $retailTraderId : $plumberId)
                        && $pointsOk
                        && $cartPts > 0;
                @endphp

                <button type="button"
                    wire:click="issueInvoice"
                    wire:loading.attr="disabled"
                    class="pos-issue-btn"
                    @disabled(! $canIssue)>
                    <span wire:loading.remove wire:target="issueInvoice">
                        ✓ {{ $isRetail ? 'إصدار فاتورة صادر' : 'تأكيد التوزيع للسباك' }}
                    </span>
                    <span wire:loading wire:target="issueInvoice">جاري التنفيذ...</span>
                </button>

                <p class="pos-hint">
                    @if($isRetail)
                        لا يمكن البيع بكمية أكبر من المخزن أو نقاط أكثر من رصيدك — أي تجاوز يُرفض تلقائياً.
                    @else
                        تُخصم النقاط من رصيدك وتُضاف لمحفظة السباك فور التأكيد.
                    @endif
                </p>
            </aside>
        </div>
    </div>
</x-filament-panels::page>
