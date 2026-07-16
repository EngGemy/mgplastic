@php
    /** @var \App\Models\Invoice $record */
    $record = $getRecord();
    $record->loadMissing([
        'returns.items.product.translations',
        'returns.fromUser',
        'returns.toUser',
        'sourceDistribution.items.invoiceItem.product.translations',
    ]);
    $summary = $record->returnSummary();
    $returns = $record->returns->where('status', 'confirmed');
    $dist = $record->sourceDistribution;
@endphp

<div class="inv-returns" dir="rtl" style="font-family:'Cairo',sans-serif">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:14px">
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:12px;text-align:center">
            <div style="font-size:1.25rem;font-weight:900;color:#1d4ed8">{{ number_format($summary['sold_qty']) }}</div>
            <div style="font-size:11px;color:#64748b;font-weight:700">كمية الفاتورة</div>
        </div>
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:12px;text-align:center">
            <div style="font-size:1.25rem;font-weight:900;color:#dc2626">−{{ number_format($summary['returned_qty']) }}</div>
            <div style="font-size:11px;color:#64748b;font-weight:700">مرتجع وحدات</div>
        </div>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:12px;text-align:center">
            <div style="font-size:1.25rem;font-weight:900;color:#059669">{{ number_format($summary['net_qty']) }}</div>
            <div style="font-size:11px;color:#64748b;font-weight:700">صافي الوحدات</div>
        </div>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:12px;text-align:center">
            <div style="font-size:1.25rem;font-weight:900;color:#d97706">{{ number_format($summary['sold_points']) }}</div>
            <div style="font-size:11px;color:#64748b;font-weight:700">نقاط الفاتورة</div>
        </div>
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:12px;text-align:center">
            <div style="font-size:1.25rem;font-weight:900;color:#dc2626">−{{ number_format($summary['returned_points']) }}</div>
            <div style="font-size:11px;color:#64748b;font-weight:700">مرتجع نقاط</div>
        </div>
        <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:12px;padding:12px;text-align:center">
            <div style="font-size:1.25rem;font-weight:900;color:#047857">{{ number_format($summary['net_points']) }}</div>
            <div style="font-size:11px;color:#64748b;font-weight:700">صافي النقاط</div>
        </div>
    </div>

    @if($dist && $dist->items->isNotEmpty())
        <div style="overflow:auto;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:14px">
            <table style="width:100%;border-collapse:collapse;font-size:13px">
                <thead>
                    <tr style="background:#f8fafc;color:#475569">
                        <th style="padding:10px;text-align:right">المنتج</th>
                        <th style="padding:10px;text-align:center">مباع</th>
                        <th style="padding:10px;text-align:center">مرتجع</th>
                        <th style="padding:10px;text-align:center">صافي</th>
                        <th style="padding:10px;text-align:center">نقاط صافية</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($dist->items as $item)
                        @php
                            $sold = (int) $item->quantity;
                            $ret = (int) ($item->returned_quantity ?? 0);
                            $net = max(0, $sold - $ret);
                            $ppu = (float) ($item->invoiceItem?->points_per_unit ?? 0);
                            $netPts = (int) floor($net * $ppu);
                        @endphp
                        <tr style="border-top:1px solid #f1f5f9">
                            <td style="padding:10px;font-weight:700">{{ localized_name($item->invoiceItem?->product, 'name', 'منتج') }}</td>
                            <td style="padding:10px;text-align:center">{{ number_format($sold) }}</td>
                            <td style="padding:10px;text-align:center;color:#dc2626;font-weight:700">{{ $ret > 0 ? '−'.number_format($ret) : '0' }}</td>
                            <td style="padding:10px;text-align:center;font-weight:800">{{ number_format($net) }}</td>
                            <td style="padding:10px;text-align:center;color:#d97706;font-weight:700">{{ number_format($netPts) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($returns->isEmpty())
        <p style="text-align:center;color:#94a3b8;font-size:13px;padding:12px 0;margin:0">لا توجد مرتجعات على هذه الفاتورة بعد</p>
    @else
        <div style="display:flex;flex-direction:column;gap:10px">
            @foreach($returns as $ret)
                <div style="border:1.5px solid #fecaca;background:#fff7f7;border-radius:12px;padding:12px 14px">
                    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
                        <div>
                            <div style="font-weight:900;color:#b91c1c">{{ $ret->return_number }}</div>
                            <div style="font-size:12px;color:#64748b;margin-top:2px">
                                من {{ $ret->fromUser?->name ?? '—' }} → إلى {{ $ret->toUser?->name ?? '—' }}
                                · {{ $ret->confirmed_at?->timezone('Africa/Tripoli')->format('Y/m/d H:i') }}
                            </div>
                        </div>
                        <div style="text-align:left">
                            <span style="display:inline-block;background:#fee2e2;color:#991b1b;font-weight:800;font-size:12px;padding:4px 10px;border-radius:999px">
                                −{{ number_format($ret->total_quantity) }} وحدة
                            </span>
                            <span style="display:inline-block;background:#ffedd5;color:#9a3412;font-weight:800;font-size:12px;padding:4px 10px;border-radius:999px;margin-right:6px">
                                −{{ number_format($ret->total_points) }} نقطة
                            </span>
                        </div>
                    </div>
                    @if($ret->items->isNotEmpty())
                        <ul style="margin:8px 0 0;padding:0 18px 0 0;font-size:12px;color:#475569">
                            @foreach($ret->items as $ri)
                                <li>
                                    {{ localized_name($ri->product, 'name', 'منتج') }}
                                    — {{ number_format($ri->quantity) }} وحدة
                                    ({{ number_format($ri->points_value) }} نقطة)
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if(filled($ret->note))
                        <p style="margin:8px 0 0;font-size:12px;color:#64748b">ملاحظة: {{ $ret->note }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
