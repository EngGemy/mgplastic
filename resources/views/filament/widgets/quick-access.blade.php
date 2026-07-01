<x-filament-widgets::widget>
    <div class="fi-wi-quick-access fi-wi-quick-access--hero" dir="rtl">

        <header class="fi-wi-qa-header">
            <div>
                <h2 class="fi-wi-qa-title">{{ $title }}</h2>
                @if(!empty($subtitle))
                    <p class="fi-wi-qa-subtitle">{{ $subtitle }}</p>
                @endif
            </div>
        </header>

        @if($wholesalerSummary || $wallet || $recentDistributions->isNotEmpty())
        <div style="
            background: linear-gradient(135deg, #1a3a6e 0%, #1a56db 100%);
            border-radius: 12px;
            padding: 18px 22px;
            margin-bottom: 1.25rem;
            color: white;
            font-family: 'Cairo', sans-serif;
            direction: rtl;
        ">
            @if($wholesalerSummary)
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px;">
                <div style="background:rgba(255,255,255,0.12);border-radius:10px;padding:12px 14px;text-align:center;">
                    <div style="font-size:11px;opacity:0.8;font-weight:600;">رصيد النقاط الحالي</div>
                    <div style="font-size:1.8rem;font-weight:900;margin-top:4px;">{{ number_format($wholesalerSummary['balance_points']) }}</div>
                    <div style="font-size:11px;opacity:0.75;">نقطة</div>
                </div>
                <div style="background:rgba(255,255,255,0.12);border-radius:10px;padding:12px 14px;text-align:center;">
                    <div style="font-size:11px;opacity:0.8;font-weight:600;">من المصنع</div>
                    <div style="font-size:1.8rem;font-weight:900;margin-top:4px;">{{ number_format($wholesalerSummary['factory_points']) }}</div>
                    <div style="font-size:11px;opacity:0.75;">نقطة واصلة</div>
                </div>
                <div style="background:rgba(255,255,255,0.12);border-radius:10px;padding:12px 14px;text-align:center;">
                    <div style="font-size:11px;opacity:0.8;font-weight:600;">موزَّع للقطاعي</div>
                    <div style="font-size:1.8rem;font-weight:900;margin-top:4px;">{{ number_format($wholesalerSummary['distributed_points']) }}</div>
                    <div style="font-size:11px;opacity:0.75;">نقطة مخصومة</div>
                </div>
                <div style="background:rgba(255,255,255,0.12);border-radius:10px;padding:12px 14px;text-align:center;">
                    <div style="font-size:11px;opacity:0.8;font-weight:600;">وحدات منتجات</div>
                    <div style="font-size:1.8rem;font-weight:900;margin-top:4px;">{{ number_format($wholesalerSummary['product_units']) }}</div>
                    <div style="font-size:11px;opacity:0.75;">{{ $wholesalerSummary['product_types'] }} صنف</div>
                </div>
            </div>
            @endif

            <div style="display:flex;gap:24px;align-items:center;flex-wrap:wrap;">
            @if(!$wholesalerSummary && $wallet)
            <div style="flex:0 0 auto;">
                <div style="font-size:11px;opacity:0.75;font-weight:600;letter-spacing:0.05em;">رصيد النقاط الحالي</div>
                <div style="font-size:2.5rem;font-weight:900;line-height:1.1;margin-top:4px;">
                    {{ number_format($wallet?->balance_points ?? 0) }}
                    <span style="font-size:1rem;opacity:0.75;font-weight:600;">نقطة</span>
                </div>
            </div>

            <div style="width:1px;background:rgba(255,255,255,0.2);align-self:stretch;flex:0 0 auto;"></div>
            @endif

            @if($recentDistributions->isNotEmpty())
            <div style="flex:1;min-width:200px;">
                <div style="font-size:11px;opacity:0.75;font-weight:600;margin-bottom:8px;">آخر التوزيعات الواصلة</div>
                <div style="display:flex;flex-direction:column;gap:5px;">
                    @foreach($recentDistributions as $dist)
                    <div style="display:flex;justify-content:space-between;align-items:center;background:rgba(255,255,255,0.1);border-radius:6px;padding:5px 10px;">
                        <span style="font-size:12px;font-weight:600;">{{ $dist['invoice_number'] }}</span>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="font-size:11px;opacity:0.7;">{{ $dist['date'] }}</span>
                            <span style="background:rgba(255,255,255,0.2);padding:1px 8px;border-radius:999px;font-size:12px;font-weight:700;">
                                +{{ number_format($dist['points']) }} نقطة
                            </span>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
            </div>
        </div>
        @endif

        @if(!empty($pointsBanner))
        <div style="
            background: linear-gradient(135deg, #fff7ed 0%, #fef3c7 100%);
            border: 1.5px solid #fcd34d;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 1.25rem;
            direction: rtl;
            font-family: 'Cairo', sans-serif;
        ">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                <span style="font-size:18px;">⭐</span>
                <span style="font-size:14px;font-weight:700;color:#92400e;">كيف تعمل النقاط؟</span>
            </div>

            <div style="display:flex;align-items:center;gap:6px;margin-bottom:14px;flex-wrap:wrap;">
                <div style="background:#1a3a6e;color:white;padding:5px 12px;border-radius:8px;font-size:12px;font-weight:700;">
                    🏭 المصنع
                </div>
                <div style="color:#d97706;font-weight:700;font-size:16px;">→</div>
                @if($pointsBanner['type'] === 'wholesaler')
                <div style="background:#1a56db;color:white;padding:5px 12px;border-radius:8px;font-size:12px;font-weight:700;border:2px solid #93c5fd;">
                    🚚 أنت (موزع الجملة)
                </div>
                @else
                <div style="background:#6b7280;color:white;padding:5px 12px;border-radius:8px;font-size:12px;">
                    🚚 موزع الجملة
                </div>
                @endif
                <div style="color:#d97706;font-weight:700;font-size:16px;">→</div>
                @if($pointsBanner['type'] === 'trader')
                <div style="background:#059669;color:white;padding:5px 12px;border-radius:8px;font-size:12px;font-weight:700;border:2px solid #6ee7b7;">
                    🏪 أنت (تاجر قطاعي)
                </div>
                @else
                <div style="background:#6b7280;color:white;padding:5px 12px;border-radius:8px;font-size:12px;">
                    🏪 تاجر قطاعي
                </div>
                @endif
                <div style="color:#d97706;font-weight:700;font-size:16px;">→</div>
                <div style="background:#f59e0b;color:white;padding:5px 12px;border-radius:8px;font-size:12px;font-weight:700;">
                    🔧 السباك ← النقاط هنا
                </div>
            </div>

            @if($pointsBanner['type'] === 'wholesaler')
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:10px;">
                <div style="background:white;border-radius:8px;padding:10px;text-align:center;border:1px solid #fde68a;">
                    <div style="font-size:20px;font-weight:900;color:#1d4ed8;">{{ number_format($pointsBanner['total']) }}</div>
                    <div style="font-size:11px;color:#6b7280;font-weight:600;">وصلتك من المصنع</div>
                </div>
                <div style="background:white;border-radius:8px;padding:10px;text-align:center;border:1px solid #fde68a;">
                    <div style="font-size:20px;font-weight:900;color:#059669;">{{ number_format($pointsBanner['distributed']) }}</div>
                    <div style="font-size:11px;color:#6b7280;font-weight:600;">وزّعتها على القطاعي</div>
                </div>
                <div style="background:white;border-radius:8px;padding:10px;text-align:center;border:1px solid #fde68a;">
                    <div style="font-size:20px;font-weight:900;color:#d97706;">{{ number_format($pointsBanner['remaining']) }}</div>
                    <div style="font-size:11px;color:#6b7280;font-weight:600;">متبقية للتوزيع</div>
                </div>
            </div>
            @else
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:10px;">
                <div style="background:white;border-radius:8px;padding:10px;text-align:center;border:1px solid #fde68a;">
                    <div style="font-size:20px;font-weight:900;color:#1d4ed8;">{{ number_format($pointsBanner['received']) }}</div>
                    <div style="font-size:11px;color:#6b7280;font-weight:600;">استلمتها من الموزع</div>
                </div>
                <div style="background:white;border-radius:8px;padding:10px;text-align:center;border:1px solid #fde68a;">
                    <div style="font-size:20px;font-weight:900;color:#059669;">{{ number_format($pointsBanner['sent_to_plumbers']) }}</div>
                    <div style="font-size:11px;color:#6b7280;font-weight:600;">وصلت للسباكين</div>
                </div>
                <div style="background:white;border-radius:8px;padding:10px;text-align:center;border:1px solid #fde68a;">
                    <div style="font-size:20px;font-weight:900;color:#d97706;">{{ number_format($pointsBanner['remaining']) }}</div>
                    <div style="font-size:11px;color:#6b7280;font-weight:600;">لم توزَّع بعد</div>
                </div>
            </div>
            @endif

            <div>
                <div style="display:flex;justify-content:space-between;font-size:11px;color:#92400e;font-weight:600;margin-bottom:4px;">
                    <span>تم توزيع {{ $pointsBanner['percent'] }}% من النقاط</span>
                    <span>{{ 100 - $pointsBanner['percent'] }}% متبقي</span>
                </div>
                <div style="background:#fde68a;border-radius:999px;height:8px;overflow:hidden;">
                    <div style="background:linear-gradient(90deg,#059669,#34d399);height:100%;width:{{ $pointsBanner['percent'] }}%;border-radius:999px;"></div>
                </div>
            </div>

            <div style="margin-top:10px;font-size:11px;color:#92400e;font-weight:500;">
                💡 النقاط لا تنتهي لديك — هي في الطريق إلى السباك. وزّعها على تجارك القطاعيين ليوزعوها على السباكين.
            </div>
        </div>
        @endif

        @foreach($sections as $section)
            <section class="fi-wi-qa-section">
                <h3 class="fi-wi-qa-section-title">{{ $section['title'] }}</h3>

                <div class="fi-wi-qa-grid">
                    @foreach($section['links'] as $link)
                        <a href="{{ $link['url'] }}" class="fi-wi-qa-card fi-wi-qa-card--{{ $link['color'] }}">

                            @if(!empty($link['badge']))
                                <span class="fi-wi-qa-badge fi-wi-qa-badge--{{ $link['badgeColor'] ?? 'primary' }}">
                                    {{ $link['badge'] }}
                                </span>
                            @endif

                            <div class="fi-wi-qa-icon fi-wi-qa-icon--{{ $link['color'] }}">
                                <x-filament::icon
                                    :icon="$link['icon']"
                                    class="fi-wi-qa-icon-svg"
                                />
                            </div>

                            <span class="fi-wi-qa-label">{{ $link['label'] }}</span>
                            <span class="fi-wi-qa-sub">{{ $link['sub'] }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach

    </div>
</x-filament-widgets::widget>
