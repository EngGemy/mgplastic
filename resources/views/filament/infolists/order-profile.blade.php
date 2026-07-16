@php
    use App\Support\OrderStatus;
    use App\Services\OrderService;

    $order = $getRecord();
    $order->loadMissing(['items.product', 'requester', 'supplier']);
    $timeline = OrderStatus::timeline();
    $current = $order->status;
    $currentIdx = array_search($current, $timeline, true);
    if ($currentIdx === false) {
        $currentIdx = OrderStatus::isFinal($current) ? count($timeline) : -1;
    }

    $stockMap = collect();
    $showStock = $order->isPlumberChannel()
        && auth()->check()
        && (int) auth()->id() === (int) $order->supplier_id
        && ! OrderStatus::isFinal($order->status);

    if ($showStock) {
        $stockMap = collect(app(OrderService::class)->stockAvailability($order))->keyBy('product_id');
    }
@endphp

<div class="mg-ord" dir="rtl">
<style>
.mg-ord{font-family:'Cairo',sans-serif;color:#0f172a}
.mg-ord-hero{background:linear-gradient(125deg,#0f3d91,#1a56db);color:#fff;border-radius:18px;padding:22px 24px;margin-bottom:18px;display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:center}
.mg-ord-num{font-size:1.35rem;font-weight:900}
.mg-ord-meta{font-size:12px;opacity:.85;margin-top:4px}
.mg-ord-badge{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:999px;background:rgba(255,255,255,.18);font-weight:700;font-size:13px}
.mg-ord-steps{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap}
.mg-ord-step{flex:1;min-width:120px;background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:12px;text-align:center}
.mg-ord-step.done{border-color:#059669;background:#ecfdf5}
.mg-ord-step.current{border-color:#1a56db;background:#eff6ff;box-shadow:0 0 0 3px rgba(26,86,219,.12)}
.mg-ord-step.muted{opacity:.55}
.mg-ord-step-lbl{font-size:12px;font-weight:700}
.mg-ord-step-sub{font-size:10px;color:#64748b;margin-top:3px}
.mg-ord-grid{display:grid;grid-template-columns:1.4fr .9fr;gap:16px}
@media(max-width:900px){.mg-ord-grid{grid-template-columns:1fr}}
.mg-ord-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:16px;padding:16px}
.mg-ord-card h3{font-size:14px;font-weight:800;margin:0 0 14px;display:flex;align-items:center;gap:8px}
.mg-ord-item{display:flex;gap:12px;align-items:center;padding:10px 0;border-bottom:1px solid #f1f5f9}
.mg-ord-item:last-child{border-bottom:none}
.mg-ord-img{width:56px;height:56px;object-fit:cover;border-radius:10px;background:#f1f5f9;flex-shrink:0}
.mg-ord-img-ph{width:56px;height:56px;border-radius:10px;background:#1a56db;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;flex-shrink:0}
.mg-ord-iname{font-size:13px;font-weight:700}
.mg-ord-isub{font-size:11px;color:#64748b;margin-top:2px}
.mg-ord-iqty{font-size:12px;font-weight:800;background:#f1f5f9;padding:4px 10px;border-radius:999px}
.mg-ord-kv{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.mg-ord-k{font-size:10px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.mg-ord-v{font-size:13px;font-weight:700;margin-top:2px}
.mg-ord-stock-ok{color:#059669;font-weight:700;font-size:11px}
.mg-ord-stock-bad{color:#dc2626;font-weight:700;font-size:11px}
.mg-ord-tip{background:#fffbeb;border:1px solid #fcd34d;border-radius:12px;padding:12px 14px;margin-bottom:16px;font-size:12px;color:#92400e;line-height:1.6}
</style>

<div class="mg-ord-hero">
    <div>
        <div class="mg-ord-num">طلب {{ $order->order_number }}</div>
        <div class="mg-ord-meta">{{ OrderStatus::channelLabel($order->channel) }} · {{ $order->total_quantity }} وحدة · {{ number_format($order->total_points) }} نقطة</div>
    </div>
    <div class="mg-ord-badge">{{ OrderStatus::label($order->status) }}</div>
</div>

@if($showStock)
<div class="mg-ord-tip">
    السباك يطلب أي منتج حتى لو مش عندك — للتنفيذ لازم الأصناف تكون متوفرة في مخزونك.
    استخدم «تطبيق المتوفر فقط» أو «تعديل الأصناف»، ثم «تنفيذ وتحويل لفاتورة».
</div>
@endif

@if(! OrderStatus::isFinal($current) || $current === OrderStatus::DELIVERED)
<div class="mg-ord-steps">
    @foreach($timeline as $i => $step)
        @php
            $cls = 'muted';
            if ($current === OrderStatus::DELIVERED || ($currentIdx !== false && $i < $currentIdx)) $cls = 'done';
            if ($step === $current) $cls = 'current';
            if (in_array($current, [OrderStatus::CANCELLED, OrderStatus::REJECTED], true)) $cls = 'muted';
        @endphp
        <div class="mg-ord-step {{ $cls }}">
            <div class="mg-ord-step-lbl">{{ OrderStatus::label($step) }}</div>
            <div class="mg-ord-step-sub">{{ OrderStatus::description($step) }}</div>
        </div>
    @endforeach
</div>
@endif

<div class="mg-ord-grid">
    <div class="mg-ord-card">
        <h3>🛒 أصناف الطلب</h3>
        @forelse($order->items as $item)
            @php
                $img = $item->product?->display_image_url;
                $name = $item->name_snapshot ?: localized_name($item->product, 'name', 'منتج');
                $stock = $stockMap->get((int) $item->product_id);
            @endphp
            <div class="mg-ord-item">
                @if($img)
                    <img src="{{ $img }}" class="mg-ord-img" alt="">
                @else
                    <div class="mg-ord-img-ph">{{ mb_substr($name, 0, 1) }}</div>
                @endif
                <div style="flex:1;min-width:0">
                    <div class="mg-ord-iname">{{ $name }}</div>
                    <div class="mg-ord-isub">{{ rtrim(rtrim(number_format((float)$item->points_per_unit, 2), '0'), '.') }} نقطة / وحدة · إجمالي {{ number_format($item->line_points) }} نقطة</div>
                    @if($stock)
                        <div class="{{ $stock['is_available'] ? 'mg-ord-stock-ok' : 'mg-ord-stock-bad' }}">
                            @if($stock['is_available'])
                                ✓ متوفر ({{ $stock['available_qty'] }} في المخزن)
                            @else
                                ✕ غير كافٍ — مطلوب {{ $stock['requested_qty'] }} / متوفر {{ $stock['available_qty'] }}
                            @endif
                        </div>
                    @endif
                </div>
                <div class="mg-ord-iqty">× {{ $item->quantity }}</div>
            </div>
        @empty
            <div style="color:#94a3b8;font-size:13px;padding:12px 0">لا توجد أصناف</div>
        @endforelse
    </div>

    <div class="mg-ord-card">
        <h3>📦 التفاصيل</h3>
        <div class="mg-ord-kv">
            <div><div class="mg-ord-k">الطالب</div><div class="mg-ord-v">{{ $order->requester?->name ?? '—' }}</div></div>
            <div><div class="mg-ord-k">المورّد</div><div class="mg-ord-v">{{ $order->supplier?->name ?? 'المصنع' }}</div></div>
            <div><div class="mg-ord-k">شركة الشحن</div><div class="mg-ord-v">{{ $order->carrier_name ?: '—' }}</div></div>
            <div><div class="mg-ord-k">رقم التتبّع</div><div class="mg-ord-v">{{ $order->tracking_number ?: '—' }}</div></div>
            <div><div class="mg-ord-k">التسليم المتوقع</div><div class="mg-ord-v">{{ optional($order->expected_delivery_at)->format('Y/m/d') ?: '—' }}</div></div>
            <div><div class="mg-ord-k">تاريخ الطلب</div><div class="mg-ord-v">{{ optional($order->placed_at ?? $order->created_at)->format('Y/m/d H:i') ?: '—' }}</div></div>
        </div>
        @if($order->note)
            <div style="margin-top:14px"><div class="mg-ord-k">ملاحظة الطالب</div><div class="mg-ord-v" style="font-weight:500;color:#475569">{{ $order->note }}</div></div>
        @endif
        @if($order->supplier_note)
            <div style="margin-top:10px"><div class="mg-ord-k">ملاحظة المورّد</div><div class="mg-ord-v" style="font-weight:500;color:#475569">{{ $order->supplier_note }}</div></div>
        @endif
    </div>
</div>
</div>
