@php
    /** @var \App\Models\Invoice $record */
    $record = $getRecord();
    $percent = $record->distributionPercent();
    $remaining = $record->remainingPointsSum();
@endphp

<div class="mg-inv-progress">
    <div class="mg-inv-progress-meta">
        <span class="mg-inv-progress-done">{{ $percent }}% موزّع</span>
        @if($remaining > 0)
            <span class="mg-inv-progress-left">{{ number_format($remaining) }} متبقي</span>
        @else
            <span class="mg-inv-progress-full">مكتمل</span>
        @endif
    </div>
    <div class="mg-inv-progress-track">
        <div
            class="mg-inv-progress-fill mg-inv-progress-fill--{{ $percent >= 100 ? 'full' : ($percent >= 50 ? 'mid' : 'low') }}"
            style="width: {{ min(100, $percent) }}%"
        ></div>
    </div>
</div>
