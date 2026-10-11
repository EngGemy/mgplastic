@php
    // Same asset used by Filament AdminPanelProvider ->brandLogo(...)
    $logoUrl = asset(\App\Models\WebsiteSetting::DEFAULT_LOGO);
    $alt = $settings->site_name ?? 'MG Plastic';
    $size = $size ?? 40;
@endphp
<img
    src="{{ $logoUrl }}"
    alt="{{ $alt }}"
    class="site-logo-mark {{ $class ?? '' }}"
    style="width:{{ $size }}px;height:{{ $size }}px;object-fit:contain;border-radius:10px;display:block;flex-shrink:0;background:#fff"
>
