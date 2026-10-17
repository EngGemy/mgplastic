@php
    $totals = $report['totals'] ?? [];
    $topCustomers = $report['top_customers'] ?? [];
    $topProducts = $report['top_products'] ?? [];
    $daily = $report['daily'] ?? [];
    $warehouseId = ! empty($data['warehouse_id']) ? (int) $data['warehouse_id'] : null;

    $cards = [
        ['label' => 'طلبات صادرة', 'value' => $totals['orders_placed'] ?? 0, 'color' => '#0f766e'],
        ['label' => 'طلبات واردة', 'value' => $totals['orders_incoming'] ?? 0, 'color' => '#0891b2'],
        ['label' => 'مُسلَّمة', 'value' => $totals['orders_delivered'] ?? 0, 'color' => '#059669'],
        ['label' => 'كمية المبيعات', 'value' => $totals['sales_qty'] ?? 0, 'color' => '#d97706'],
        ['label' => 'نقاط المبيعات', 'value' => $totals['sales_points'] ?? 0, 'color' => '#7c3aed'],
        ['label' => 'مرتجعات معلّقة', 'value' => $totals['returns_pending'] ?? 0, 'color' => '#dc2626'],
        ['label' => 'مرتجعات مقبولة', 'value' => $totals['returns_approved'] ?? 0, 'color' => '#64748b'],
    ];
@endphp

