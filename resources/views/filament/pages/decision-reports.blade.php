@php
    $snap = $this->getSnapshot();
    $tabs = $this->tabs();
    $executive = $snap['executive'] ?? [];
    $network = $snap['network'] ?? [];
    $orders = $snap['orders'] ?? [];
    $points = $snap['points']['summary'] ?? [];
    $finance = $snap['finance'] ?? [];
    $inventory = $snap['inventory'] ?? [];
    $alerts = $snap['alerts'] ?? [];
    $trends = $snap['trends'] ?? ['labels' => [], 'orders' => [], 'deliveries' => []];
    $maxTrend = max(1, ...array_merge($trends['orders'] ?? [0], $trends['deliveries'] ?? [0]));
@endphp

<x-filament::page>
<div class="dec-report" dir="rtl">
    <div class="dec-hero">
        <div>
            <div class="dec-hero-title">مركز صناعة القرار</div>
            <div class="dec-hero-sub">تقارير الشبكة والطلبيات والنقاط والمالية — مع إمكانية سؤال Gemini داخل النظام</div>
        </div>
        <div class="dec-hero-controls">
            <label class="dec-select-label">الفترة</label>
            <select wire:model.live="windowDays" class="dec-select">
                <option value="7">آخر 7 أيام</option>
                <option value="14">آخر 14 يوم</option>
                <option value="30">آخر 30 يوم</option>
                <option value="90">آخر 90 يوم</option>
            </select>
        </div>
    </div>

    <div class="dec-tabs">
        @foreach($tabs as $tab)
            <button type="button"
                wire:click="setTab('{{ $tab['key'] }}')"
                class="dec-tab {{ $activeTab === $tab['key'] ? 'dec-tab--on' : '' }}">
                <span>{{ $tab['icon'] }}</span> {{ $tab['label'] }}
            </button>
        @endforeach
    </div>

    @if($activeTab === 'overview')
        <div class="dec-stats">
            @foreach($executive as $card)
                <div class="dec-stat dec-stat--{{ $card['color'] }}">
                    <div class="dec-stat-num">{{ is_numeric($card['value']) ? number_format($card['value']) : $card['value'] }}</div>
                    <div class="dec-stat-label">{{ $card['label'] }}</div>
                    @if(!empty($card['hint']))
                        <div class="dec-stat-hint">{{ $card['hint'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="dec-grid-2">
            <div class="dec-card">
                <h3>تنبيهات لصانع القرار</h3>
                <div class="dec-alerts">
                    @foreach($alerts as $alert)
                        <div class="dec-alert dec-alert--{{ $alert['level'] }}">
                            <strong>{{ $alert['title'] }}</strong>
                            <p>{{ $alert['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="dec-card">
                <h3>اتجاه الطلبيات (آخر أيام الفترة)</h3>
                <div class="dec-bars">
                    @foreach(($trends['labels'] ?? []) as $i => $label)
                        @php
                            $o = (int) ($trends['orders'][$i] ?? 0);
                            $d = (int) ($trends['deliveries'][$i] ?? 0);
                            $oh = (int) round(($o / $maxTrend) * 100);
                            $dh = (int) round(($d / $maxTrend) * 100);
                        @endphp
                        <div class="dec-bar-col" title="طلبات {{ $o }} · تسليم {{ $d }}">
                            <div class="dec-bar-stack">
                                <div class="dec-bar dec-bar--orders" style="height: {{ max(4, $oh) }}%"></div>
                                <div class="dec-bar dec-bar--deliver" style="height: {{ max(2, $dh) }}%"></div>
                            </div>
                            <span>{{ $label }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="dec-legend">
                    <span><i class="dec-dot dec-dot--blue"></i> طلبات جديدة</span>
                    <span><i class="dec-dot dec-dot--green"></i> تسليمات</span>
                </div>
                <div class="dec-quick-ask">
                    <button type="button" class="dec-chip-btn" wire:click="askSuggestion('ما أهم قرار يجب اتخاذه هذا الأسبوع بناءً على التنبيهات والمؤشرات؟')">✨ لخّص القرار الأهم</button>
                </div>
            </div>
        </div>
    @endif

    @if($activeTab === 'network')
        <div class="dec-card">
            <h3>قمع النقاط عبر الشبكة</h3>
            <div class="dec-funnel">
                @foreach(($network['steps'] ?? []) as $step)
                    <div class="dec-funnel-step dec-funnel-step--{{ $step['color'] }}">
                        <div class="dec-funnel-val">{{ number_format($step['value']) }}</div>
                        <div class="dec-funnel-lbl">{{ $step['label'] }}</div>
                    </div>
                    @if(! $loop->last)<div class="dec-funnel-arrow">←</div>@endif
                @endforeach
            </div>
            <div class="dec-kv">
                <div><span>روابط جملة↔قطاعي</span><strong>{{ number_format($network['links']['wholesaler_retail_links'] ?? 0) }}</strong></div>
                <div><span>سباكون مرتبطون</span><strong>{{ number_format($network['links']['plumbers_linked'] ?? 0) }}</strong></div>
                <div><span>سباكون بدون محل</span><strong>{{ number_format($network['links']['plumbers_unlinked'] ?? 0) }}</strong></div>
            </div>
        </div>
    @endif

    @if($activeTab === 'orders')
        <div class="dec-stats">
            <div class="dec-stat dec-stat--blue"><div class="dec-stat-num">{{ number_format($orders['summary']['total'] ?? 0) }}</div><div class="dec-stat-label">إجمالي الطلبات</div></div>
            <div class="dec-stat dec-stat--amber"><div class="dec-stat-num">{{ number_format($orders['summary']['open'] ?? 0) }}</div><div class="dec-stat-label">مفتوحة</div></div>
            <div class="dec-stat dec-stat--green"><div class="dec-stat-num">{{ number_format($orders['summary']['delivered'] ?? 0) }}</div><div class="dec-stat-label">تم التسليم</div></div>
            <div class="dec-stat dec-stat--teal"><div class="dec-stat-num">{{ number_format($orders['summary']['delivery_rate'] ?? 0, 1) }}%</div><div class="dec-stat-label">نسبة التسليم</div></div>
        </div>
        <div class="dec-grid-2">
            <div class="dec-card">
                <h3>حسب الحالة</h3>
                @foreach(($orders['by_status'] ?? []) as $row)
                    <div class="dec-row"><span>{{ $row['label'] }}</span><strong>{{ number_format($row['count']) }}</strong></div>
                @endforeach
            </div>
            <div class="dec-card">
                <h3>حسب القناة</h3>
                @foreach(($orders['by_channel'] ?? []) as $row)
                    <div class="dec-row"><span>{{ $row['label'] }}</span><strong>{{ number_format($row['count']) }}</strong></div>
                @endforeach
            </div>
        </div>
        <div class="dec-card">
            <h3>آخر الطلبيات</h3>
            <div class="dec-table-wrap">
                <table class="dec-table">
                    <thead>
                        <tr>
                            <th>الرقم</th><th>القناة</th><th>الحالة</th><th>الطالب</th><th>المورّد</th><th>كمية</th><th>نقاط</th><th>التاريخ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse(($orders['recent'] ?? []) as $row)
                            <tr>
                                <td>{{ $row['order_number'] }}</td>
                                <td>{{ $row['channel'] }}</td>
                                <td><span class="dec-badge">{{ $row['status'] }}</span></td>
                                <td>{{ $row['requester'] }}</td>
                                <td>{{ $row['supplier'] }}</td>
                                <td>{{ number_format($row['qty']) }}</td>
                                <td>{{ number_format($row['points']) }}</td>
                                <td>{{ $row['created_at'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="dec-empty">لا توجد طلبيات في هذه الفترة</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if($activeTab === 'points')
        <div class="dec-stats">
            <div class="dec-stat dec-stat--purple"><div class="dec-stat-num">{{ number_format($points['factory_points'] ?? 0) }}</div><div class="dec-stat-label">نقاط المصنع</div></div>
            <div class="dec-stat dec-stat--blue"><div class="dec-stat-num">{{ number_format($points['wholesale_remaining'] ?? 0) }}</div><div class="dec-stat-label">متبقي جملة</div></div>
            <div class="dec-stat dec-stat--amber"><div class="dec-stat-num">{{ number_format($points['distributed'] ?? 0) }}</div><div class="dec-stat-label">موزَّع</div></div>
            <div class="dec-stat dec-stat--green"><div class="dec-stat-num">{{ number_format($points['plumber_balances'] ?? 0) }}</div><div class="dec-stat-label">أرصدة سباكين</div></div>
            <div class="dec-stat dec-stat--teal"><div class="dec-stat-num">{{ number_format($points['awarded_in_window'] ?? 0) }}</div><div class="dec-stat-label">مُنحت في الفترة</div></div>
            <div class="dec-stat dec-stat--amber"><div class="dec-stat-num">{{ number_format($points['returned_points_in_window'] ?? 0) }}</div><div class="dec-stat-label">نقاط مرتجعات</div></div>
        </div>
        <div class="dec-card">
            <p class="dec-note">المنتجات ذات النقاط: {{ number_format($points['products_with_points'] ?? 0) }} — راجع أيضاً «تقرير المنتجات والنقاط» للتفصيل حسب صنف.</p>
        </div>
    @endif

    @if($activeTab === 'finance')
        @php $fs = $finance['summary'] ?? []; @endphp
        <div class="dec-stats">
            <div class="dec-stat dec-stat--amber"><div class="dec-stat-num">{{ number_format($fs['withdrawals_pending'] ?? 0) }}</div><div class="dec-stat-label">سحوبات معلّقة</div><div class="dec-stat-hint">{{ number_format($fs['pending_amount_lyd'] ?? 0, 2) }} د.ل</div></div>
            <div class="dec-stat dec-stat--green"><div class="dec-stat-num">{{ number_format($fs['withdrawals_paid'] ?? 0) }}</div><div class="dec-stat-label">مدفوعة (الفترة)</div><div class="dec-stat-hint">{{ number_format($fs['paid_amount_lyd'] ?? 0, 2) }} د.ل</div></div>
            <div class="dec-stat dec-stat--purple"><div class="dec-stat-num">{{ number_format($fs['wallet_points_total'] ?? 0) }}</div><div class="dec-stat-label">نقاط المحافظ</div></div>
            <div class="dec-stat dec-stat--blue"><div class="dec-stat-num">{{ number_format(($fs['wallet_cents_total'] ?? 0) / 100, 2) }}</div><div class="dec-stat-label">رصيد مالي د.ل</div></div>
            <div class="dec-stat dec-stat--amber"><div class="dec-stat-num">{{ number_format($fs['invoices_pending_review'] ?? 0) }}</div><div class="dec-stat-label">فواتير قيد المراجعة</div></div>
        </div>
        <div class="dec-card">
            <h3>آخر طلبات السحب</h3>
            <div class="dec-table-wrap">
                <table class="dec-table">
                    <thead><tr><th>#</th><th>المستخدم</th><th>الهاتف</th><th>المبلغ</th><th>الحالة</th><th>التاريخ</th></tr></thead>
                    <tbody>
                        @forelse(($finance['recent_withdrawals'] ?? []) as $w)
                            <tr>
                                <td>{{ $w['id'] }}</td>
                                <td>{{ $w['user'] }}</td>
                                <td>{{ $w['phone'] }}</td>
                                <td>{{ $w['amount'] }}</td>
                                <td>{{ $w['status'] }}</td>
                                <td>{{ $w['created_at'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="dec-empty">لا توجد سحوبات</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if($activeTab === 'inventory')
        <div class="dec-stats">
            <div class="dec-stat dec-stat--blue"><div class="dec-stat-num">{{ number_format($inventory['catalog_total'] ?? 0) }}</div><div class="dec-stat-label">كل المنتجات</div></div>
            <div class="dec-stat dec-stat--green"><div class="dec-stat-num">{{ number_format($inventory['with_points'] ?? 0) }}</div><div class="dec-stat-label">بنقاط (MG)</div></div>
            <div class="dec-stat dec-stat--amber"><div class="dec-stat-num">{{ number_format($inventory['without_points'] ?? 0) }}</div><div class="dec-stat-label">بدون نقاط</div></div>
        </div>
        <div class="dec-card">
            <h3>أعلى منتجات بالنقاط/وحدة</h3>
            <div class="dec-table-wrap">
                <table class="dec-table">
                    <thead><tr><th>المنتج</th><th>النقاط/وحدة</th><th>التصنيف</th></tr></thead>
                    <tbody>
                        @foreach(($inventory['top_point_products'] ?? []) as $p)
                            <tr>
                                <td>{{ $p['name'] }}</td>
                                <td>{{ rtrim(rtrim(number_format($p['points_per_unit'], 2), '0'), '.') }}</td>
                                <td>{{ $p['product_kind'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if($activeTab === 'gemini')
        <div class="dec-card dec-gemini">
            <h3>✨ اسأل Gemini عن البيانات</h3>
            <p class="dec-note">
                Gemini يقرأ ملخص التقرير الحالي (الفترة المختارة) ويجيب بالعربية لصانع القرار.
                @if(! $this->geminiEnabled())
                    <strong class="dec-warn">المفتاح غير مضبوط — أضف <code>GEMINI_API_KEY</code> في .env</strong>
                @else
                    النموذج جاهز: <code>{{ config('services.gemini.model') }}</code>
                @endif
            </p>

            <div class="dec-suggestions">
                <button type="button" class="dec-chip-btn" wire:click="askSuggestion('أين أكبر عنق زجاجة في شبكة التوزيع حالياً؟')">عنق الزجاجة؟</button>
                <button type="button" class="dec-chip-btn" wire:click="askSuggestion('هل نسبة تسليم الطلبيات جيدة وماذا أحسّن؟')">نسبة التسليم</button>
                <button type="button" class="dec-chip-btn" wire:click="askSuggestion('ما أولوية السحوبات والفواتير المعلّقة؟')">أولوية مالية</button>
                <button type="button" class="dec-chip-btn" wire:click="askSuggestion('اقترح 3 قرارات تشغيلية للأسبوع القادم')">3 قرارات</button>
            </div>

            <textarea wire:model="geminiQuestion" class="dec-textarea" rows="3" placeholder="مثال: قارن بين نمو القطاعي والسباكين واقترح خطة ربط…"></textarea>
            <button type="button" class="dec-ask-btn" wire:click="askGemini" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="askGemini">اسأل Gemini</span>
                <span wire:loading wire:target="askGemini">جاري التحليل…</span>
            </button>

            @if($geminiError)
                <div class="dec-alert dec-alert--danger"><strong>تعذّر</strong><p>{{ $geminiError }}</p></div>
            @endif
            @if($geminiAnswer)
                <div class="dec-answer">
                    <div class="dec-answer-title">إجابة Gemini</div>
                    <div class="dec-answer-body">{!! nl2br(e($geminiAnswer)) !!}</div>
                </div>
            @endif
        </div>
    @endif
</div>
</x-filament::page>
