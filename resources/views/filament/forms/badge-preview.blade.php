@php
    /** @var \Filament\Forms\Get|null $get */
    $get = $get ?? $getGetCallback ?? (isset($field) ? $field->getGetCallback() : null);

    $name = trim((string) ($get ? ($get('name_ar') ?: 'اسم الشارة') : 'اسم الشارة'));
    $desc = trim((string) ($get ? ($get('description_ar') ?: 'وصف قصير يظهر للسباك عند الفوز بالشارة.') : 'وصف قصير يظهر للسباك عند الفوز بالشارة.'));
    $tier = (string) ($get ? ($get('tier') ?: 'common') : 'common');
    $icon = (string) ($get ? ($get('icon') ?: 'star') : 'star');
    $key = trim((string) ($get ? ($get('key') ?: 'badge_key') : 'badge_key'));
    $active = (bool) ($get ? ($get('is_active') ?? true) : true);

    $tierMeta = match ($tier) {
        'legendary' => ['label' => 'أسطورية', 'class' => 'mg-badge-preview--legendary'],
        'epic' => ['label' => 'ملحمية', 'class' => 'mg-badge-preview--epic'],
        'rare' => ['label' => 'نادرة', 'class' => 'mg-badge-preview--rare'],
        default => ['label' => 'شائعة', 'class' => 'mg-badge-preview--common'],
    };

    $iconGlyph = match ($icon) {
        'medal' => '🏅',
        'trophy' => '🏆',
        'coin' => '🪙',
        'fire' => '🔥',
        'shield' => '🛡️',
        'sun' => '☀️',
        'heart' => '💙',
        'basket' => '🧺',
        'badge' => '🎖️',
        'diamond' => '💎',
        default => '⭐',
    };
@endphp

<div class="mg-badge-studio" dir="rtl">
    <div class="mg-badge-studio__rail">
        <span class="mg-badge-studio__kicker">معاينة التطبيق</span>
        <h3 class="mg-badge-studio__title">كيف تظهر الشارة للسباك</h3>
        <p class="mg-badge-studio__hint">تتحدّث تلقائيًا مع الحقول — الندرة تغيّر الإطار واللون.</p>
    </div>

    <div class="mg-badge-preview {{ $tierMeta['class'] }} {{ $active ? '' : 'mg-badge-preview--off' }}">
        <div class="mg-badge-preview__glow" aria-hidden="true"></div>
        <div class="mg-badge-preview__medal">
            <span class="mg-badge-preview__glyph">{{ $iconGlyph }}</span>
        </div>
        <div class="mg-badge-preview__body">
            <div class="mg-badge-preview__meta">
                <span class="mg-badge-preview__tier">{{ $tierMeta['label'] }}</span>
                @unless($active)
                    <span class="mg-badge-preview__paused">موقوفة</span>
                @endunless
            </div>
            <h4 class="mg-badge-preview__name">{{ $name }}</h4>
            <p class="mg-badge-preview__desc">{{ \Illuminate\Support\Str::limit($desc, 90) }}</p>
            <code class="mg-badge-preview__key">{{ $key }}</code>
        </div>
    </div>
</div>
