<div class="stats-bar">
    <div class="stats-grid">
        @foreach($stats as $stat)
            <div class="reveal">
                <div class="stat-num" data-target="{{ $stat->value }}">
                    ٠@if($stat->suffix)<span style="font-size:1.2rem">{{ $stat->suffix }}</span>@endif
                </div>
                <div class="stat-ar">{{ $stat->label_ar }}</div>
                @if($stat->label_en)
                    <div class="stat-fm">{{ $stat->label_en }}</div>
                @endif
            </div>
        @endforeach
    </div>
</div>
