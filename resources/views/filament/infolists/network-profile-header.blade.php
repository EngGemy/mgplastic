@php
    $record = $getRecord();
    $isWholesale = $record->role === 'wholesale_distributor';
    $isRetail = $record->role === 'retail_trader';

    $retailCount = $isWholesale ? (int) ($record->retail_traders_count ?? $record->retailTraders()->count()) : 0;
    $plumberCount = $isWholesale
        ? \App\Models\User::where('role', 'plumber')
            ->whereIn('parent_distributor_id', $record->retailTraders()->pluck('id'))
            ->count()
        : (int) ($record->plumbers_count ?? $record->plumbers()->count());

    $photoUrl = $record->profile_photo
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($record->profile_photo)
        : null;
@endphp

<div class="net-profile" dir="rtl">
    <div class="net-profile-top">
        <div class="net-profile-identity">
            @if($photoUrl)
                <img src="{{ $photoUrl }}" alt="" class="net-profile-avatar">
            @else
                <div class="net-profile-avatar net-profile-avatar--empty">
                    {{ mb_substr($record->name, 0, 1) }}
                </div>
            @endif
            <div class="net-profile-text">
                <span class="net-profile-badge">
                    @if($isWholesale) 🏪 متجر — موزع جملة
                    @elseif($isRetail && $record->is_independent) 🏬 موزع قطاعي منفرد
                    @else 🏬 موزع قطاعي
                    @endif
                </span>
                <h2 class="net-profile-name">{{ $record->name }}</h2>
                @if($isRetail && $record->parentDistributor)
                    <p class="net-profile-parent">تابع لـ: <strong>{{ $record->parentDistributor->name }}</strong></p>
                @endif
            </div>
        </div>

        <div class="net-profile-stats">
            @if($isWholesale)
                <div class="net-stat net-stat--blue">
                    <span class="net-stat-num">{{ number_format($retailCount) }}</span>
                    <span class="net-stat-label">موزع قطاعي</span>
                </div>
                <div class="net-stat net-stat--amber">
                    <span class="net-stat-num">{{ number_format($plumberCount) }}</span>
                    <span class="net-stat-label">سباك</span>
                </div>
            @else
                <div class="net-stat net-stat--amber">
                    <span class="net-stat-num">{{ number_format($plumberCount) }}</span>
                    <span class="net-stat-label">سباك تابع</span>
                </div>
            @endif
            <div class="net-stat net-stat--{{ $record->is_active ? 'green' : 'gray' }}">
                <span class="net-stat-num">{{ $record->is_active ? '✓' : '✗' }}</span>
                <span class="net-stat-label">{{ $record->is_active ? 'نشط' : 'موقوف' }}</span>
            </div>
        </div>
    </div>
</div>