<x-filament::page>
    <div dir="rtl" style="font-family:Cairo,sans-serif;display:grid;gap:1.25rem">
        <form wire:submit="applyFilters" style="
            background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:14px;padding:1rem 1.15rem;
            box-shadow:0 1px 2px rgba(15,23,42,.04);
        ">
            {{ $this->form }}
            <div style="margin-top:1rem;display:flex;gap:.75rem;flex-wrap:wrap">
                <x-filament::button type="submit" color="primary">
                    عرض التقرير
                </x-filament::button>
                @if($warehouseId)
                    <x-filament::button
                        tag="a"
                        color="gray"
                        :href="\App\Filament\Warehouse\Pages\WarehouseReports::getUrl()"
                    >
                        العودة للتقرير الشامل
                    </x-filament::button>
                @endif
            </div>
        </form>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem">
            @foreach($cards as $card)
                <div style="
                    background:#fff;border-radius:12px;padding:1rem;
                    border-top:3px solid {{ $card['color'] }};
                    border:1px solid rgba(15,23,42,.06);border-top:3px solid {{ $card['color'] }};
                ">
                    <div style="font-size:1.4rem;font-weight:800;color:#0f172a;line-height:1.2">
                        {{ number_format((float) $card['value']) }}
                    </div>
                    <div style="font-size:.8rem;color:#64748b;margin-top:.35rem;font-weight:600">
                        {{ $card['label'] }}
                    </div>
                </div>
            @endforeach
        </div>

        @if(! $warehouseId && count($networkWarehouses))
            <div style="background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:14px;padding:1rem 1.15rem;overflow-x:auto">
                <div style="font-weight:800;font-size:1rem;margin-bottom:.85rem;color:#0f172a">
                    تقارير مخازن الجملة (لكل مخزن)
                </div>
                <table style="width:100%;border-collapse:collapse;font-size:.875rem;min-width:720px">
                    <thead>
                        <tr style="background:#f8fafc;text-align:right">
                            <th style="padding:.65rem;border-bottom:1px solid #e2e8f0">المخزن</th>
                            <th style="padding:.65rem;border-bottom:1px solid #e2e8f0">قطاعي</th>
                            <th style="padding:.65rem;border-bottom:1px solid #e2e8f0">طلبات واردة</th>
                            <th style="padding:.65rem;border-bottom:1px solid #e2e8f0">طلبات صادرة</th>
                            <th style="padding:.65rem;border-bottom:1px solid #e2e8f0">كمية</th>
                            <th style="padding:.65rem;border-bottom:1px solid #e2e8f0">نقاط</th>
                            <th style="padding:.65rem;border-bottom:1px solid #e2e8f0">مرتجعات</th>
                            <th style="padding:.65rem;border-bottom:1px solid #e2e8f0"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($networkWarehouses as $row)
                            <tr style="border-bottom:1px solid #f1f5f9">
                                <td style="padding:.65rem;font-weight:700">
                                    {{ $row['name'] }}
                                    @if(!empty($row['network_code']))
                                        <div style="font-size:.75rem;color:#64748b;font-weight:500">{{ $row['network_code'] }}</div>
                                    @endif
                                </td>
                                <td style="padding:.65rem">{{ number_format($row['retail_count']) }}</td>
                                <td style="padding:.65rem">{{ number_format($row['orders_incoming']) }}</td>
                                <td style="padding:.65rem">{{ number_format($row['orders_placed']) }}</td>
                                <td style="padding:.65rem">{{ number_format($row['sales_qty']) }}</td>
                                <td style="padding:.65rem">{{ number_format($row['sales_points']) }}</td>
                                <td style="padding:.65rem">
                                    @if(($row['returns_pending'] ?? 0) > 0)
                                        <span style="color:#dc2626;font-weight:700">{{ $row['returns_pending'] }}</span>
                                    @else
                                        0
                                    @endif
                                </td>
                                <td style="padding:.65rem">
                                    <a href="{{ \App\Filament\Warehouse\Pages\WarehouseReports::getUrl().'?warehouse_id='.$row['id'].'&from='.urlencode($data['from'] ?? '').'&to='.urlencode($data['to'] ?? '') }}"
                                       style="color:#0f766e;font-weight:700;text-decoration:none">
                                        تفاصيل ←
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem">
            <div style="background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:14px;padding:1rem 1.15rem">
                <div style="font-weight:800;margin-bottom:.75rem">أعلى العملاء</div>
                @forelse($topCustomers as $c)
                    <div style="display:flex;justify-content:space-between;gap:.5rem;padding:.45rem 0;border-bottom:1px solid #f1f5f9">
                        <span style="font-weight:600">{{ $c['name'] ?? '—' }}</span>
                        <span style="color:#64748b;font-size:.85rem">
                            {{ number_format($c['orders_count'] ?? 0) }} طلب · {{ number_format($c['points'] ?? 0) }} نقطة
                        </span>
                    </div>
                @empty
                    <div style="color:#94a3b8;font-size:.875rem">لا بيانات في الفترة</div>
                @endforelse
            </div>

            <div style="background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:14px;padding:1rem 1.15rem">
                <div style="font-weight:800;margin-bottom:.75rem">أعلى المنتجات</div>
                @forelse($topProducts as $p)
                    <div style="display:flex;justify-content:space-between;gap:.5rem;padding:.45rem 0;border-bottom:1px solid #f1f5f9">
                        <span style="font-weight:600">{{ $p['name'] ?? '—' }}</span>
                        <span style="color:#64748b;font-size:.85rem">
                            {{ number_format($p['quantity'] ?? 0) }} · {{ number_format($p['points'] ?? 0) }} نقطة
                        </span>
                    </div>
                @empty
                    <div style="color:#94a3b8;font-size:.875rem">لا بيانات في الفترة</div>
                @endforelse
            </div>
        </div>

        @if(count($daily))
            <div style="background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:14px;padding:1rem 1.15rem;overflow-x:auto">
                <div style="font-weight:800;margin-bottom:.75rem">اليومي (آخر أيام الفترة)</div>
                <table style="width:100%;border-collapse:collapse;font-size:.8rem;min-width:480px">
                    <thead>
                        <tr style="background:#f8fafc;text-align:right">
                            <th style="padding:.5rem;border-bottom:1px solid #e2e8f0">اليوم</th>
                            <th style="padding:.5rem;border-bottom:1px solid #e2e8f0">صادرة</th>
                            <th style="padding:.5rem;border-bottom:1px solid #e2e8f0">واردة</th>
                            <th style="padding:.5rem;border-bottom:1px solid #e2e8f0">نقاط</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(array_slice(array_reverse($daily), 0, 14) as $day)
                            <tr>
                                <td style="padding:.45rem;border-bottom:1px solid #f1f5f9">{{ $day['date'] ?? '—' }}</td>
                                <td style="padding:.45rem;border-bottom:1px solid #f1f5f9">{{ number_format($day['orders_placed'] ?? 0) }}</td>
                                <td style="padding:.45rem;border-bottom:1px solid #f1f5f9">{{ number_format($day['orders_incoming'] ?? 0) }}</td>
                                <td style="padding:.45rem;border-bottom:1px solid #f1f5f9">{{ number_format($day['sales_points'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament::page>
