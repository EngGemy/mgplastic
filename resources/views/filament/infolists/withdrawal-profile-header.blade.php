@php
    $record = $getRecord();
    $amount = $record->formattedAmount();

    $statusLabel = $record->statusLabel();
    $statusClass = match ($record->status) {
        'pending' => 'amber',
        'paid' => 'green',
        'rejected' => 'red',
        default => 'gray',
    };

    $methodLabel = $record->methodLabel();
@endphp

<div class="inv-profile" dir="rtl">
    <div class="inv-profile-top">
        <div class="inv-profile-main">
            <span class="inv-profile-type">💸 طلب سحب رصيد</span>
            <h2 class="inv-profile-number">{{ $amount }}</h2>
            <p class="inv-profile-serial">
                السباك: <strong>{{ $record->plumber?->name ?? '—' }}</strong>
                · {{ $methodLabel }}
            </p>
        </div>
        <div class="inv-profile-stats">
            <div class="net-stat net-stat--{{ $statusClass }}">
                <span class="net-stat-num" style="font-size:0.85rem">{{ $statusLabel }}</span>
                <span class="net-stat-label">الحالة</span>
            </div>
            <div class="net-stat net-stat--blue">
                <span class="net-stat-num" style="font-size:0.85rem">#{{ $record->id }}</span>
                <span class="net-stat-label">رقم الطلب</span>
            </div>
        </div>
    </div>
    <div class="inv-profile-meta">
        <span>📅 {{ $record->created_at?->timezone('Africa/Tripoli')->format('Y/m/d H:i') }}</span>
        @if($record->paid_at)
            <span>✓ دُفع في {{ $record->paid_at->timezone('Africa/Tripoli')->format('Y/m/d H:i') }}</span>
        @endif
        @if($proof = $record->paymentProofSummary())
            <span>🧾 {{ $proof }}</span>
        @endif
    </div>
</div>
