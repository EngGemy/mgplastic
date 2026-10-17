<x-filament-widgets::widget>
    <div style="
        direction: rtl;
        font-family: Cairo, sans-serif;
        background: #fff;
        border-radius: 16px;
        padding: 18px 20px;
        box-shadow: 0 1px 3px rgba(15,23,42,.08);
        border: 1px solid rgba(15,23,42,.06);
    ">
        <div style="font-size:16px;font-weight:800;color:#0f172a;margin-bottom:14px">
            {{ $title ?? 'اختصارات مخزن الدعوة' }}
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px">
            @foreach(($links ?? []) as $link)
                <a href="{{ $link['url'] }}"
                   style="
                        display:block;
                        text-decoration:none;
                        background:linear-gradient(135deg,#0f766e,#134e4a);
                        color:#fff;
                        border-radius:12px;
                        padding:14px 16px;
                   ">
                    <div style="font-size:14px;font-weight:800;line-height:1.3">{{ $link['label'] }}</div>
                    <div style="font-size:11px;opacity:.85;margin-top:4px">{{ $link['sub'] }}</div>
                </a>
            @endforeach
        </div>
    </div>
</x-filament-widgets::widget>
