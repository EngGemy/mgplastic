@php
    $record = $getRecord();
    $record->loadMissing(['fromUser', 'toUser', 'invoice', 'items.invoiceItem.product']);

    $tierLabel = match ($record->tier) {
        1 => '① مصنع → موزع جملة',
        2 => '② موزع جملة → قطاعي',
        3 => '③ قطاعي → سباك',
        default => '—',
    };

    $statusLabel = match ($record->status) {
        'draft' => 'مسودة',
        'confirmed' => 'مؤكد',
        'points_awarded' => 'نقاط ممنوحة',
        default => $record->status,
    };

    $totalPoints = (int) $record->items->sum('points_value');
    $totalQty = (int) $record->items->sum('quantity');
@endphp

<div class="inv-profile" dir="rtl">
    <div class="inv-profile-top">
        <div class="inv-profile-main">
            <span class="inv-profile-type">🔗 توزيع نقاط — {{ $tierLabel }}</span>
            <h2 class="inv-profile-number">فاتورة {{ $record->invoice?->number ?? '—' }}</h2>
            <p class="inv-profile-serial">
                {{ $record->fromUser?->name }} → <strong>{{ $record->toUser?->name }}</strong>
            </p>
        </div>
        <div class="inv-profile-stats">
            <div class="net-stat net-stat--blue">
                <span class="net-stat-num">{{ number_format($totalQty) }}</span>
                <span class="net-stat-label">وحدة</span>
            </div>
            <div class="net-stat net-stat--amber">
                <span class="net-stat-num">{{ number_format($totalPoints) }}</span>
                <span class="net-stat-label">نقطة</span>
            </div>
            <div class="net-stat net-stat--green">
                <span class="net-stat-num" style="font-size:0.85rem">{{ $statusLabel }}</span>
                <span class="net-stat-label">الحالة</span>
            </div>
        </div>
    </div>
    @if($record->confirmed_at)
        <div class="inv-profile-meta">
            <span>✓ تأكد في {{ $record->confirmed_at->timezone('Africa/Tripoli')->format('Y/m/d H:i') }}</span>
        </div>
    @endif
</div>
