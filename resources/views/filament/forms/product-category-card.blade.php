@php
    use App\Models\ProductCategory;
    use Illuminate\Support\Facades\Storage;

    $category = filled($categoryId)
        ? ProductCategory::query()->with(['translations', 'parent.translations'])->find($categoryId)
        : null;

    $imageUrl = null;
    if ($category && is_string($category->image) && $category->image !== '' && Storage::disk('public')->exists($category->image)) {
        $imageUrl = Storage::disk('public')->url($category->image);
    }

    $parent = $category?->parent ? localized_name($category->parent, 'name') : null;
    $name = $category ? localized_name($category, 'name', 'تصنيف #'.$category->id) : null;
@endphp

@if (! $category)
    <div class="prod-cat-card prod-cat-card--empty">
        اختر التصنيف ليظهر رقمه وصورته هنا. نفس الرقم يُكتب في عمود «رقم التصنيف» داخل شيت المنتجات.
    </div>
@else
    <div class="prod-cat-card">
        @if ($imageUrl)
            <img src="{{ $imageUrl }}" alt="">
        @else
            <span class="prod-cat-card-fallback">#{{ $category->id }}</span>
        @endif
        <div>
            <div class="prod-cat-card-num">#{{ $category->id }}</div>
            <div class="prod-cat-card-name">{{ $parent ? $parent.' ← '.$name : $name }}</div>
            <div class="prod-cat-card-hint">رقم هذا التصنيف هو الذي يُستخدم في شيت المنتجات.</div>
        </div>
    </div>
@endif
