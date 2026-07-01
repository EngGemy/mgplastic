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
                    <select wire:model.live="plumberId" class="pos-select">
                        <option value="">— اختر سباك —</option>
                        @foreach($this->plumbers as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
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
