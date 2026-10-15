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
            <div class="pos-header-field pos-sheet-field">
                <label class="pos-label">شيت المنتجات والكميات</label>
                <div class="pos-sheet-actions">
                    <button type="button" wire:click="downloadInvoiceTemplate" class="pos-sheet-btn">تنزيل النموذج</button>
                    <label class="pos-sheet-btn pos-sheet-btn--upload">
                        رفع الشيت
                        <input type="file" wire:model="invoiceSheet" accept=".xlsx,.xls,.csv">
                    </label>
                </div>
                <p class="pos-invoice-no-hint" wire:loading.remove wire:target="invoiceSheet">الكمية = عدد الصناديق لو المنتج له صندوق، وإلا عدد القطع. بعد الرفع راجع الفاتورة قبل الحفظ.</p>
                <p class="pos-invoice-no-hint" wire:loading wire:target="invoiceSheet">جاري قراءة الشيت...</p>
                @error('invoiceSheet')
                    <p class="pos-sheet-error">{{ $message }}</p>
                @enderror
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
                                        @if(!empty($line['pieces_per_box']))
                                            <input type="number" min="1"
                                                value="{{ $line['boxes'] }}"
                                                wire:change="setBoxes('{{ $key }}', $event.target.value)">
                                        @else
                                            <input type="number" min="1"
                                                value="{{ $line['quantity'] }}"
                                                wire:change="setQuantity('{{ $key }}', $event.target.value)">
                                        @endif
                                        <button type="button" wire:click="incrementQty('{{ $key }}')">+</button>
                                    </div>
                                </div>
                                <div class="pos-cart-line-bot">
                                    @if(!empty($line['pieces_per_box']))
                                        <span>{{ $line['boxes'] }} صندوق × {{ $line['pieces_per_box'] }} = {{ number_format($line['quantity']) }} قطعة</span>
                                    @else
                                        <span>{{ number_format($line['quantity']) }} وحدة</span>
                                    @endif
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
                    wire:click="openReview"
                    class="pos-issue-btn"
                    @disabled(empty($cart))>
                    مراجعة الفاتورة قبل الحفظ
                </button>

                <p class="pos-hint">الحفظ يتم بعد مراجعة الكميات والنقاط وموزع الجملة</p>
            </aside>
        </div>
    </div>

    @if($reviewing)
        <div class="pos-review-backdrop" dir="rtl">
            <div class="pos-review" role="dialog" aria-modal="true" aria-labelledby="pos-review-title">
                <header class="pos-review-head">
                    <div>
                        <p class="pos-review-kicker">مراجعة قبل الحفظ</p>
                        <h2 id="pos-review-title">فاتورة {{ $this->nextInvoiceNumber }}</h2>
                    </div>
                    <button type="button" wire:click="closeReview" class="pos-review-close">رجوع</button>
                </header>

                <div class="pos-review-meta">
                    <div>
                        <span>موزع الجملة</span>
                        <strong>{{ $this->selectedWholesalerName ?: 'لم يُختر' }}</strong>
                    </div>
                    <div>
                        <span>الأصناف</span>
                        <strong>{{ count($cart) }}</strong>
                    </div>
                    <div>
                        <span>الوحدات</span>
                        <strong>{{ number_format($this->totalUnits) }}</strong>
                    </div>
                    <div>
                        <span>إجمالي النقاط</span>
                        <strong>{{ number_format($this->totalPoints) }}</strong>
                    </div>
                </div>

                @if($this->reviewIssues !== [])
                    <ul class="pos-review-issues">
                        @foreach($this->reviewIssues as $issue)
                            <li>{{ $issue }}</li>
                        @endforeach
                    </ul>
                @endif

                <div class="pos-review-table-wrap">
                    <table class="pos-review-table">
                        <thead>
                            <tr>
                                <th>المنتج</th>
                                <th>العدد</th>
                                <th>العبوة</th>
                                <th>الكمية</th>
                                <th>نقطة / وحدة</th>
                                <th>النقاط</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($cart as $key => $line)
                                <tr wire:key="review-{{ $key }}" @class(['pos-review-row--warn' => (float) $line['points_per_unit'] <= 0 || (int) $line['quantity'] < 1])>
                                    <td>
                                        <div class="pos-review-product">
                                            <span>{{ $line['name'] }}</span>
                                            <button type="button" wire:click="removeLine('{{ $key }}')" class="pos-remove">×</button>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="pos-qty">
                                            <button type="button" wire:click="decrementQty('{{ $key }}')">−</button>
                                            @if(!empty($line['pieces_per_box']))
                                                <input type="number" min="1"
                                                    value="{{ $line['boxes'] }}"
                                                    wire:change="setBoxes('{{ $key }}', $event.target.value)">
                                            @else
                                                <input type="number" min="1"
                                                    value="{{ $line['quantity'] }}"
                                                    wire:change="setQuantity('{{ $key }}', $event.target.value)">
                                            @endif
                                            <button type="button" wire:click="incrementQty('{{ $key }}')">+</button>
                                        </div>
                                    </td>
                                    <td>
                                        @if(!empty($line['pieces_per_box']))
                                            {{ $line['boxes'] }} صندوق × {{ $line['pieces_per_box'] }}
                                        @else
                                            بالوحدة
                                        @endif
                                    </td>
                                    <td>{{ number_format($line['quantity']) }}</td>
                                    <td>{{ number_format((float) $line['points_per_unit'], 2) }}</td>
                                    <td>{{ number_format((int) floor($line['quantity'] * $line['points_per_unit'])) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <footer class="pos-review-actions">
                    <button type="button" wire:click="closeReview" class="pos-review-back">رجوع للتعديل</button>
                    <button type="button"
                        wire:click="confirmIssue"
                        wire:loading.attr="disabled"
                        class="pos-issue-btn"
                        @disabled($this->reviewIssues !== [])>
                        <span wire:loading.remove wire:target="confirmIssue">تأكيد وحفظ الفاتورة</span>
                        <span wire:loading wire:target="confirmIssue">جاري الحفظ...</span>
                    </button>
                </footer>
            </div>
        </div>
    @endif
</x-filament-panels::page>
