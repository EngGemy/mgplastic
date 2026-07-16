@php
    $record = $getRecord();
    $isWholesale = $record->isWholesalePos();
    $summary = $record->returnSummary();
    $hasReturns = $summary['returns_count'] > 0;

    $totalQty = $hasReturns ? $summary['net_qty'] : (int) (
        $record->isOutgoing() && $record->sourceDistribution
            ? $record->sourceDistribution->items->sum(fn ($i) => $i->quantity - ($i->returned_quantity ?? 0))
            : $record->items->sum('quantity')
    );

    $totalPoints = $hasReturns
        ? $summary['net_points']
        : (int) ($record->points_awarded ?: $record->items->sum('total_points') ?: ($record->sourceDistribution?->items->sum('points_value') ?? 0));

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

    $flowLabel = $record->isOutgoing() ? 'صادر' : 'وارد';
@endphp

<div class="inv-profile" dir="rtl">
    <div class="inv-profile-top">
        <div class="inv-profile-main">
            <span class="inv-profile-type">
                @if($isWholesale)
                    {{ $record->isOutgoing() ? '📤 فاتورة صادر — جملة → قطاعي' : '📥 فاتورة وارد — مصنع → جملة' }}
                @else
                    📄 إيصال سباك
                @endif
            </span>
            <h2 class="inv-profile-number">{{ $record->number }}</h2>
            <p class="inv-profile-serial">الرقم الأساسي: <strong>{{ $record->serial_number }}</strong>
                · الاتجاه: <strong>{{ $flowLabel }}</strong>
            </p>
        </div>
        <div class="inv-profile-stats">
            <div class="net-stat net-stat--blue">
                <span class="net-stat-num">{{ number_format($totalQty) }}</span>
                <span class="net-stat-label">{{ $hasReturns ? 'صافي الوحدات' : 'وحدة' }}</span>
            </div>
            <div class="net-stat net-stat--amber">
                <span class="net-stat-num">{{ number_format($totalPoints) }}</span>
                <span class="net-stat-label">{{ $hasReturns ? 'صافي النقاط' : 'نقطة' }}</span>
            </div>
            @if($hasReturns)
                <div class="net-stat net-stat--red">
                    <span class="net-stat-num">−{{ number_format($summary['returned_qty']) }}</span>
                    <span class="net-stat-label">مرتجع وحدات</span>
                </div>
                <div class="net-stat net-stat--red">
                    <span class="net-stat-num">−{{ number_format($summary['returned_points']) }}</span>
                    <span class="net-stat-label">مرتجع نقاط</span>
                </div>
            @endif
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
        @if($record->counterparty)
            <span>👤 الطرف: {{ $record->counterparty->name }}</span>
        @endif
        @if(!$isWholesale && $record->plumber)
            <span>🔧 {{ $record->plumber->name }}</span>
        @endif
        @if($record->issuer)
            <span>✍️ أصدرها: {{ $record->issuer->name }}</span>
        @endif
        <span>📅 {{ $record->created_at?->timezone('Africa/Tripoli')->format('Y/m/d H:i') }}</span>
        @if($hasReturns)
            <span style="color:#b91c1c;font-weight:700">↩ {{ $summary['returns_count'] }} مرتجع · −{{ number_format($summary['returned_qty']) }} وحدة</span>
        @endif
    </div>
</div>
