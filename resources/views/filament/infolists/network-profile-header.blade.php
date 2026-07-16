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
                @if(filled($record->network_code))
                    <div style="margin:6px 0 4px;display:inline-flex;align-items:center;gap:8px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:4px 10px">
                        <span style="font-size:11px;font-weight:700;color:#64748b">الرقم الموحّد</span>
                        <strong style="font-size:1rem;color:#1a56db;letter-spacing:.04em;font-family:ui-monospace,monospace">{{ $record->network_code }}</strong>
                    </div>
                @endif
                <h2 class="net-profile-name">{{ $record->name }}</h2>
                @if($isRetail && $record->parentDistributor)
                    <p class="net-profile-parent">موزّع أساسي: <strong>{{ $record->parentDistributor->name }}</strong></p>
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
