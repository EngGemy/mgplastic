@php
    $record = $getRecord();
    $record->loadMissing(['items', 'distributions.fromUser', 'distributions.toUser', 'distributions.items']);
    $totalPoints = (int) $record->items->sum('total_points');
    $totalQty = (int) $record->items->sum('quantity');

    $tierLabels = [
        1 => '① مصنع → موزع جملة',
        2 => '② موزع جملة → تاجر قطاعي',
        3 => '③ تاجر قطاعي → سباك (نقاط)',
    ];

    $tierColors = [1 => 'blue', 2 => 'amber', 3 => 'green'];

    $distributionsByTier = $record->distributions->groupBy('tier');
@endphp

<div class="dist-panel" dir="rtl">
    <div class="dist-panel-header">
        <div>
            <h3 class="dist-panel-title">نظام توزيع النقاط — فاتورة {{ $record->number }}</h3>
            <p class="dist-panel-sub">الرقم الأساسي: {{ $record->serial_number }} — سلسلة: مصنع → جملة → قطاعي → سباك</p>
        </div>
        <div class="dist-panel-stats">
            <span class="dist-stat dist-stat--qty">{{ number_format($totalQty) }} وحدة</span>
            <span class="dist-stat dist-stat--pts">⭐ {{ number_format($totalPoints) }} نقطة</span>
        </div>
    </div>

    <div class="dist-tiers">
        @foreach([1, 2, 3] as $tier)
            @php
                $tierDists = $distributionsByTier->get($tier, collect());
                $confirmedQty = $tierDists->where('status', '!=', 'draft')->sum(fn ($d) => $d->items->sum('quantity'));
                $confirmedPts = $tierDists->where('status', '!=', 'draft')->sum(fn ($d) => $d->items->sum('points_value'));
                $draftCount = $tierDists->where('status', 'draft')->count();
                $pct = $totalQty > 0 ? min(100, round(($confirmedQty / $totalQty) * 100)) : 0;
                $color = $tierColors[$tier];
            @endphp
            <div class="dist-tier dist-tier--{{ $color }}">
                <div class="dist-tier-head">
                    <span class="dist-tier-label">{{ $tierLabels[$tier] }}</span>
                    @if($draftCount > 0)
                        <span class="dist-badge dist-badge--warning">{{ $draftCount }} مسودة</span>
                    @endif
                </div>
                <div class="dist-progress">
                    <div class="dist-progress-bar" style="width: {{ $pct }}%"></div>
                </div>
                <div class="dist-tier-meta">
                    <span>{{ number_format($confirmedQty) }} / {{ number_format($totalQty) }} وحدة موزّعة</span>
                    <span>{{ number_format($confirmedPts) }} نقطة</span>
                </div>
                @if($tierDists->isNotEmpty())
                    <ul class="dist-list">
                        @foreach($tierDists as $dist)
                            <li>
                                <a href="{{ \App\Filament\Support\NetworkPanelUrls::distributionView($dist) }}"
                                   class="dist-link">
                                    #{{ $dist->id }}
                                    {{ $dist->fromUser?->name }} → {{ $dist->toUser?->name }}
                                    <span class="dist-status dist-status--{{ $dist->status }}">
                                        @switch($dist->status)
                                            @case('draft') مسودة @break
                                            @case('confirmed') مؤكد @break
                                            @case('points_awarded') نقاط ممنوحة @break
                                            @default {{ $dist->status }}
                                        @endswitch
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>
</div>
