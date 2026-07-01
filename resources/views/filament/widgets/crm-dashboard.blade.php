<x-filament-widgets::widget>
    <div class="mg-crm-dash mg-crm-dash--{{ $accent ?? 'blue' }}" dir="rtl">
        <header class="mg-crm-welcome">
            <div class="mg-crm-welcome-text">
                <h1 class="mg-crm-welcome-title">{{ $welcomeTitle }}</h1>
                <p class="mg-crm-welcome-sub">{{ $welcomeSubtitle }}</p>
            </div>
            <div class="mg-crm-welcome-date">
                <x-filament::icon icon="heroicon-o-calendar-days" class="mg-crm-welcome-date-icon" />
                <span>{{ now()->translatedFormat('l، d F Y') }}</span>
            </div>
        </header>

        <div class="mg-crm-stats">
            @foreach($cards as $card)
                <article class="mg-crm-stat-card">
                    <div class="mg-crm-stat-top">
                        <div class="mg-crm-stat-icon mg-crm-stat-icon--{{ $card['color'] }}">
                            <x-filament::icon :icon="$card['icon']" class="mg-crm-stat-icon-svg" />
                        </div>
                    </div>

                    <p class="mg-crm-stat-label">{{ $card['label'] }}</p>
                    <p class="mg-crm-stat-value">{{ is_numeric($card['value']) ? number_format((float) $card['value']) : $card['value'] }}</p>

                    @if(!empty($card['trend']))
                        <p class="mg-crm-stat-trend {{ ($card['trend']['positive'] ?? true) ? 'mg-crm-stat-trend--up' : 'mg-crm-stat-trend--down' }}">
                            @if($card['trend']['positive'] ?? true)
                                <svg class="mg-crm-trend-arrow" viewBox="0 0 16 16" fill="currentColor"><path d="M8 3l5 6H3l5-6z"/></svg>
                            @else
                                <svg class="mg-crm-trend-arrow" viewBox="0 0 16 16" fill="currentColor"><path d="M8 13L3 7h10l-5 6z"/></svg>
                            @endif
                            {{ $card['trend']['text'] }}
                        </p>
                    @else
                        <p class="mg-crm-stat-sub">{{ $card['sub'] }}</p>
                    @endif
                </article>
            @endforeach
        </div>
    </div>
</x-filament-widgets::widget>
