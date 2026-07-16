@php
    $record = $getRecord();
    $isWholesale = $record->isWholesalePos();
    $totalQty = (int) $record->items->sum('quantity');
    $totalPoints = (int) ($record->points_awarded ?: $record->items->sum('total_points'));

    $statusLabel = match ($record->status) {
        'approved' => 'معتمدة',
        'pending_review' => 'قيد المراجعة',
        'rejected' => 'مرفوضة',
        default => $record->status,
    };
    $statusClass = match ($record->status) {
        'approved' => 'green',
        'pending_review' => 'amber',
        'rejected' => 'red',
        default => 'gray',
    };
@endphp

<div class="inv-profile" dir="rtl">
    <div class="inv-profile-top">
        <div class="inv-profile-main">
            <span class="inv-profile-type">
                {{ $isWholesale ? '🧾 فاتورة جملة POS' : '📄 إيصال سباك' }}
            </span>
            <h2 class="inv-profile-number">{{ $record->number }}</h2>
            <p class="inv-profile-serial">الرقم الأساسي: <strong>{{ $record->serial_number }}</strong></p>
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
            <div class="net-stat net-stat--{{ $statusClass }}">
                <span class="net-stat-num" style="font-size:0.85rem">{{ $statusLabel }}</span>
                <span class="net-stat-label">الحالة</span>
            </div>
        </div>
    </div>
    <div class="inv-profile-meta">
        @if($isWholesale && $record->wholesaleDistributor)
            <span>🏪 {{ $record->wholesaleDistributor->name }}</span>
        @endif
        @if(!$isWholesale && $record->plumber)
            <span>🔧 {{ $record->plumber->name }}</span>
        @endif
        @if($record->issuer)
            <span>👤 أصدرها: {{ $record->issuer->name }}</span>
        @endif
        <span>📅 {{ $record->created_at?->timezone('Africa/Tripoli')->format('Y/m/d H:i') }}</span>
    </div>
</div>
