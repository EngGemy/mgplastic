<x-filament-panels::page>
    <div class="mg-points-page mg-rate-board" dir="rtl">
        <x-mg-ops-rail
            kicker="نظام النقاط · سعر الكتالوج الموحّد"
            title="تغيير أسعار نقاط المنتجات"
            lead="المبيعات <em>الجديدة</em> تُحتسب دائماً بسعر الكتالوج الحالي — حتى لو صُرف المخزون من فواتير قديمة. الفواتير السابقة لا تُمس. قبل الحفظ ترى <em>تقدير الأثر</em> على مخزون الجملة والقطاعي وهامش الزيادة."
            :steps="[
                'اختر المنتج وحدّد السعر الجديد بالنقطة/وحدة.',
                'راجع لوحة التقدير: المخزون المتبقي، عدد الحاملين، وفرق النقاط المتوقع.',
                'احفظ — يُسجَّل التغيير ويصل تنبيه للإدارة فوراً.',
                'سجل التغييرات أدناه مرجع تاريخي (ليس على بطاقة المنتج في الموبايل).',
            ]"
            note="السياسة: قديم يبقى على فاتورته للأرشيف · جديد = سعر الكتالوج الموحّد."
            :actions="[
                ['label' => 'تقرير تغيرات الأسعار', 'url' => \App\Filament\Pages\ProductPointsChangesReport::getUrl(), 'tone' => 'ghost'],
            ]"
        />

        @if ($this->canApply())
            <div class="mg-rate-board__composer">
                <div class="mg-rate-board__form-pane">
                    <header class="mg-rate-board__pane-head">
                        <span class="mg-rate-board__pane-kicker">تعديل السعر</span>
                        <h3>عيّن السعر ثم اقرأ التقدير قبل التأكيد</h3>
                    </header>
                    <form wire:submit="applyRateChange" class="mg-points-page__form space-y-4">
                        {{ $this->form }}
                        <div class="mg-rate-board__actions">
                            <button type="button" class="mg-rate-board__btn mg-rate-board__btn--ghost" wire:click="refreshImpactPreview" wire:loading.attr="disabled">
                                تحديث التقدير
                            </button>
                            <button type="submit" class="mg-rate-board__btn mg-rate-board__btn--commit" wire:loading.attr="disabled" wire:target="applyRateChange">
                                <span wire:loading.remove wire:target="applyRateChange">تأكيد التغيير وتنبيه الإدارة</span>
                                <span wire:loading wire:target="applyRateChange">جاري الحفظ…</span>
                            </button>
                        </div>
                    </form>
                </div>

                <aside class="mg-rate-board__impact" aria-live="polite">
                    @php $impact = $this->impactPreview; @endphp
                    @if ($impact)
                        @php
                            $delta = (float) ($impact['delta_points_per_unit'] ?? 0);
                            $dir = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');
                            $pct = $impact['change_percent'];
                        @endphp
                        <div class="mg-rate-board__impact-inner mg-rate-board__impact-inner--{{ $dir }}">
                            <div class="mg-rate-board__impact-grain" aria-hidden="true"></div>
                            <p class="mg-rate-board__impact-kicker">تقدير الأثر قبل الحفظ</p>
                            <h4 class="mg-rate-board__impact-product">{{ $impact['product_name'] ?? '—' }}</h4>

                            <div class="mg-rate-board__ticker">
                                <div class="mg-rate-board__tick">
                                    <span>قبل</span>
                                    <strong>{{ number_format((float) $impact['old_points_per_unit'], 2) }}</strong>
                                </div>
                                <div class="mg-rate-board__arrow" aria-hidden="true">⟵</div>
                                <div class="mg-rate-board__tick mg-rate-board__tick--new">
                                    <span>بعد</span>
                                    <strong>{{ number_format((float) $impact['new_points_per_unit'], 2) }}</strong>
                                </div>
                            </div>

                            <div class="mg-rate-board__margin {{ $dir }}">
                                <span class="mg-rate-board__margin-label">هامش التغيير</span>
                                <span class="mg-rate-board__margin-val">
                                    {{ $delta >= 0 ? '+' : '' }}{{ number_format($delta, 2) }}
                                    @if ($pct !== null)
                                        <small>({{ $pct >= 0 ? '+' : '' }}{{ number_format((float) $pct, 1) }}%)</small>
                                    @endif
                                </span>
                            </div>

                            <dl class="mg-rate-board__stats">
                                <div>
                                    <dt>مخزون جملة متبقٍ</dt>
                                    <dd>{{ number_format((int) $impact['wholesale_remaining_qty']) }} <small>{{ (int) $impact['wholesale_holders'] }} تاجر</small></dd>
                                </div>
                                <div>
                                    <dt>مخزون قطاعي متبقٍ</dt>
                                    <dd>{{ number_format((int) $impact['retail_remaining_qty']) }} <small>{{ (int) $impact['retail_holders'] }} تاجر</small></dd>
                                </div>
                                <div class="mg-rate-board__stats-span">
                                    <dt>فرق النقاط لو صُرف كل المتبقي بالسعر الجديد</dt>
                                    <dd class="{{ $dir }}">{{ sprintf('%+d', (int) $impact['estimated_points_delta']) }} نقطة</dd>
                                </div>
                            </dl>

                            <p class="mg-rate-board__footnote">
                                التقدير تقريبي على الكمية المتبقية حالياً · الفواتير القديمة لا تُعاد تسعيرها · المبيعات الجديدة فقط تأخذ السعر الجديد.
                            </p>
                        </div>
                    @else
                        <div class="mg-rate-board__impact-empty">
                            <span class="mg-rate-board__empty-mark">◈</span>
                            <p>اختر منتجاً وأدخل السعر الجديد لترى تقدير المخزون والهامش هنا.</p>
                        </div>
                    @endif
                </aside>
            </div>
        @else
            <div class="mg-rate-board__readonly-note">
                لديك صلاحية عرض السجل فقط. تعديل الأسعار متاح لـ admin / super_admin.
            </div>
        @endif

        <section class="mg-rate-board__history">
            <header class="mg-rate-board__pane-head">
                <span class="mg-rate-board__pane-kicker">السجل</span>
                <h3>تاريخ تغييرات أسعار النقاط</h3>
            </header>
            {{ $this->table }}
        </section>
    </div>
</x-filament-panels::page>
