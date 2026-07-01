@php
    use App\Models\ProductCategory;

    $get = $field->getGetCallback();
    $name = $get('name_ar') ?: $get('name_en') ?: '—';
    $points = (float) ($get('points_per_unit') ?? 0);
    $categoryId = $get('product_category_id');
    $categoryLabel = '—';

    if ($categoryId) {
        $cat = ProductCategory::with('translations', 'parent.translations')->find($categoryId);
        if ($cat) {
            $parent = $cat->parent
                ? (localized_name($cat->parent, 'name').' ← ')
                : '';
            $categoryLabel = $parent.localized_name($cat, 'name');
        }
    }

    $image = $get('main_image');
    $imageUrl = null;
    if (is_string($image) && $image !== '') {
        $imageUrl = \Illuminate\Support\Facades\Storage::disk('public')->url(ltrim($image, '/'));
    } elseif (is_array($image)) {
        $path = $image['path'] ?? $image[0] ?? null;
        if (is_string($path)) {
            $imageUrl = \Illuminate\Support\Facades\Storage::disk('public')->url(ltrim($path, '/'));
        }
    }
@endphp

<div class="prod-create-summary" dir="rtl">
    <h4 class="prod-create-summary-title">📋 ملخص المنتج قبل الحفظ</h4>

    <div class="prod-create-summary-body">
        @if($imageUrl)
            <img src="{{ $imageUrl }}" alt="" class="prod-create-summary-img">
        @endif
        <div class="prod-create-summary-info">
            <p><span>الاسم:</span> <strong>{{ $name }}</strong></p>
            <p><span>الفئة:</span> {{ $categoryLabel }}</p>
            <p><span>النقاط/وحدة:</span> <strong class="text-amber-600">{{ number_format($points, 2) }}</strong> نقطة</p>
            <p class="prod-create-summary-hint">اضغط «حفظ المنتج» لإضافته إلى النظام والتقرير.</p>
            @php
                $type = $get('point_value_type');
                $refPrice = (float) ($get('reference_unit_price_dinars') ?? 0);
                $percent = (float) ($get('point_value_percent') ?? 0);
                $fixed = (float) ($get('point_value_fixed') ?? 0);
                $unitValue = 0.0;
                if ($type === 'percent' && $refPrice > 0 && $percent > 0) {
                    $unitValue = round($refPrice * ($percent / 100), 2);
                } elseif ($type === 'fixed' && $fixed > 0) {
                    $unitValue = round($points * $fixed, 2);
                }
            @endphp
            @if($type)
                <p><span>التحويل:</span>
                    @if($type === 'percent')
                        {{ number_format($percent, 2) }}% من {{ number_format($refPrice, 2) }} د.ل
                    @else
                        {{ number_format($fixed, 4) }} د.ل / نقطة
                    @endif
                </p>
                @if($unitValue > 0)
                    <p><span>قيمة نقاط الوحدة:</span> <strong>{{ number_format($unitValue, 2) }} د.ل</strong></p>
                @endif
            @endif
        </div>
    </div>
</div>
