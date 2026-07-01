@php
    $record = $getRecord();
    $record->loadMissing(['category.parent', 'category.translations', 'translations']);

    $name = localized_name($record, 'name', "منتج #{$record->id}");

    $cat = $record->category;
    $categoryPath = '—';
    if ($cat) {
        $parentName = $cat->parent ? localized_name($cat->parent, 'name') : null;
        $childName = localized_name($cat, 'name');
        $categoryPath = $parentName ? "{$parentName} ← {$childName}" : $childName;
    }

    $imageUrl = $record->main_image
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($record->main_image)
        : null;
@endphp

<div class="net-profile" dir="rtl">
    <div class="net-profile-top">
        <div class="net-profile-identity">
            @if($imageUrl)
                <img src="{{ $imageUrl }}" alt="" class="net-profile-avatar" style="border-radius:12px">
            @else
                <div class="net-profile-avatar net-profile-avatar--empty" style="border-radius:12px">📦</div>
            @endif
            <div class="net-profile-text">
                <span class="net-profile-badge">منتج</span>
                <h2 class="net-profile-name">{{ $name }}</h2>
                <p class="net-profile-parent">{{ $categoryPath }}</p>
            </div>
        </div>
        <div class="net-profile-stats">
            @if($record->points_per_unit)
                <div class="net-stat net-stat--amber">
                    <span class="net-stat-num">{{ number_format((int) $record->points_per_unit) }}</span>
                    <span class="net-stat-label">نقطة/وحدة</span>
                </div>
            @endif
            @if($record->pointMonetaryValuePerUnit() > 0)
                <div class="net-stat net-stat--green">
                    <span class="net-stat-num">{{ number_format($record->pointMonetaryValuePerUnit(), 2) }}</span>
                    <span class="net-stat-label">د.ل / وحدة</span>
                </div>
            @endif
            @if($record->classification)
                <div class="net-stat net-stat--blue">
                    <span class="net-stat-num" style="font-size:0.85rem">{{ $record->classification }}</span>
                    <span class="net-stat-label">التصنيف</span>
                </div>
            @endif
        </div>
    </div>
</div>
