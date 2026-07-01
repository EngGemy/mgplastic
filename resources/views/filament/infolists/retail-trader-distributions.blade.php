@php
    use App\Models\InvoiceDistribution;

    $record = $getRecord();
    $trader = $record;

    $distributions = InvoiceDistribution::query()
        ->where('to_user_id', $trader->id)
        ->whereIn('status', ['confirmed', 'points_awarded'])
        ->with([
            'invoice:id,number,serial_number',
            'fromUser:id,name,role',
            'items.invoiceItem.product.translations',
        ])
        ->latest('confirmed_at')
        ->get();

    $totalPoints = $distributions->sum(fn ($d) => $d->items->sum('points_value'));
@endphp

@if($distributions->isEmpty())
    <div style="text-align:center;padding:2rem;color:#94a3b8;font-family:'Cairo',sans-serif;">
        <div style="font-size:2rem;margin-bottom:0.5rem;">📦</div>
        <div style="font-weight:600;">لا توجد توزيعات بعد</div>
        <div style="font-size:0.85rem;margin-top:0.25rem;">ستظهر هنا المنتجات والنقاط عند وصول أول توزيع</div>
    </div>
@else
<div style="font-family:'Cairo',sans-serif;direction:rtl;">

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:1.5rem;">
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 16px;text-align:center;">
            <div style="font-size:1.6rem;font-weight:900;color:#059669;">{{ number_format($totalPoints) }}</div>
            <div style="font-size:0.8rem;color:#065f46;font-weight:600;margin-top:2px;">إجمالي النقاط المستلمة</div>
        </div>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;text-align:center;">
            <div style="font-size:1.6rem;font-weight:900;color:#1d4ed8;">{{ $distributions->count() }}</div>
            <div style="font-size:0.8rem;color:#1e40af;font-weight:600;margin-top:2px;">توزيعات مستلمة</div>
        </div>
        <div style="background:#fefce8;border:1px solid #fde68a;border-radius:10px;padding:14px 16px;text-align:center;">
            <div style="font-size:1.6rem;font-weight:900;color:#d97706;">{{ $distributions->pluck('invoice_id')->unique()->count() }}</div>
            <div style="font-size:0.8rem;color:#92400e;font-weight:600;margin-top:2px;">فواتير مختلفة</div>
        </div>
    </div>

    @foreach($distributions as $dist)
    <div style="border:1px solid #e2e8f0;border-radius:10px;margin-bottom:1rem;overflow:hidden;">

        <div style="background:#f8fafc;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2e8f0;">
            <div style="display:flex;align-items:center;gap:12px;">
                <span style="background:#1a3a6e;color:white;font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;">
                    {{ $dist->invoice->number ?? 'فاتورة #'.$dist->invoice_id }}
                </span>
                <span style="font-size:12px;color:#64748b;">
                    من: <strong style="color:#1e293b;">{{ $dist->fromUser->name }}</strong>
                </span>
                <span style="font-size:11px;color:#94a3b8;">
                    {{ $dist->confirmed_at?->format('Y/m/d') ?? '—' }}
                </span>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <span style="background:{{ $dist->status === 'points_awarded' ? '#d1fae5' : '#fef3c7' }};
                             color:{{ $dist->status === 'points_awarded' ? '#065f46' : '#92400e' }};
                             font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;">
                    {{ $dist->status === 'points_awarded' ? '✓ نقاط ممنوحة' : 'مؤكد' }}
                </span>
                <span style="background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:700;padding:3px 12px;border-radius:999px;">
                    {{ number_format($dist->items->sum('points_value')) }} نقطة
                </span>
            </div>
        </div>

        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="background:#f1f5f9;">
                    <th style="padding:8px 16px;text-align:right;font-weight:700;color:#475569;font-size:11px;">المنتج</th>
                    <th style="padding:8px 12px;text-align:center;font-weight:700;color:#475569;font-size:11px;">الكمية</th>
                    <th style="padding:8px 12px;text-align:center;font-weight:700;color:#475569;font-size:11px;">نقطة/وحدة</th>
                    <th style="padding:8px 16px;text-align:center;font-weight:700;color:#475569;font-size:11px;">إجمالي النقاط</th>
                </tr>
            </thead>
            <tbody>
                @foreach($dist->items as $item)
                @php
                    $product = $item->invoiceItem?->product;
                    $productName = $product?->translate('ar')?->name
                        ?? $product?->translate('en')?->name
                        ?? 'منتج #'.$item->invoiceItem?->product_id;
                    $pointsPerUnit = (float) ($item->invoiceItem?->points_per_unit ?? 0);
                @endphp
                <tr style="border-top:1px solid #f1f5f9;{{ $loop->even ? 'background:#fafafa;' : '' }}">
                    <td style="padding:10px 16px;font-weight:600;color:#1e293b;">
                        {{ $productName }}
                    </td>
                    <td style="padding:10px 12px;text-align:center;">
                        <span style="background:#dbeafe;color:#1d4ed8;font-weight:700;padding:2px 10px;border-radius:999px;font-size:12px;">
                            {{ number_format($item->quantity) }}
                        </span>
                    </td>
                    <td style="padding:10px 12px;text-align:center;color:#64748b;font-size:12px;">
                        {{ $pointsPerUnit }}
                    </td>
                    <td style="padding:10px 16px;text-align:center;">
                        <span style="background:#d1fae5;color:#065f46;font-weight:700;padding:2px 10px;border-radius:999px;font-size:12px;">
                            {{ number_format($item->points_value) }}
                        </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>

    </div>
    @endforeach

</div>
@endif
