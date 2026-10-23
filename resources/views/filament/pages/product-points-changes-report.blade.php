@php
    $stats = $this->getSummaryStats();
    $dossiers = $this->productDossiers;
    $selected = $this->selectedProduct;
    $timeline = $this->selectedTimeline;
@endphp

<x-filament-panels::page>
    <div class="mg-points-page mg-rate-ledger" dir="rtl">
        <x-mg-ops-rail
            kicker="نظام النقاط · تقرير مستقل"
            title="تقرير تغيرات أسعار النقاط"
            lead="مكان واحد لكل منتج: <em>السعر الحالي</em>، آخر تغيير، الهامش، والتاريخ. اضغط أي منتج لفتح خط الزمن الكامل للتغييرات."
            :steps="[
                'كل بطاقة = منتج واحد: الحالي بكام + آخر تغيير + تاريخه.',
                'الفلاتر: منتجات لها تغييرات · آخر 30 يوم · الكل.',
                'التعديل يتم من صفحة «تعديل أسعار النقاط» أو من بطاقة المنتج.',
            ]"
            :actions="[
                ['label' => 'تعديل سعر نقاط', 'url' => $stats['edit_url'], 'tone' => 'brass'],
            ]"
        />

        <div class="mg-rate-ledger__stats">
            <div class="mg-rate-ledger__stat">
                <span class="mg-rate-ledger__stat-num">{{ number_format($stats['total_changes']) }}</span>
                <span class="mg-rate-ledger__stat-lbl">إجمالي التغييرات</span>
            </div>
            <div class="mg-rate-ledger__stat">
                <span class="mg-rate-ledger__stat-num">{{ number_format($stats['products_touched']) }}</span>
                <span class="mg-rate-ledger__stat-lbl">منتجات تغيّر سعرها</span>
            </div>
            <div class="mg-rate-ledger__stat mg-rate-ledger__stat--up">
                <span class="mg-rate-ledger__stat-num">{{ number_format($stats['up_count']) }}</span>
                <span class="mg-rate-ledger__stat-lbl">زيادات</span>
            </div>
            <div class="mg-rate-ledger__stat mg-rate-ledger__stat--down">
                <span class="mg-rate-ledger__stat-num">{{ number_format($stats['down_count']) }}</span>
                <span class="mg-rate-ledger__stat-lbl">تخفيضات</span>
            </div>
            <div class="mg-rate-ledger__stat mg-rate-ledger__stat--muted">
                <span class="mg-rate-ledger__stat-num" style="font-size:1rem">{{ $stats['last_change_at'] ?? '—' }}</span>
                <span class="mg-rate-ledger__stat-lbl">آخر تغيير</span>
            </div>
        </div>

        <div class="mg-rate-ledger__toolbar">
            <div class="mg-rate-ledger__filters" role="tablist">
                <button type="button" role="tab" class="mg-rate-ledger__chip {{ $filter === 'changed' ? 'is-on' : '' }}" wire:click="$set('filter', 'changed')">لها تغييرات</button>
                <button type="button" role="tab" class="mg-rate-ledger__chip {{ $filter === 'recent' ? 'is-on' : '' }}" wire:click="$set('filter', 'recent')">آخر 30 يوم</button>
                <button type="button" role="tab" class="mg-rate-ledger__chip {{ $filter === 'all' ? 'is-on' : '' }}" wire:click="$set('filter', 'all')">كل المنتجات</button>
            </div>
            <div class="mg-rate-ledger__search">
                <input
                    type="search"
                    wire:model.live.debounce.350ms="search"
                    placeholder="بحث باسم المنتج…"
                    class="mg-rate-ledger__search-input"
                />
            </div>
        </div>

        <div class="mg-rate-ledger__layout {{ $selected ? 'has-drawer' : '' }}">
            <div class="mg-rate-ledger__grid">
                @forelse ($dossiers as $i => $product)
                    @php
                        $name = localized_name($product, 'name', 'منتج #'.$product->id);
                        $current = (float) ($product->points_per_unit ?? 0);
                        $last = $product->latestPointsRateChange;
                        $dir = $last?->direction() ?? 'flat';
                        $img = $product->display_image_url;
                        $isSelected = $selectedProductId === (int) $product->id;
                    @endphp
                    <article
                        class="mg-rate-dossier {{ $isSelected ? 'is-selected' : '' }} mg-rate-dossier--{{ $dir }}"
                        style="--i: {{ $i % 12 }}"
                        wire:key="dossier-{{ $product->id }}"
                    >
                        <button type="button" class="mg-rate-dossier__hit" wire:click="selectProduct({{ $product->id }})" aria-expanded="{{ $isSelected ? 'true' : 'false' }}">
                            <div class="mg-rate-dossier__media">
                                @if ($img)
                                    <img src="{{ $img }}" alt="" loading="lazy">
                                @else
                                    <span class="mg-rate-dossier__ph">{{ mb_substr($name, 0, 1) }}</span>
                                @endif
                            </div>
                            <div class="mg-rate-dossier__body">
                                <h3 class="mg-rate-dossier__name">{{ $name }}</h3>
                                <div class="mg-rate-dossier__current">
                                    <span>الحالي</span>
                                    <strong>{{ number_format($current, 2) }}</strong>
                                    <small>نقطة / وحدة</small>
                                </div>

                                @if ($last)
                                    <div class="mg-rate-dossier__change">
                                        <div class="mg-rate-dossier__arrow-row">
                                            <span class="mg-rate-dossier__old">{{ number_format((float) $last->old_points_per_unit, 2) }}</span>
                                            <span class="mg-rate-dossier__to" aria-hidden="true">→</span>
                                            <span class="mg-rate-dossier__new">{{ number_format((float) $last->new_points_per_unit, 2) }}</span>
                                            @php
                                                $pct = $last->change_percent;
                                                $sign = (float) $last->delta_points_per_unit >= 0 ? '+' : '';
                                            @endphp
                                            <span class="mg-rate-dossier__pct {{ $dir }}">
                                                {{ $pct !== null ? $sign.number_format((float) $pct, 1).'%' : $sign.number_format((float) $last->delta_points_per_unit, 2) }}
                                            </span>
                                        </div>
                                        <time class="mg-rate-dossier__when" datetime="{{ $last->created_at?->toIso8601String() }}">
                                            {{ $last->created_at?->format('Y-m-d H:i') }}
                                            <em>· {{ $last->created_at?->diffForHumans() }}</em>
                                        </time>
                                    </div>
                                @else
                                    <p class="mg-rate-dossier__none">لا يوجد تغيير مسجّل بعد</p>
                                @endif

                                <footer class="mg-rate-dossier__meta">
                                    <span>{{ (int) $product->points_rate_changes_count }} تغيير</span>
                                    @if ($last?->author)
                                        <span>{{ $last->author->name }}</span>
                                    @endif
                                </footer>
                            </div>
                        </button>
                    </article>
                @empty
                    <div class="mg-rate-ledger__empty">
                        <span>◈</span>
                        <p>لا توجد منتجات مطابقة للفلتر أو البحث.</p>
                        <a href="{{ $stats['edit_url'] }}" class="mg-rate-board__btn mg-rate-board__btn--commit">سجّل أول تغيير سعر</a>
                    </div>
                @endforelse
            </div>

            @if ($selected)
                @php
                    $selName = localized_name($selected, 'name', 'منتج #'.$selected->id);
                    $selCurrent = (float) ($selected->points_per_unit ?? 0);
                    $selImg = $selected->display_image_url;
                @endphp
                <aside class="mg-rate-ledger__drawer" wire:key="drawer-{{ $selected->id }}">
                    <header class="mg-rate-ledger__drawer-head">
                        <div class="mg-rate-ledger__drawer-id">
                            @if ($selImg)
                                <img src="{{ $selImg }}" alt="">
                            @else
                                <span class="mg-rate-dossier__ph">{{ mb_substr($selName, 0, 1) }}</span>
                            @endif
                            <div>
                                <p class="mg-rate-ledger__drawer-kicker">ملف المنتج · تغيرات السعر</p>
                                <h3>{{ $selName }}</h3>
                            </div>
                        </div>
                        <button type="button" class="mg-rate-ledger__close" wire:click="clearSelection" aria-label="إغلاق">×</button>
                    </header>

                    <div class="mg-rate-ledger__drawer-current">
                        <span>السعر الحالي للكتالوج</span>
                        <strong>{{ number_format($selCurrent, 2) }} <small>نقطة/وحدة</small></strong>
                    </div>

                    <div class="mg-rate-ledger__drawer-actions">
                        <a href="{{ $this->productEditUrl($selected) }}" class="mg-rate-ledger__link">تعديل المنتج</a>
                        <a href="{{ $this->ratesEditUrl($selected->id) }}" class="mg-rate-ledger__link mg-rate-ledger__link--brass">تغيير السعر من اللوحة</a>
                    </div>

                    <h4 class="mg-rate-ledger__timeline-title">خط الزمن ({{ $timeline->count() }})</h4>
                    <ol class="mg-rate-ledger__timeline">
                        @forelse ($timeline as $idx => $change)
                            @php $d = $change->direction(); @endphp
                            <li class="mg-rate-ledger__event mg-rate-ledger__event--{{ $d }}" style="--i: {{ $idx }}">
                                <div class="mg-rate-ledger__event-dot" aria-hidden="true"></div>
                                <div class="mg-rate-ledger__event-card">
                                    <time>{{ $change->created_at?->format('Y-m-d H:i') }} · {{ $change->created_at?->diffForHumans() }}</time>
                                    <div class="mg-rate-ledger__event-rates">
                                        <span>{{ number_format((float) $change->old_points_per_unit, 2) }}</span>
                                        <span class="mg-rate-ledger__event-arrow">→</span>
                                        <strong>{{ number_format((float) $change->new_points_per_unit, 2) }}</strong>
                                        <span class="mg-rate-ledger__event-pct">
                                            @if ($change->change_percent !== null)
                                                {{ ((float) $change->delta_points_per_unit >= 0 ? '+' : '').number_format((float) $change->change_percent, 1) }}%
                                            @else
                                                {{ sprintf('%+.2f', (float) $change->delta_points_per_unit) }}
                                            @endif
                                        </span>
                                    </div>
                                    <dl class="mg-rate-ledger__event-stats">
                                        <div>
                                            <dt>جملة متبقٍ</dt>
                                            <dd>{{ number_format((int) $change->wholesale_remaining_qty) }} <small>({{ $change->wholesale_holders }})</small></dd>
                                        </div>
                                        <div>
                                            <dt>قطاعي متبقٍ</dt>
                                            <dd>{{ number_format((int) $change->retail_remaining_qty) }} <small>({{ $change->retail_holders }})</small></dd>
                                        </div>
                                        <div>
                                            <dt>تقدير فرق النقاط</dt>
                                            <dd>{{ sprintf('%+d', (int) $change->estimated_points_delta) }}</dd>
                                        </div>
                                    </dl>
                                    <p class="mg-rate-ledger__event-meta">
                                        {{ $change->author?->name ?? 'نظام' }}
                                        @if ($change->note)
                                            · {{ $change->note }}
                                        @endif
                                    </p>
                                </div>
                            </li>
                        @empty
                            <li class="mg-rate-ledger__timeline-empty">لا تغييرات مسجّلة لهذا المنتج.</li>
                        @endforelse
                    </ol>
                </aside>
            @endif
        </div>

        <div class="mg-rate-ledger__pager">
            {{ $dossiers->links() }}
        </div>
    </div>
</x-filament-panels::page>
