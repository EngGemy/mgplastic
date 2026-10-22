@props([
    'kicker' => '',
    'title' => '',
    'lead' => '',
    'steps' => [],
    'note' => null,
    'actions' => [],
])

<div {{ $attributes->class(['mg-ops-rail']) }} dir="rtl">
    <div class="mg-ops-rail__mesh" aria-hidden="true"></div>
    <div class="mg-ops-rail__copy">
        @if ($kicker)
            <span class="mg-ops-rail__kicker">{{ $kicker }}</span>
        @endif
        <h2 class="mg-ops-rail__title">{{ $title }}</h2>
        @if ($lead)
            <p class="mg-ops-rail__lead">{!! $lead !!}</p>
        @endif
        @if (! empty($steps))
            <ol class="mg-ops-rail__steps">
                @foreach ($steps as $i => $step)
                    <li style="--i: {{ $i }}">
                        <span class="mg-ops-rail__n">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="mg-ops-rail__step">{!! $step !!}</span>
                    </li>
                @endforeach
            </ol>
        @endif
        @if ($note)
            <p class="mg-ops-rail__note">{!! $note !!}</p>
        @endif
        @if (! empty($actions))
            <div class="mg-ops-rail__actions">
                @foreach ($actions as $action)
                    <a href="{{ $action['url'] }}" class="mg-ops-rail__btn {{ ($action['tone'] ?? 'ghost') === 'brass' ? 'mg-ops-rail__btn--brass' : 'mg-ops-rail__btn--ghost' }}">
                        {{ $action['label'] }}
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
