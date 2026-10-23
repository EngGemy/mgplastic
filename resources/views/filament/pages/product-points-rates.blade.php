<x-filament-panels::page>
    <div class="mg-points-page mg-rate-hub" dir="rtl">
        <header class="mg-rate-hub__hero">
            <div class="mg-rate-hub__hero-copy">
                <span class="mg-rate-hub__eyebrow">أسعار نقاط المنتجات · تغيير السعر</span>
                <h2 class="mg-rate-hub__title">عيّن السعر الجديد بعد قراءة الأثر</h2>
                <p class="mg-rate-hub__lead">
                    المبيعات الجديدة تُحسب بسعر الكتالوج الحالي حتى لو المخزون من فواتير قديمة.
                    قبل الحفظ ترى المخزون عند الجملة والقطاعي وهامش التغيير وتقدير فرق النقاط.
                </p>
            </div>
            <div class="mg-rate-hub__hero-actions">
                <a href="{{ \App\Filament\Pages\ProductPointsChangesReport::getUrl() }}" class="mg-rate-hub__btn mg-rate-hub__btn--ghost">التقرير والسجل</a>
            </div>
        </header>

        @if ($this->canApply())
            <div class="mg-rate-hub__composer">
                <div class="mg-rate-hub__form-pane">
                    <header class="mg-rate-hub__pane-head">
                        <span class="mg-rate-hub__pane-kicker">تعديل</span>
                        <h3>المنتج والسعر الجديد</h3>
                    </header>
                    <form wire:submit="applyRateChange" class="mg-points-page__form space-y-4">
                        {{ $this->form }}
                        <div class="mg-rate-hub__form-actions">
                            <button type="button" class="mg-rate-hub__btn mg-rate-hub__btn--ghost" wire:click="refreshImpactPreview" wire:loading.attr="disabled">
                                تحديث التقدير
                            </button>
                            <button type="submit" class="mg-rate-hub__btn mg-rate-hub__btn--primary" wire:loading.attr="disabled" wire:target="applyRateChange">
                                <span wire:loading.remove wire:target="applyRateChange">تأكيد التغيير وتنبيه الإدارة</span>
                                <span wire:loading wire:target="applyRateChange">جاري الحفظ…</span>
                            </button>
                        </div>
                    </form>
                </div>

                <aside class="mg-rate-hub__impact" aria-live="polite">
                    @php $impact = $this->impactPreview; @endphp
                    @if ($impact)
                        @php
                            $delta = (float) ($impact['delta_points_per_unit'] ?? 0);
                            $dir = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');
                            $pct = $impact['change_percent'];
                            $wTraders = $impact['wholesale_by_trader'] ?? [];
                            $rTraders = $impact['retail_by_trader'] ?? [];
                        @endphp
                        <div class="mg-rate-impact mg-rate-impact--{{ $dir }}">
                            <p class="mg-rate-impact__kicker">تقدير الأثر قبل الحفظ</p>
                            <h4 class="mg-rate-impact__product">{{ $impact['product_name'] ?? '—' }}</h4>

                            <div class="mg-rate-impact__ticker">
                                <div class="mg-rate-impact__tick">
                                    <span>قبل</span>
                                    <strong>{{ number_format((float) $impact['old_points_per_unit'], 2) }}</strong>
                                </div>
                                <div class="mg-rate-impact__arrow">←</div>
                                <div class="mg-rate-impact__tick mg-rate-impact__tick--new">
                                    <span>بعد</span>
                                    <strong>{{ number_format((float) $impact['new_points_per_unit'], 2) }}</strong>
                                </div>
                            </div>

                            <div class="mg-rate-impact__margin {{ $dir }}">
                                <span>معدل التغيير</span>
                                <strong>
                                    {{ $delta >= 0 ? '+' : '' }}{{ number_format($delta, 2) }}
                                    @if ($pct !== null)
                                        <small>({{ $pct >= 0 ? '+' : '' }}{{ number_format((float) $pct, 1) }}%)</small>
                                    @endif
                                </strong>
                            </div>

                            <dl class="mg-rate-impact__stats">
                                <div>
                                    <dt>مخزون جملة</dt>
                                    <dd>{{ number_format((int) $impact['wholesale_remaining_qty']) }} <small>{{ (int) $impact['wholesale_holders'] }} تاجر</small></dd>
                                </div>
                                <div>
                                    <dt>مخزون قطاعي</dt>
                                    <dd>{{ number_format((int) $impact['retail_remaining_qty']) }} <small>{{ (int) $impact['retail_holders'] }} تاجر</small></dd>
                                </div>
                                <div class="mg-rate-impact__stats-span">
                                    <dt>فرق النقاط لو صُرف المتبقي بالسعر الجديد</dt>
                                    <dd class="{{ $dir }}">{{ sprintf('%+d', (int) $impact['estimated_points_delta']) }} نقطة</dd>
                                </div>
                            </dl>

                            @if (! empty($wTraders) || ! empty($rTraders))
                                <details class="mg-rate-impact__holders" open>
                                    <summary>تفصيل المخزون عند كل تاجر</summary>
                                    @if (! empty($wTraders))
                                        <p class="mg-rate-drawer__sub">جملة</p>
                                        <ul class="mg-rate-trader-list mg-rate-trader-list--on-dark">
                                            @foreach ($wTraders as $row)
                                                <li><span>{{ $row['name'] }}</span><strong>{{ number_format((int) $row['qty']) }}</strong></li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    @if (! empty($rTraders))
                                        <p class="mg-rate-drawer__sub">قطاعي</p>
                                        <ul class="mg-rate-trader-list mg-rate-trader-list--on-dark">
                                            @foreach ($rTraders as $row)
                                                <li><span>{{ $row['name'] }}</span><strong>{{ number_format((int) $row['qty']) }}</strong></li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </details>
                            @endif

                            <p class="mg-rate-impact__note">
                                الفواتير القديمة لا تُعاد تسعيرها · المبيعات الجديدة فقط تأخذ السعر الجديد · المرتجع يعكس نقاط البيع الأصلية.
                            </p>
                        </div>
                    @else
                        <div class="mg-rate-impact-empty">
                            <p>اختر منتجاً وأدخل السعر الجديد لترى التقدير ومخزون كل تاجر هنا.</p>
                        </div>
                    @endif
                </aside>
            </div>
        @else
            <div class="mg-rate-hub__readonly">لديك صلاحية العرض فقط. التعديل لـ admin / super_admin.</div>
        @endif

        <section class="mg-rate-hub__form-pane" style="margin-top:8px">
            <header class="mg-rate-hub__pane-head">
                <span class="mg-rate-hub__pane-kicker">سجل سريع</span>
                <h3>آخر التغييرات</h3>
            </header>
            {{ $this->table }}
        </section>
    </div>
</x-filament-panels::page>
