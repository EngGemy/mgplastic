@php
    // Filament ViewEntry passes $getRecord as a Closure — must call it.
    $item = is_callable($getRecord ?? null) ? $getRecord() : ($getRecord ?? null);
    $hint = ($item instanceof \App\Models\InvoiceItem)
        ? app(\App\Services\Points\PointsRateHintBuilder::class)
            ->forInvoiceItem($item, includeRates: true)
        : null;
@endphp

@if ($hint)
    <div class="mg-points-hint mg-points-hint--{{ $hint['tone'] ?? 'info' }}" role="status">
        <div class="mg-points-hint__rail" aria-hidden="true"></div>
        <div class="mg-points-hint__body">
            <span class="mg-points-hint__kicker">تنبيه سعر النقاط · المصنع</span>
            <strong class="mg-points-hint__title">{{ $hint['title_ar'] }}</strong>
            <p class="mg-points-hint__msg">{{ $hint['message_ar'] }}</p>
            <div class="mg-points-hint__meta">
                <span>البيع من هذه الدفعة: سعر الاستلام المثبت</span>
                @if (isset($hint['lot_receive_points_per_unit'], $hint['catalog_points_per_unit']))
                    <span class="mg-points-hint__rates">
                        استلام {{ rtrim(rtrim(number_format((float) $hint['lot_receive_points_per_unit'], 4, '.', ''), '0'), '.') }}
                        → كتالوج {{ rtrim(rtrim(number_format((float) $hint['catalog_points_per_unit'], 4, '.', ''), '0'), '.') }}
                    </span>
                @endif
            </div>
        </div>
    </div>
@endif
