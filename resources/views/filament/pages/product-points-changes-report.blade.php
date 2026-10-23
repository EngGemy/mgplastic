@php
    $stats = $this->getSummaryStats();
    $dossiers = $this->productDossiers;
    $selected = $this->selectedProduct;
    $timeline = $this->selectedTimeline;
    $network = $this->selectedNetworkStock;
@endphp

<x-filament-panels::page>
    <div class="mg-points-page mg-rate-hub" dir="rtl">
        <header class="mg-rate-hub__hero">
            <div class="mg-rate-hub__hero-copy">
                <span class="mg-rate-hub__eyebrow">أسعار نقاط المنتجات · مركز التحكم</span>
                <h2 class="mg-rate-hub__title">التقرير وسجل التغيرات</h2>
                <p class="mg-rate-hub__lead">
                    لكل منتج: السعر الحالي، معدل آخر تغيير، التاريخ، ومخزون الجملة والقطاعي عند كل تاجر.
                    التغييرات محفوظة هنا — ليست على بطاقة المنتج في الموبايل.
                </p>
            </div>
            <div class="mg-rate-hub__hero-actions">
                <a href="{{ $stats['edit_url'] }}" class="mg-rate-hub__btn mg-rate-hub__btn--primary">تغيير سعر منتج</a>
            </div>
        </header>

        <div class="mg-rate-hub__stats">
            <div class="mg-rate-hub__stat">
                <span class="mg-rate-hub__stat-num">{{ number_format($stats['total_changes']) }}</span>
                <span class="mg-rate-hub__stat-lbl">إجمالي التغييرات</span>
            </div>
            <div class="mg-rate-hub__stat">
                <span class="mg-rate-hub__stat-num">{{ number_format($stats['products_touched']) }}</span>
                <span class="mg-rate-hub__stat-lbl">منتجات تغيّر سعرها</span>
            </div>
            <div class="mg-rate-hub__stat mg-rate-hub__stat--up">
                <span class="mg-rate-hub__stat-num">{{ number_format($stats['up_count']) }}</span>
                <span class="mg-rate-hub__stat-lbl">زيادات</span>
            </div>
            <div class="mg-rate-hub__stat mg-rate-hub__stat--down">
                <span class="mg-rate-hub__stat-num">{{ number_format($stats['down_count']) }}</span>
                <span class="mg-rate-hub__stat-lbl">تخفيضات</span>
            </div>
            <div class="mg-rate-hub__stat mg-rate-hub__stat--muted">
                <span class="mg-rate-hub__stat-num mg-rate-hub__stat-num--sm">{{ $stats['last_change_at'] ?? '—' }}</span>
                <span class="mg-rate-hub__stat-lbl">آخر تغيير</span>
            </div>
        </div>

        <div class="mg-rate-hub__toolbar">
            <div class="mg-rate-hub__filters" role="tablist">
                <button type="button" class="mg-rate-hub__chip {{ $filter === 'changed' ? 'is-on' : '' }}" wire:click="$set('filter', 'changed')">لها تغييرات</button>
                <button type="button" class="mg-rate-hub__chip {{ $filter === 'recent' ? 'is-on' : '' }}" wire:click="$set('filter', 'recent')">آخر 30 يوم</button>
                <button type="button" class="mg-rate-hub__chip {{ $filter === 'all' ? 'is-on' : '' }}" wire:click="$set('filter', 'all')">كل المنتجات</button>
            </div>
            <div class="mg-rate-hub__search">
                <input type="search" wire:model.live.debounce.350ms="search" placeholder="بحث باسم المنتج…" class="mg-rate-hub__search-input" />
            </div>
        </div>

        <div class="mg-rate-hub__layout {{ $selected ? 'has-drawer' : '' }}">
            <div class="mg-rate-hub__grid">
                @forelse ($dossiers as $i => $product)
                    @php
                        $name = localized_name($product, 'name', 'منتج #'.$product->id);
                        $current = (float) ($product->points_per_unit ?? 0);
                        $last = $product->latestPointsRateChange;
                        $dir = $last?->direction() ?? 'flat';
                        $img = $product->display_image_url;
                        $isSelected = $selectedProductId === (int) $product->id;
                    @endphp
                    <article class="mg-rate-card {{ $isSelected ? 'is-selected' : '' }} mg-rate-card--{{ $dir }}" style="--i: {{ $i % 12 }}" wire:key="dossier-{{ $product->id }}">
                        <button type="button" class="mg-rate-card__hit" wire:click="selectProduct({{ $product->id }})">
                            <div class="mg-rate-card__media">
                                @if ($img)
                                    <img src="{{ $img }}" alt="" loading="lazy">
                                @else
                                    <span class="mg-rate-card__ph">{{ mb_substr($name, 0, 1) }}</span>
                                @endif
                            </div>
                            <div class="mg-rate-card__body">
                                <h3 class="mg-rate-card__name">{{ $name }}</h3>
                                <div class="mg-rate-card__current">
                                    <span>الحالي</span>
                                    <strong>{{ number_format($current, 2) }}</strong>
                                    <small>نقطة / وحدة</small>
                                </div>
                                @if ($last)
                                    @php
                                        $pct = $last->change_percent;
                                        $sign = (float) $last->delta_points_per_unit >= 0 ? '+' : '';
                                    @endphp
                                    <div class="mg-rate-card__change">
                                        <div class="mg-rate-card__arrow-row">
                                            <span class="mg-rate-card__old">{{ number_format((float) $last->old_points_per_unit, 2) }}</span>
                                            <span class="mg-rate-card__to">→</span>
                                            <span class="mg-rate-card__new">{{ number_format((float) $last->new_points_per_unit, 2) }}</span>
                                            <span class="mg-rate-card__pct {{ $dir }}">
                                                {{ $pct !== null ? $sign.number_format((float) $pct, 1).'%' : $sign.number_format((float) $last->delta_points_per_unit, 2) }}
                                            </span>
                                        </div>
                                        <time class="mg-rate-card__when" datetime="{{ $last->created_at?->toIso8601String() }}">
                                            {{ $last->created_at?->format('Y-m-d H:i') }}
                                            <em>· {{ $last->created_at?->diffForHumans() }}</em>
                                        </time>
                                        <div class="mg-rate-card__stock-mini">
                                            جملة {{ number_format((int) $last->wholesale_remaining_qty) }}
                                            · قطاعي {{ number_format((int) $last->retail_remaining_qty) }}
                                        </div>
                                    </div>
                                @else
                                    <p class="mg-rate-card__none">لا يوجد تغيير مسجّل بعد</p>
                                @endif
                                <footer class="mg-rate-card__meta">
                                    <span>{{ (int) $product->points_rate_changes_count }} تغيير</span>
                                    @if ($last?->author)
                                        <span>{{ $last->author->name }}</span>
                                    @endif
                                </footer>
                            </div>
                        </button>
                    </article>
                @empty
                    <div class="mg-rate-hub__empty">
                        <p>لا توجد منتجات مطابقة.</p>
                        <a href="{{ $stats['edit_url'] }}" class="mg-rate-hub__btn mg-rate-hub__btn--primary">سجّل أول تغيير</a>
                    </div>
                @endforelse
            </div>

            @if ($selected)
                @php
                    $selName = localized_name($selected, 'name', 'منتج #'.$selected->id);
                    $selCurrent = (float) ($selected->points_per_unit ?? 0);
                    $selImg = $selected->display_image_url;
                @endphp
                <aside class="mg-rate-drawer" wire:key="drawer-{{ $selected->id }}">
                    <header class="mg-rate-drawer__head">
                        <div class="mg-rate-drawer__id">
                            @if ($selImg)
                                <img src="{{ $selImg }}" alt="">
                            @else
                                <span class="mg-rate-card__ph">{{ mb_substr($selName, 0, 1) }}</span>
                            @endif
                            <div>
                                <p class="mg-rate-drawer__kicker">ملف المنتج</p>
                                <h3>{{ $selName }}</h3>
                            </div>
                        </div>
                        <button type="button" class="mg-rate-drawer__close" wire:click="clearSelection" aria-label="إغلاق">×</button>
                    </header>

                    <div class="mg-rate-drawer__current">
                        <span>السعر الحالي للكتالوج</span>
                        <strong>{{ number_format($selCurrent, 2) }} <small>نقطة/وحدة</small></strong>
                    </div>

                    <div class="mg-rate-drawer__actions">
                        <a href="{{ $this->productEditUrl($selected) }}" class="mg-rate-hub__btn mg-rate-hub__btn--ghost">تعديل المنتج</a>
                        <a href="{{ $this->ratesEditUrl() }}" class="mg-rate-hub__btn mg-rate-hub__btn--primary">تغيير السعر</a>
                    </div>

                    @if ($network)
                        <section class="mg-rate-drawer__section">
                            <h4>المخزون المتبقي الآن عند التجار</h4>
                            <div class="mg-rate-drawer__stock-sum">
                                <div>
                                    <span>جملة</span>
                                    <strong>{{ number_format((int) $network['wholesale_remaining_qty']) }}</strong>
                                    <small>{{ (int) $network['wholesale_holders'] }} تاجر</small>
                                </div>
                                <div>
                                    <span>قطاعي</span>
                                    <strong>{{ number_format((int) $network['retail_remaining_qty']) }}</strong>
                                    <small>{{ (int) $network['retail_holders'] }} تاجر</small>
                                </div>
                            </div>

                            @if (! empty($network['wholesale_by_trader']))
                                <p class="mg-rate-drawer__sub">موزّعو الجملة</p>
                                <ul class="mg-rate-trader-list">
                                    @foreach ($network['wholesale_by_trader'] as $row)
                                        <li>
                                            <span>{{ $row['name'] }}</span>
                                            <strong>{{ number_format((int) $row['qty']) }}</strong>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            @if (! empty($network['retail_by_trader']))
                                <p class="mg-rate-drawer__sub">تجار القطاعي</p>
                                <ul class="mg-rate-trader-list">
                                    @foreach ($network['retail_by_trader'] as $row)
                                        <li>
                                            <span>{{ $row['name'] }}</span>
                                            <strong>{{ number_format((int) $row['qty']) }}</strong>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            @if (empty($network['wholesale_by_trader']) && empty($network['retail_by_trader']))
                                <p class="mg-rate-drawer__empty-stock">لا يوجد مخزون متبقٍ لهذا المنتج عند التجار حالياً.</p>
                            @endif
                        </section>
                    @endif

                    <h4 class="mg-rate-drawer__timeline-title">سجل التغييرات ({{ $timeline->count() }})</h4>
                    <ol class="mg-rate-timeline">
                        @forelse ($timeline as $idx => $change)
                            @php
                                $d = $change->direction();
                                $snap = is_array($change->impact_snapshot) ? $change->impact_snapshot : [];
                                $wTraders = $snap['wholesale_by_trader'] ?? [];
                                $rTraders = $snap['retail_by_trader'] ?? [];
                            @endphp
                            <li class="mg-rate-timeline__event mg-rate-timeline__event--{{ $d }}" style="--i: {{ $idx }}">
                                <div class="mg-rate-timeline__dot"></div>
                                <div class="mg-rate-timeline__card">
                                    <time>{{ $change->created_at?->format('Y-m-d H:i') }} · {{ $change->created_at?->diffForHumans() }}</time>
                                    <div class="mg-rate-timeline__rates">
                                        <span>{{ number_format((float) $change->old_points_per_unit, 2) }}</span>
                                        <span class="mg-rate-timeline__arrow">→</span>
                                        <strong>{{ number_format((float) $change->new_points_per_unit, 2) }}</strong>
                                        <span class="mg-rate-timeline__pct">
                                            @if ($change->change_percent !== null)
                                                {{ ((float) $change->delta_points_per_unit >= 0 ? '+' : '').number_format((float) $change->change_percent, 1) }}%
                                            @else
                                                {{ sprintf('%+.2f', (float) $change->delta_points_per_unit) }}
                                            @endif
                                        </span>
                                    </div>
                                    <dl class="mg-rate-timeline__stats">
                                        <div>
                                            <dt>جملة وقت التغيير</dt>
                                            <dd>{{ number_format((int) $change->wholesale_remaining_qty) }} <small>({{ $change->wholesale_holders }})</small></dd>
                                        </div>
                                        <div>
                                            <dt>قطاعي وقت التغيير</dt>
                                            <dd>{{ number_format((int) $change->retail_remaining_qty) }} <small>({{ $change->retail_holders }})</small></dd>
                                        </div>
                                        <div>
                                            <dt>تقدير فرق النقاط</dt>
                                            <dd>{{ sprintf('%+d', (int) $change->estimated_points_delta) }}</dd>
                                        </div>
                                    </dl>

                                    @if (! empty($wTraders) || ! empty($rTraders))
                                        <details class="mg-rate-timeline__holders">
                                            <summary>مخزون كل تاجر وقت التغيير</summary>
                                            @if (! empty($wTraders))
                                                <p class="mg-rate-drawer__sub">جملة</p>
                                                <ul class="mg-rate-trader-list mg-rate-trader-list--compact">
                                                    @foreach ($wTraders as $row)
                                                        <li><span>{{ $row['name'] ?? ('#'.$row['user_id']) }}</span><strong>{{ number_format((int) ($row['qty'] ?? 0)) }}</strong></li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                            @if (! empty($rTraders))
                                                <p class="mg-rate-drawer__sub">قطاعي</p>
                                                <ul class="mg-rate-trader-list mg-rate-trader-list--compact">
                                                    @foreach ($rTraders as $row)
                                                        <li><span>{{ $row['name'] ?? ('#'.$row['user_id']) }}</span><strong>{{ number_format((int) ($row['qty'] ?? 0)) }}</strong></li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </details>
                                    @endif

                                    <p class="mg-rate-timeline__meta">
                                        {{ $change->author?->name ?? 'نظام' }}
                                        @if ($change->note) · {{ $change->note }} @endif
                                    </p>
                                </div>
                            </li>
                        @empty
                            <li class="mg-rate-timeline__empty">لا تغييرات مسجّلة.</li>
                        @endforelse
                    </ol>
                </aside>
            @endif
        </div>

        <div class="mg-rate-hub__pager">{{ $dossiers->links() }}</div>
    </div>
</x-filament-panels::page>
