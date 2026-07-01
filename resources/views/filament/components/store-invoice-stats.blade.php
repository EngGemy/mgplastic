@php
    /** @var array{count: int, total_points: int, distributed: int, remaining: int, percent: int} $stats */
@endphp

<div class="mg-store-inv-stats">
    <div class="mg-store-inv-stat mg-store-inv-stat--blue">
        <span class="mg-store-inv-stat-num">{{ number_format($stats['count']) }}</span>
        <span class="mg-store-inv-stat-label">فاتورة</span>
    </div>
    <div class="mg-store-inv-stat mg-store-inv-stat--amber">
        <span class="mg-store-inv-stat-num">{{ number_format($stats['total_points']) }}</span>
        <span class="mg-store-inv-stat-label">نقطة إجمالاً</span>
    </div>
    <div class="mg-store-inv-stat mg-store-inv-stat--green">
        <span class="mg-store-inv-stat-num">{{ number_format($stats['distributed']) }}</span>
        <span class="mg-store-inv-stat-label">موزّع ({{ $stats['percent'] }}%)</span>
    </div>
    <div class="mg-store-inv-stat mg-store-inv-stat--{{ $stats['remaining'] > 0 ? 'orange' : 'gray' }}">
        <span class="mg-store-inv-stat-num">{{ number_format($stats['remaining']) }}</span>
        <span class="mg-store-inv-stat-label">متبقي للتوزيع</span>
    </div>
</div>

@if($stats['count'] > 0)
    <div class="mg-store-inv-overall-bar">
        <div class="mg-store-inv-overall-fill" style="width: {{ $stats['percent'] }}%"></div>
    </div>
@endif
