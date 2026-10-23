@php
    $stats = $this->getSummaryStats();
    $rows = $this->filteredStock;
    $history = $this->history;
    $selected = $this->selectedProduct;
    $isWholesale = $this->reportRole() === 'wholesale';
    $accent = $isWholesale ? 'blue' : 'teal';
@endphp

<x-filament-panels::page>
    <div class="mg-stock-ledger mg-stock-ledger--{{ $accent }}" dir="rtl">
        <header class="mg-stock-ledger__hero">
            <div class="mg-stock-ledger__hero-copy">
                <span class="mg-stock-ledger__eyebrow">{{ $stats['role_label'] }} · دفتر الكميات</span>
                <h2 class="mg-stock-ledger__title">تقرير المخزون والتوزيع</h2>
                <p class="mg-stock-ledger__lead">
                    كل منتج عندك بالكمية المتاحة، مع سجل التوزيعات وحالة الاعتماد
                    (بانتظار الاعتماد / معتمد / نقاط ممنوحة).
                </p>
            </div>
            <div class="mg-stock-ledger__hero-mark" aria-hidden="true">
                <span class="mg-stock-ledger__mark-num">{{ number_format($stats['units']) }}</span>
                <span class="mg-stock-ledger__mark-lbl">وحدة متاحة</span>
            </div>
        </header>

        <div class="mg-stock-ledger__stats">
            <div class="mg-stock-ledger__stat">
                <span class="mg-stock-ledger__stat-num">{{ number_format($stats['skus']) }}</span>
                <span class="mg-stock-ledger__stat-lbl">أصناف بالمخزون</span>
            </div>
            <div class="mg-stock-ledger__stat">
                <span class="mg-stock-ledger__stat-num">{{ number_format($stats['units']) }}</span>
                <span class="mg-stock-ledger__stat-lbl">إجمالي الوحدات</span>
            </div>
            <div class="mg-stock-ledger__stat mg-stock-ledger__stat--amber">
                <span class="mg-stock-ledger__stat-num">{{ number_format($stats['points']) }}</span>
                <span class="mg-stock-ledger__stat-lbl">نقاط المخزون</span>
            </div>
            <div class="mg-stock-ledger__stat mg-stock-ledger__stat--warn">
                <span class="mg-stock-ledger__stat-num">{{ number_format($stats['pending_drafts']) }}</span>
                <span class="mg-stock-ledger__stat-lbl">بانتظار الاعتماد</span>
            </div>
            <div class="mg-stock-ledger__stat mg-stock-ledger__stat--ok">
                <span class="mg-stock-ledger__stat-num">{{ number_format($stats['confirmed_out']) }}</span>
                <span class="mg-stock-ledger__stat-lbl">توزيعات صادرة معتمدة</span>
            </div>
        </div>

        <div class="mg-stock-ledger__tabs" role="tablist">
            <button type="button"
                class="mg-stock-ledger__tab {{ $panelTab === 'stock' ? 'is-on' : '' }}"
                wire:click="setPanelTab('stock')">
                المنتجات والكميات
            </button>
            <button type="button"
                class="mg-stock-ledger__tab {{ $panelTab === 'history' ? 'is-on' : '' }}"
                wire:click="setPanelTab('history')">
                تاريخ التوزيع والاعتماد
                @if ($selected)
                    <span class="mg-stock-ledger__tab-pill">مفلتر</span>
                @endif
            </button>
        </div>

        <div class="mg-stock-ledger__toolbar">
            <div class="mg-stock-ledger__filters">
                @if ($panelTab === 'stock')
                    <button type="button" class="mg-stock-ledger__chip {{ $stockFilter === 'all' ? 'is-on' : '' }}" wire:click="$set('stockFilter', 'all')">الكل</button>
                    <button type="button" class="mg-stock-ledger__chip {{ $stockFilter === 'in_stock' ? 'is-on' : '' }}" wire:click="$set('stockFilter', 'in_stock')">متوفر</button>
                    <button type="button" class="mg-stock-ledger__chip {{ $stockFilter === 'empty' ? 'is-on' : '' }}" wire:click="$set('stockFilter', 'empty')">صفر</button>
                @else
                    <button type="button" class="mg-stock-ledger__chip {{ $historyStatus === 'all' ? 'is-on' : '' }}" wire:click="$set('historyStatus', 'all')">كل الحالات</button>
                    <button type="button" class="mg-stock-ledger__chip {{ $historyStatus === 'draft' ? 'is-on' : '' }}" wire:click="$set('historyStatus', 'draft')">بانتظار الاعتماد</button>
                    <button type="button" class="mg-stock-ledger__chip {{ $historyStatus === 'confirmed' ? 'is-on' : '' }}" wire:click="$set('historyStatus', 'confirmed')">معتمد</button>
                    <button type="button" class="mg-stock-ledger__chip {{ $historyStatus === 'points_awarded' ? 'is-on' : '' }}" wire:click="$set('historyStatus', 'points_awarded')">نقاط ممنوحة</button>
                @endif
            </div>
            <div class="mg-stock-ledger__search">
                <input type="search"
                    wire:model.live.debounce.350ms="search"
                    placeholder="{{ $panelTab === 'stock' ? 'بحث بالمنتج / التصنيف / التاج…' : 'بحث برقم فاتورة أو طرف…' }}"
                    class="mg-stock-ledger__search-input" />
            </div>
        </div>

        @if ($selected)
            <div class="mg-stock-ledger__focus">
                <div class="mg-stock-ledger__focus-copy">
                    <span class="mg-stock-ledger__focus-kicker">سجل المنتج</span>
                    <strong>{{ localized_name($selected, 'name', 'منتج #'.$selected->id) }}</strong>
                </div>
                <button type="button" class="mg-stock-ledger__focus-clear" wire:click="clearProduct">إلغاء الفلتر</button>
            </div>
        @endif

        @if ($panelTab === 'stock')
            <div class="mg-stock-ledger__table-wrap" wire:key="stock-table">
                <table class="mg-stock-ledger__table">
                    <thead>
                        <tr>
                            <th>المنتج</th>
                            <th>التصنيف / التاج</th>
                            <th>الكمية</th>
                            <th>نقطة/وحدة</th>
                            <th>نقاط متاحة</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $i => $row)
                            @php
                                $pid = (int) ($row['product_id'] ?? 0);
                                $qty = (int) ($row['available_qty'] ?? 0);
                                $ppu = (float) ($row['points_per_unit'] ?? 0);
                                $pts = (int) ($row['available_points'] ?? 0);
                                $isSel = $selectedProductId === $pid;
                            @endphp
                            <tr class="mg-stock-ledger__row {{ $isSel ? 'is-selected' : '' }} {{ $qty <= 0 ? 'is-empty' : '' }}" style="--i: {{ $i % 16 }}" wire:key="stock-{{ $pid }}">
                                <td>
                                    <div class="mg-stock-ledger__product">
                                        <span class="mg-stock-ledger__dot"></span>
                                        <div>
                                            <div class="mg-stock-ledger__pname">{{ $row['name'] ?? ('#'.$pid) }}</div>
                                            <div class="mg-stock-ledger__pid">#{{ $pid }}</div>
                                            @if (! empty($row['points_rate_hint']))
                                                <div class="mg-points-hint mg-points-hint--{{ $row['points_rate_hint']['tone'] ?? 'warning' }}" style="margin-top:.45rem">
                                                    <div class="mg-points-hint__rail" aria-hidden="true"></div>
                                                    <div class="mg-points-hint__body">
                                                        <span class="mg-points-hint__kicker">تنبيه سعر النقاط</span>
                                                        <strong class="mg-points-hint__title">{{ $row['points_rate_hint']['title_ar'] }}</strong>
                                                        <p class="mg-points-hint__msg">{{ $row['points_rate_hint']['message_ar'] }}</p>
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="mg-stock-ledger__meta">{{ $row['category_name'] ?? '—' }}</div>
                                    @if (! empty($row['tag']))
                                        <span class="mg-stock-ledger__tag">{{ $row['tag'] }}</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="mg-stock-ledger__qty {{ $qty > 0 ? 'is-ok' : 'is-zero' }}">{{ number_format($qty) }}</span>
                                </td>
                                <td>{{ rtrim(rtrim(number_format($ppu, 4, '.', ''), '0'), '.') ?: '0' }}</td>
                                <td><span class="mg-stock-ledger__pts">{{ number_format($pts) }}</span></td>
                                <td>
                                    <button type="button" class="mg-stock-ledger__link" wire:click="selectProduct({{ $pid }})">
                                        سجل التوزيع
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="mg-stock-ledger__empty">لا يوجد مخزون مطابق للفلتر الحالي.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <div class="mg-stock-ledger__history" wire:key="history-list">
                @forelse ($history as $i => $dist)
                    @php
                        $meta = $this::statusMeta((string) $dist->status);
                        $dirOut = (int) $dist->from_user_id === (int) auth()->id();
                        $qty = $this->productQtyInDistribution($dist);
                        $pts = $this->productPointsInDistribution($dist);
                        $when = $dist->confirmed_at ?? $dist->created_at;
                    @endphp
                    <article class="mg-stock-ledger__card mg-stock-ledger__card--{{ $meta['tone'] }}" style="--i: {{ $i % 10 }}" wire:key="dist-{{ $dist->id }}">
                        <div class="mg-stock-ledger__card-top">
                            <div>
                                <span class="mg-stock-ledger__badge mg-stock-ledger__badge--{{ $meta['tone'] }}">{{ $meta['label'] }}</span>
                                <span class="mg-stock-ledger__flow">{{ $dirOut ? 'صادر منك' : 'وارد إليك' }} · طبقة {{ $dist->tier }}</span>
                            </div>
                            <time class="mg-stock-ledger__when">{{ optional($when)->timezone('Africa/Tripoli')->format('Y/m/d H:i') }}</time>
                        </div>
                        <div class="mg-stock-ledger__card-body">
                            <div class="mg-stock-ledger__parties">
                                <div>
                                    <span class="mg-stock-ledger__k">من</span>
                                    <strong>{{ $dist->fromUser?->brand_name ?: $dist->fromUser?->name ?: '—' }}</strong>
                                </div>
                                <span class="mg-stock-ledger__arrow" aria-hidden="true">→</span>
                                <div>
                                    <span class="mg-stock-ledger__k">إلى</span>
                                    <strong>{{ $dist->toUser?->brand_name ?: $dist->toUser?->name ?: '—' }}</strong>
                                </div>
                            </div>
                            <div class="mg-stock-ledger__card-metrics">
                                <span>{{ number_format($qty) }} وحدة</span>
                                <span>{{ number_format($pts) }} نقطة</span>
                                @if ($dist->invoice?->number)
                                    <span class="mg-stock-ledger__inv">{{ $dist->invoice->number }}</span>
                                @endif
                            </div>
                        </div>
                        @if ($dist->items->isNotEmpty())
                            <ul class="mg-stock-ledger__lines">
                                @foreach ($dist->items->take(4) as $di)
                                    <li>
                                        <span>{{ localized_name($di->invoiceItem?->product, 'name', 'منتج') }}</span>
                                        <span>×{{ (int) $di->quantity }}</span>
                                    </li>
                                @endforeach
                                @if ($dist->items->count() > 4)
                                    <li class="mg-stock-ledger__more">+{{ $dist->items->count() - 4 }} أصناف أخرى</li>
                                @endif
                            </ul>
                        @endif
                    </article>
                @empty
                    <div class="mg-stock-ledger__empty-block">لا توجد توزيعات مطابقة.</div>
                @endforelse

                <div class="mg-stock-ledger__pager">
                    {{ $history->links() }}
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
