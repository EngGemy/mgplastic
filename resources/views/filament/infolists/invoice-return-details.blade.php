@php
    /** @var \App\Models\InvoiceReturn $return */
@endphp

<div dir="rtl" style="font-family:'Cairo',sans-serif;padding:4px 2px">
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <span style="background:#fee2e2;color:#991b1b;font-weight:800;font-size:12px;padding:4px 10px;border-radius:999px">
            −{{ number_format($return->total_quantity) }} وحدة
        </span>
        <span style="background:#ffedd5;color:#9a3412;font-weight:800;font-size:12px;padding:4px 10px;border-radius:999px">
            −{{ number_format($return->total_points) }} نقطة
        </span>
        <span style="background:#f1f5f9;color:#475569;font-weight:700;font-size:12px;padding:4px 10px;border-radius:999px">
            {{ $return->confirmed_at?->timezone('Africa/Tripoli')->format('Y/m/d H:i') ?? '—' }}
        </span>
    </div>

    <p style="margin:0 0 10px;font-size:13px;color:#64748b">
        من <strong>{{ $return->fromUser?->name ?? '—' }}</strong>
        → إلى <strong>{{ $return->toUser?->name ?? '—' }}</strong>
    </p>

    @if($return->items->isNotEmpty())
        <div style="overflow:auto;border:1px solid #e2e8f0;border-radius:12px">
            <table style="width:100%;border-collapse:collapse;font-size:13px">
                <thead>
                    <tr style="background:#f8fafc;color:#475569">
                        <th style="padding:10px;text-align:right">المنتج</th>
                        <th style="padding:10px;text-align:center">الكمية</th>
                        <th style="padding:10px;text-align:center">النقاط</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($return->items as $item)
                        <tr style="border-top:1px solid #f1f5f9">
                            <td style="padding:10px;font-weight:700">{{ localized_name($item->product, 'name', 'منتج') }}</td>
                            <td style="padding:10px;text-align:center;color:#dc2626;font-weight:700">−{{ number_format($item->quantity) }}</td>
                            <td style="padding:10px;text-align:center;color:#d97706;font-weight:700">−{{ number_format($item->points_value) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if(filled($return->note))
        <p style="margin:12px 0 0;font-size:13px;color:#64748b">ملاحظة: {{ $return->note }}</p>
    @endif
</div>
