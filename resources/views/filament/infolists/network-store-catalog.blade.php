@php
    /** @var \App\Models\User $record */
    $record->loadMissing(['storeMedia.product.translations']);
    $media = $record->storeMedia->where('is_active', true);
    $banners = $media->where('kind', 'banner');
    $gallery = $media->where('kind', 'gallery');
    $products = $media->where('kind', 'product_image');
    $videos = $media->where('kind', 'video');
    $myProducts = $media->where('kind', 'my_product');
@endphp

<div style="font-family:'Cairo',sans-serif;direction:rtl;">
    @if($myProducts->isNotEmpty())
    <div style="margin-bottom:1rem;">
        <div style="font-size:0.75rem;font-weight:700;color:#6b7280;margin-bottom:0.5rem;">منتجاتي</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:0.6rem;">
            @foreach($myProducts as $item)
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
                    <img src="{{ $item->url }}" alt="" style="width:100%;aspect-ratio:1;object-fit:cover;display:block;">
                    <div style="padding:6px 8px;">
                        <div style="font-size:0.7rem;font-weight:800;line-height:1.3;">{{ $item->title ?: '—' }}</div>
                        @if($item->description)
                            <div style="font-size:0.6rem;color:#64748b;margin-top:2px;">{{ \Illuminate\Support\Str::limit($item->description, 40) }}</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    @if($banners->isNotEmpty())
    <div style="margin-bottom:1rem;">
        <div style="font-size:0.75rem;font-weight:700;color:#6b7280;margin-bottom:0.5rem;">بانرات</div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
            @foreach($banners as $item)
                <img src="{{ $item->url }}" alt="" style="height:72px;border-radius:8px;object-fit:cover;border:1px solid #e5e7eb;">
            @endforeach
        </div>
    </div>
    @endif

    @if($gallery->isNotEmpty())
    <div style="margin-bottom:1rem;">
        <div style="font-size:0.75rem;font-weight:700;color:#6b7280;margin-bottom:0.5rem;">معرض الصور</div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
            @foreach($gallery as $item)
                <img src="{{ $item->url }}" alt="" style="height:72px;border-radius:8px;object-fit:cover;border:1px solid #e5e7eb;">
            @endforeach
        </div>
    </div>
    @endif

    @if($products->isNotEmpty())
    <div style="margin-bottom:1rem;">
        <div style="font-size:0.75rem;font-weight:700;color:#6b7280;margin-bottom:0.5rem;">صور منتجات</div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
            @foreach($products as $item)
                <div style="text-align:center;">
                    <img src="{{ $item->url }}" alt="" style="height:72px;border-radius:8px;object-fit:cover;border:1px solid #e5e7eb;">
                    @if($item->product)
                        <div style="font-size:0.65rem;margin-top:2px;">{{ localized_name($item->product, 'name') }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
    @endif

    @if($videos->isNotEmpty())
    <div>
        <div style="font-size:0.75rem;font-weight:700;color:#6b7280;margin-bottom:0.5rem;">فيديوهات</div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
            @foreach($videos as $item)
                <a href="{{ $item->url }}" target="_blank" style="display:block;text-align:center;text-decoration:none;color:#1d4ed8;">
                    @if($item->thumbnail_url)
                        <img src="{{ $item->thumbnail_url }}" alt="" style="height:72px;border-radius:8px;object-fit:cover;border:1px solid #e5e7eb;">
                    @else
                        <div style="height:72px;width:96px;background:#f3f4f6;border-radius:8px;display:flex;align-items:center;justify-content:center;">▶</div>
                    @endif
                    <div style="font-size:0.65rem;margin-top:2px;">{{ $item->title ?: 'فيديو' }}</div>
                </a>
            @endforeach
        </div>
    </div>
    @endif
</div>
