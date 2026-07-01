@php
    /** @var \App\Models\Invoice $invoice */
@endphp

<div class="mg-inv-items-modal">
    <div class="mg-inv-items-summary">
        <span>إجمالي النقاط: <strong>{{ number_format((int) $invoice->items->sum('total_points')) }}</strong></span>
        <span>موزّع: <strong class="text-success">{{ number_format($invoice->distributedPointsSum()) }}</strong></span>
        <span>متبقي: <strong class="text-warning">{{ number_format($invoice->remainingPointsSum()) }}</strong></span>
    </div>

    @if($invoice->items->isEmpty())
        <p class="mg-inv-items-empty">لا توجد بنود في هذه الفاتورة</p>
    @else
        <div class="mg-inv-items-table-wrap">
            <table class="mg-inv-items-table">
                <thead>
                    <tr>
                        <th>المنتج</th>
                        <th>الكمية</th>
                        <th>موزّع</th>
                        <th>متبقي</th>
                        <th>النقاط</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoice->items as $item)
                        @php
                            $productName = $item->product?->translate('ar')?->name
                                ?? $item->product?->translate('en')?->name
                                ?? 'منتج';

                            $distributedQty = (int) $item->distributionItems
                                ->filter(fn ($di) => $di->distribution
                                    && $di->distribution->invoice_id === $invoice->id
                                    && $di->distribution->tier === 1
                                    && in_array($di->distribution->status, ['confirmed', 'points_awarded'], true))
                                ->sum('quantity');

                            $remainingQty = max(0, $item->quantity - $distributedQty);
                        @endphp
                        <tr class="{{ $remainingQty > 0 ? 'mg-inv-items-row--pending' : '' }}">
                            <td class="mg-inv-items-product">{{ $productName }}</td>
                            <td>{{ number_format($item->quantity) }}</td>
                            <td><span class="mg-inv-qty mg-inv-qty--done">{{ number_format($distributedQty) }}</span></td>
                            <td>
                                <span class="mg-inv-qty mg-inv-qty--{{ $remainingQty > 0 ? 'left' : 'zero' }}">
                                    {{ number_format($remainingQty) }}
                                </span>
                            </td>
                            <td>{{ number_format((int) $item->total_points) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
