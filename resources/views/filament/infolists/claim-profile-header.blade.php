@php
    $record = $getRecord();
@endphp

<div class="inv-profile" dir="rtl">
    <div class="inv-profile-top">
        <div class="inv-profile-main">
            <span class="inv-profile-type">📩 شكوى / تواصل</span>
            <h2 class="inv-profile-number">{{ $record->name }}</h2>
            <p class="inv-profile-serial">
                {{ $record->email }}
                @if($record->phone)
                    · {{ $record->phone }}
                @endif
            </p>
        </div>
        <div class="inv-profile-stats">
            <div class="net-stat net-stat--blue">
                <span class="net-stat-num" style="font-size:0.85rem">#{{ $record->id }}</span>
                <span class="net-stat-label">رقم الشكوى</span>
            </div>
        </div>
    </div>
    <div class="inv-profile-meta">
        <span>📅 {{ $record->created_at?->timezone('Africa/Tripoli')->format('Y/m/d H:i') }}</span>
    </div>
</div>
