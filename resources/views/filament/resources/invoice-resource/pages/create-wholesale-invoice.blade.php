<x-filament-panels::page dir="rtl">
    <div class="pos-wrap">

        {{-- ── Header: رقم الفاتورة + الموزع ── --}}
        <div class="pos-header">
            <div class="pos-header-field pos-invoice-no">
                <label class="pos-label">رقم الفاتورة (التالي)</label>
                <div class="pos-invoice-number">{{ $this->nextInvoiceNumber }}</div>
                <p class="pos-invoice-no-hint">يُثبَّت عند الإصدار ويُستخدم في التوزيعات والتقارير</p>
            </div>
            @if(auth()->user()?->isWholesaleDistributor())
                <div class="pos-header-field">
                    <label class="pos-label">موزع الجملة</label>
                    <div class="pos-invoice-number" style="font-size:1rem">{{ auth()->user()->name }}</div>
                </div>
            @else
                <div class="pos-header-field">
                    <label class="pos-label">موزع الجملة (المستلم)</label>
                    <select wire:model.live="wholesaleDistributorId" class="pos-select">
                        <option value="">— اختر موزع الجملة —</option>
                        @foreach($this->wholesalers as $w)
                            <option value="{{ $w->id }}">{{ $w->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="pos-header-field pos-search-wrap">
                <label class="pos-label">بحث منتج</label>
                <input type="text" wire:model.live.debounce.300ms="search" class="pos-input" placeholder="ابحث بالاسم...">
            </div>
        </div>

        <div class="pos-body">

            {{-- ── التصنيفات ── --}}
            <aside class="pos-categories">
                <p class="pos-side-title">التصنيفات</p>
                <button type="button"
                    wire:click="selectCategory(null)"
                    class="pos-cat-btn {{ is_null($selectedCategoryId) ? 'pos-cat-btn--active' : '' }}">
                    الكل
                </button>
                @foreach($this->categories as $parent)
                    <button type="button"
                        wire:click="selectCategory({{ $parent->id }})"
                        class="pos-cat-btn {{ $selectedCategoryId === $parent->id ? 'pos-cat-btn--active' : '' }}">
                        {{ $parent->name }}
                    </button>
                    @foreach($parent->children as $child)
                        <button type="button"
                            wire:click="selectCategory({{ $child->id }})"
                            class="pos-cat-btn pos-cat-btn--child {{ $selectedCategoryId === $child->id ? 'pos-cat-btn--active' : '' }}">
                            ↳ {{ $child->name }}
                        </button>
                    @endforeach
                @endforeach
            </aside>

            {{-- ── المنتجات ── --}}
            <section class="pos-products">
                <p class="pos-side-title">المنتجات — اضغط للإضافة</p>
                <div class="pos-product-grid">
                    @forelse($this->products as $product)
                        <button type="button"
                            wire:click="addProduct({{ $product->id }})"
                            class="pos-product-card">
                            @if($product->main_image)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($product->main_image) }}" alt="" class="pos-product-img">
                            @else
                                <div class="pos-product-img pos-product-img--empty">📦</div>
                            @endif
                            <span class="pos-product-name">{{ $product->display_name }}</span>
                            <span class="pos-product-points">{{ number_format($product->points_per_unit, 2) }} نقطة/وحدة</span>
                        </button>
                    @empty
                        <p class="pos-empty">لا توجد منتجات في هذا التصنيف</p>
                    @endforelse
                </div>
            </section>

            {{-- ── الفاتورة / السلة ── --}}
            <aside class="pos-cart">
                <p class="pos-side-title">🧾 الفاتورة</p>

                @if(empty($cart))
                    <p class="pos-empty">السلة فارغة — اختر منتجات</p>
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
                                        <input type="number" min="1"
                                            style="width:56px;text-align:center;font-weight:800;border:1px solid #cbd5e1;border-radius:6px"
                                            value="{{ $line['quantity'] }}"
                                            wire:change="setQuantity('{{ $key }}', $event.target.value)">
                                        <button type="button" wire:click="incrementQty('{{ $key }}')">+</button>
                                    </div>
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
                        <span>إجمالي النقاط (للتوزيع)</span>
                        <strong>{{ number_format($this->totalPoints) }} نقطة</strong>
                    </div>
                </div>

                <button type="button"
                    wire:click="issueInvoice"
                    wire:loading.attr="disabled"
                    class="pos-issue-btn"
                    @disabled(empty($cart) || ! $wholesaleDistributorId)>
                    <span wire:loading.remove wire:target="issueInvoice">✓ إصدار الفاتورة — {{ $this->nextInvoiceNumber }}</span>
                    <span wire:loading wire:target="issueInvoice">جاري الإصدار...</span>
                </button>

                <p class="pos-hint">بعد الإصدار تُربط الفاتورة بنظام توزيع النقاط (مصنع → جملة → قطاعي → سباك)</p>
            </aside>
        </div>
    </div>
</x-filament-panels::page>
