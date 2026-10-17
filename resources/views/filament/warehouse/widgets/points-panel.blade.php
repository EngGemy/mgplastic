@php
    $panel = $panel ?? [];
    $cards = $panel['cards'] ?? [];
    $stock = $panel['stock'] ?? [];
    $network = $panel['network'] ?? [];
    $pctDist = (int) ($panel['percent_distributed'] ?? 0);
    $pctRem = (int) ($panel['percent_remaining'] ?? 0);
@endphp

<x-filament-widgets::widget>
    <div style="
        direction: rtl;
        font-family: Cairo, sans-serif;
        background: linear-gradient(135deg, #0f766e 0%, #134e4a 55%, #0f172a 100%);
        border-radius: 18px;
        padding: 22px 24px;
        color: #fff;
        box-shadow: 0 12px 40px rgba(15, 118, 110, 0.28);
    ">
        <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-start;margin-bottom:18px">
            <div>
                <div style="font-size:13px;opacity:.85;font-weight:600;margin-bottom:4px">لوحة رصيد النقاط</div>
                <div style="font-size:22px;font-weight:800;line-height:1.3">{{ $storeName ?? 'مخزن الدعوة' }}</div>
                @if(filled($networkCode ?? null))
                    <div style="margin-top:8px;display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.12);border-radius:999px;padding:4px 12px;font-size:12px;font-weight:700">
                        الرقم الموحّد: {{ $networkCode }}
                    </div>
                @endif
            </div>
            <div style="text-align:left">
                <div style="font-size:12px;opacity:.8">النقاط المتبقية</div>
                <div style="font-size:34px;font-weight:900;letter-spacing:-1px">
                    {{ number_format((int) ($panel['remaining_points'] ?? 0)) }}
                </div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:16px">
            @foreach($cards as $card)
                @php
                    $bg = match ($card['color'] ?? 'primary') {
                        'success' => 'rgba(16,185,129,.22)',
                        'warning' => 'rgba(245,158,11,.22)',
                        default => 'rgba(255,255,255,.12)',
                    };
                @endphp
                <div style="background:{{ $bg }};border:1px solid rgba(255,255,255,.14);border-radius:14px;padding:14px 16px">
                    <div style="font-size:12px;opacity:.85;font-weight:600;margin-bottom:6px">{{ $card['label'] ?? '' }}</div>
                    <div style="font-size:26px;font-weight:800">{{ number_format((int) ($card['value'] ?? 0)) }}</div>
                </div>
            @endforeach
        </div>

        <div style="margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;font-size:12px;font-weight:700;margin-bottom:6px">
                <span>موزّع {{ $pctDist }}%</span>
                <span>متبقي {{ $pctRem }}%</span>
            </div>
            <div style="height:10px;background:rgba(255,255,255,.15);border-radius:999px;overflow:hidden;display:flex">
                <div style="width:{{ max(0, min(100, $pctDist)) }}%;background:#f59e0b"></div>
                <div style="width:{{ max(0, min(100, $pctRem)) }}%;background:#34d399"></div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px">
            <div style="background:rgba(255,255,255,.08);border-radius:12px;padding:12px">
                <div style="font-size:11px;opacity:.75">أصناف المخزون</div>
                <div style="font-size:18px;font-weight:800">{{ number_format((int) ($stock['products'] ?? 0)) }}</div>
            </div>
            <div style="background:rgba(255,255,255,.08);border-radius:12px;padding:12px">
                <div style="font-size:11px;opacity:.75">الوحدات</div>
                <div style="font-size:18px;font-weight:800">{{ number_format((int) ($stock['units'] ?? 0)) }}</div>
            </div>
            <div style="background:rgba(255,255,255,.08);border-radius:12px;padding:12px">
                <div style="font-size:11px;opacity:.75">نقاط في المخزون</div>
                <div style="font-size:18px;font-weight:800">{{ number_format((int) ($stock['points_in_stock'] ?? 0)) }}</div>
            </div>
            <div style="background:rgba(255,255,255,.08);border-radius:12px;padding:12px">
                <div style="font-size:11px;opacity:.75">تجار قطاعي</div>
                <div style="font-size:18px;font-weight:800">{{ number_format((int) ($network['retail_traders_count'] ?? 0)) }}</div>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>

<style>
@media (max-width: 900px) {
    .fi-wi-warehouse-points-panel div[style*="grid-template-columns:repeat(3"] {
        grid-template-columns: 1fr !important;
    }
}
</style>
