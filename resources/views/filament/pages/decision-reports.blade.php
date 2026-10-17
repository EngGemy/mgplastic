@php
    $snap = $this->getSnapshot();
    $tabs = $this->tabs();
    $prompts = $this->decisionPrompts();
    $executive = $snap['executive'] ?? [];
    $network = $snap['network'] ?? [];
    $orders = $snap['orders'] ?? [];
    $points = $snap['points']['summary'] ?? [];
    $finance = $snap['finance'] ?? [];
    $inventory = $snap['inventory'] ?? [];
    $alerts = $snap['alerts'] ?? [];
    $trends = $snap['trends'] ?? ['labels' => [], 'orders' => [], 'deliveries' => []];
    $maxTrend = max(1, ...array_merge($trends['orders'] ?? [0], $trends['deliveries'] ?? [0]));
    $geminiOn = $this->geminiEnabled();
@endphp

<x-filament::page>
<div class="dec-hub" dir="rtl">

    {{-- ═══ Section shell ═══ --}}
    <header class="dec-hub-head">
        <div class="dec-hub-brand">
            <div class="dec-hub-kicker">سكشن صناعة القرار · MG Plastic</div>
            <h1 class="dec-hub-title">غرفة القرارات</h1>
            <p class="dec-hub-lead">{{ $this->decisionBrief() }}</p>
        </div>
        <div class="dec-hub-meta">
            <div class="dec-hub-meta-card">
                <span class="dec-hub-meta-label">نافذة التحليل</span>
                <select wire:model.live="windowDays" class="dec-hub-select">
                    <option value="7">آخر 7 أيام</option>
                    <option value="14">آخر 14 يوم</option>
                    <option value="30">آخر 30 يوم</option>
                    <option value="90">آخر 90 يوم</option>
                </select>
            </div>
            <div class="dec-hub-meta-card">
                <span class="dec-hub-meta-label">حالة المساعد</span>
                @if($geminiOn)
                    <span class="dec-pill dec-pill--ok">Gemini جاهز · {{ config('services.gemini.model') }}</span>
                @else
                    <span class="dec-pill dec-pill--warn">أضف GEMINI_API_KEY في .env</span>
                @endif
            </div>
            <div class="dec-hub-meta-card">
                <span class="dec-hub-meta-label">آخر تحديث</span>
                <strong>{{ now()->timezone('Africa/Tripoli')->format('Y/m/d H:i') }}</strong>
            </div>
        </div>
    </header>

    {{-- ═══ Layout: prompt rail + reports stage ═══ --}}
    <div class="dec-hub-layout">

        {{-- Decision prompt panel (always visible) --}}
        <aside class="dec-prompt" id="decision-prompt">
            <div class="dec-prompt-head">
                <span class="dec-prompt-badge">برومبت صانع القرار</span>
                <h2>اسأل · قرّر · نفّذ</h2>
                <p>المساعد يقرأ ملخص مؤشرات الفترة المختارة فقط، ويجيب بتوصيات عملية بالعربية.</p>
            </div>

            <div class="dec-prompt-role">
                <div class="dec-prompt-role-title">دور المساعد</div>
                <ul>
                    <li>يعتمد على بيانات التقرير الحي فقط</li>
                    <li>يقترح 1–5 قرارات واضحة عند الإمكان</li>
                    <li>يذكر إن نقصت معلومة — بلا تخمين</li>
                </ul>
            </div>

            <label class="dec-prompt-label" for="geminiQuestion">اكتب سؤالك أو اختر جاهزاً</label>
            <textarea
                id="geminiQuestion"
                wire:model="geminiQuestion"
                class="dec-prompt-textarea"
                rows="4"
                placeholder="مثال: قارن نمو القطاعي مقابل السباكين غير المرتبطين، واقترح خطة ربط لشهر…"
            ></textarea>

            <button type="button" class="dec-prompt-submit" wire:click="askGemini" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="askGemini">تحليل وإصدار توصية</span>
                <span wire:loading wire:target="askGemini">جاري التحليل…</span>
            </button>

            <div class="dec-prompt-chips">
                @foreach($prompts as $p)
                    <button type="button" class="dec-prompt-chip" wire:click="askSuggestion(@js($p['q']))">
                        <span class="dec-prompt-chip-tag">{{ $p['tag'] }}</span>
                        {{ $p['label'] }}
                    </button>
                @endforeach
            </div>

            @if($geminiError)
                <div class="dec-alert dec-alert--danger">
                    <strong>تعذّر التحليل</strong>
                    <p>{{ $geminiError }}</p>
                </div>
            @endif

            @if($geminiAnswer)
                <div class="dec-prompt-answer">
                    <div class="dec-prompt-answer-title">توصية القرار</div>
                    <div class="dec-prompt-answer-body">{!! nl2br(e($geminiAnswer)) !!}</div>
                </div>
            @endif
        </aside>

        {{-- Reports stage --}}
        <main class="dec-stage">
            <nav class="dec-stage-tabs" aria-label="تبويبات التقرير">
                @foreach($tabs as $tab)
                    <button type="button"
                        wire:click="setTab('{{ $tab['key'] }}')"
                        class="dec-stage-tab {{ $activeTab === $tab['key'] ? 'dec-stage-tab--on' : '' }}">
                        <strong>{{ $tab['label'] }}</strong>
                        <span>{{ $tab['hint'] }}</span>
                    </button>
                @endforeach
            </nav>

            <div class="dec-stage-body">

                @if($activeTab === 'overview')
                    <section class="dec-section">
                        <header class="dec-section-head">
                            <h3>الملخّص التنفيذي</h3>
                            <p>مؤشرات شبكة MG Plastic خلال آخر {{ $windowDays }} يوماً</p>
                        </header>
                        <div class="dec-stats">
                            @foreach($executive as $card)
                                <article class="dec-stat dec-stat--{{ $card['color'] }}">
                                    <div class="dec-stat-num">{{ is_numeric($card['value']) ? number_format($card['value']) : $card['value'] }}</div>
                                    <div class="dec-stat-label">{{ $card['label'] }}</div>
                                    @if(!empty($card['hint']))
                                        <div class="dec-stat-hint">{{ $card['hint'] }}</div>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    </section>

                    <div class="dec-grid-2">
                        <section class="dec-section">
                            <header class="dec-section-head">
                                <h3>تنبيهات القرار</h3>
                                <p>ما يحتاج تدخّلاً الآن</p>
                            </header>
                            <div class="dec-alerts">
                                @foreach($alerts as $alert)
                                    <div class="dec-alert dec-alert--{{ $alert['level'] }}">
                                        <strong>{{ $alert['title'] }}</strong>
                                        <p>{{ $alert['body'] }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                        <section class="dec-section">
                            <header class="dec-section-head">
                                <h3>اتجاه الطلبيات</h3>
                                <p>طلبات جديدة مقابل تسليمات</p>
                            </header>
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
                                <span><i class="dec-dot dec-dot--blue"></i> طلبات</span>
                                <span><i class="dec-dot dec-dot--green"></i> تسليمات</span>
                            </div>
                        </section>
                    </div>
                @endif

                @if($activeTab === 'network')
                    <section class="dec-section">
                        <header class="dec-section-head">
                            <h3>قمع النقاط عبر الشبكة</h3>
                            <p>من المصنع حتى أرصدة السباكين</p>
                        </header>
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
                    </section>
                @endif

                @if($activeTab === 'orders')
                    <section class="dec-section">
                        <header class="dec-section-head">
                            <h3>أداء الطلبيات</h3>
                            <p>ملخص الفترة · {{ $windowDays }} يوم</p>
                        </header>
                        <div class="dec-stats dec-stats--4">
                            <article class="dec-stat dec-stat--blue"><div class="dec-stat-num">{{ number_format($orders['summary']['total'] ?? 0) }}</div><div class="dec-stat-label">إجمالي</div></article>
                            <article class="dec-stat dec-stat--amber"><div class="dec-stat-num">{{ number_format($orders['summary']['open'] ?? 0) }}</div><div class="dec-stat-label">مفتوحة</div></article>
                            <article class="dec-stat dec-stat--green"><div class="dec-stat-num">{{ number_format($orders['summary']['delivered'] ?? 0) }}</div><div class="dec-stat-label">مسلَّمة</div></article>
                            <article class="dec-stat dec-stat--teal"><div class="dec-stat-num">{{ number_format($orders['summary']['delivery_rate'] ?? 0, 1) }}%</div><div class="dec-stat-label">نسبة التسليم</div></article>
                        </div>
                    </section>
                    <div class="dec-grid-2">
                        <section class="dec-section">
                            <header class="dec-section-head"><h3>حسب الحالة</h3></header>
                            @foreach(($orders['by_status'] ?? []) as $row)
                                <div class="dec-row"><span>{{ $row['label'] }}</span><strong>{{ number_format($row['count']) }}</strong></div>
                            @endforeach
                        </section>
                        <section class="dec-section">
                            <header class="dec-section-head"><h3>حسب القناة</h3></header>
                            @foreach(($orders['by_channel'] ?? []) as $row)
                                <div class="dec-row"><span>{{ $row['label'] }}</span><strong>{{ number_format($row['count']) }}</strong></div>
                            @endforeach
                        </section>
                    </div>
                    <section class="dec-section">
                        <header class="dec-section-head"><h3>آخر الطلبيات</h3></header>
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
                    </section>
                @endif

                @if($activeTab === 'points')
                    <section class="dec-section">
                        <header class="dec-section-head">
                            <h3>تدفق النقاط</h3>
                            <p>أرصدة الشبكة ومنح الفترة</p>
                        </header>
                        <div class="dec-stats">
                            <article class="dec-stat dec-stat--purple"><div class="dec-stat-num">{{ number_format($points['factory_points'] ?? 0) }}</div><div class="dec-stat-label">نقاط المصنع</div></article>
                            <article class="dec-stat dec-stat--blue"><div class="dec-stat-num">{{ number_format($points['wholesale_remaining'] ?? 0) }}</div><div class="dec-stat-label">متبقي جملة</div></article>
                            <article class="dec-stat dec-stat--amber"><div class="dec-stat-num">{{ number_format($points['distributed'] ?? 0) }}</div><div class="dec-stat-label">موزَّع</div></article>
                            <article class="dec-stat dec-stat--green"><div class="dec-stat-num">{{ number_format($points['plumber_balances'] ?? 0) }}</div><div class="dec-stat-label">أرصدة سباكين</div></article>
                            <article class="dec-stat dec-stat--teal"><div class="dec-stat-num">{{ number_format($points['awarded_in_window'] ?? 0) }}</div><div class="dec-stat-label">مُنحت في الفترة</div></article>
                            <article class="dec-stat dec-stat--amber"><div class="dec-stat-num">{{ number_format($points['returned_points_in_window'] ?? 0) }}</div><div class="dec-stat-label">نقاط مرتجعات</div></article>
                        </div>
                        <p class="dec-note">المنتجات ذات النقاط: {{ number_format($points['products_with_points'] ?? 0) }} — التفاصيل في «تقرير المنتجات والنقاط» داخل نفس سكشن صناعة القرار.</p>
                    </section>
                @endif

                @if($activeTab === 'finance')
                    @php $fs = $finance['summary'] ?? []; @endphp
                    <section class="dec-section">
                        <header class="dec-section-head">
                            <h3>المالية والسحوبات</h3>
                            <p>ما يحتاج موافقة وما تم صرفه</p>
                        </header>
                        <div class="dec-stats">
                            <article class="dec-stat dec-stat--amber"><div class="dec-stat-num">{{ number_format($fs['withdrawals_pending'] ?? 0) }}</div><div class="dec-stat-label">سحوبات معلّقة</div><div class="dec-stat-hint">{{ number_format($fs['pending_amount_lyd'] ?? 0, 2) }} د.ل</div></article>
                            <article class="dec-stat dec-stat--green"><div class="dec-stat-num">{{ number_format($fs['withdrawals_paid'] ?? 0) }}</div><div class="dec-stat-label">مدفوعة (الفترة)</div><div class="dec-stat-hint">{{ number_format($fs['paid_amount_lyd'] ?? 0, 2) }} د.ل</div></article>
                            <article class="dec-stat dec-stat--purple"><div class="dec-stat-num">{{ number_format($fs['wallet_points_total'] ?? 0) }}</div><div class="dec-stat-label">نقاط المحافظ</div></article>
                            <article class="dec-stat dec-stat--blue"><div class="dec-stat-num">{{ number_format(($fs['wallet_cents_total'] ?? 0) / 100, 2) }}</div><div class="dec-stat-label">رصيد مالي د.ل</div></article>
                            <article class="dec-stat dec-stat--amber"><div class="dec-stat-num">{{ number_format($fs['invoices_pending_review'] ?? 0) }}</div><div class="dec-stat-label">فواتير قيد المراجعة</div></article>
                        </div>
                    </section>
                    <section class="dec-section">
                        <header class="dec-section-head"><h3>آخر طلبات السحب</h3></header>
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
                    </section>
                @endif

                @if($activeTab === 'inventory')
                    <section class="dec-section">
                        <header class="dec-section-head">
                            <h3>المنتجات والكتالوج</h3>
                            <p>توزيع النقاط على الأصناف</p>
                        </header>
                        <div class="dec-stats dec-stats--4">
                            <article class="dec-stat dec-stat--blue"><div class="dec-stat-num">{{ number_format($inventory['catalog_total'] ?? 0) }}</div><div class="dec-stat-label">كل المنتجات</div></article>
                            <article class="dec-stat dec-stat--green"><div class="dec-stat-num">{{ number_format($inventory['with_points'] ?? 0) }}</div><div class="dec-stat-label">بنقاط (MG)</div></article>
                            <article class="dec-stat dec-stat--amber"><div class="dec-stat-num">{{ number_format($inventory['without_points'] ?? 0) }}</div><div class="dec-stat-label">بدون نقاط</div></article>
                        </div>
                    </section>
                    <section class="dec-section">
                        <header class="dec-section-head"><h3>أعلى منتجات بالنقاط/وحدة</h3></header>
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
                    </section>
                @endif

                @if($activeTab === 'gemini')
                    <section class="dec-section dec-section--focus">
                        <header class="dec-section-head">
                            <h3>مساحة برومبت القرار</h3>
                            <p>نفس اللوحة اليسرى — هنا تظهر التوصية بشكل أعرض للمراجعة والطباعة</p>
                        </header>
                        @if($geminiAnswer)
                            <div class="dec-prompt-answer dec-prompt-answer--wide">
                                <div class="dec-prompt-answer-title">توصية القرار</div>
                                <div class="dec-prompt-answer-q"><span>سؤالك:</span> {{ $geminiQuestion }}</div>
                                <div class="dec-prompt-answer-body">{!! nl2br(e($geminiAnswer)) !!}</div>
                            </div>
                        @else
                            <div class="dec-empty-stage">
                                <p>اكتب برومبت في اللوحة اليمنى أو اختر سؤالاً جاهزاً لبدء التحليل.</p>
                            </div>
                        @endif
                    </section>
                @endif

            </div>
        </main>
    </div>
</div>
</x-filament::page>
