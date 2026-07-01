@php
    $user = auth()->user();
    if (!$user || !in_array($user->role, ['wholesale_distributor','retail_trader'])) return;
    $hasLogo = filled($user->brand_logo);
    $initials = collect(explode(' ', $user->brand_name ?? $user->name))
        ->take(2)->map(fn($w) => mb_substr($w, 0, 1))->implode('');
@endphp

<div style="
    background: linear-gradient(90deg, #1a3a6e 0%, #1a56db 100%);
    padding: 6px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    direction: rtl;
    font-family: 'Cairo', sans-serif;
    min-height: 44px;
">
    <img src="{{ asset('images/logo-light.png') }}"
         style="height:28px;width:auto;object-fit:contain;opacity:0.9;"
         alt="MG Plastic">

    <span style="width:1px;background:rgba(255,255,255,0.3);align-self:stretch;"></span>

    <div style="display:flex;align-items:center;gap:8px;">
        @if($hasLogo)
            <img src="{{ \Storage::disk('public')->url($user->brand_logo) }}"
                 style="width:28px;height:28px;border-radius:6px;object-fit:cover;border:1.5px solid rgba(255,255,255,0.4);"
                 alt="{{ $user->brand_name }}">
        @else
            <div style="
                width:28px;height:28px;border-radius:6px;
                background:rgba(255,255,255,0.2);
                border:1.5px solid rgba(255,255,255,0.4);
                display:flex;align-items:center;justify-content:center;
                font-size:11px;font-weight:800;color:white;
            ">{{ $initials }}</div>
        @endif

        <div>
            <div style="font-size:12px;font-weight:700;color:white;line-height:1.2;">
                {{ $user->brand_name ?? $user->name }}
            </div>
            <div style="font-size:10px;font-weight:600;padding:1px 6px;border-radius:999px;display:inline-block;margin-top:2px;
                {{ $user->role === 'wholesale_distributor' ? 'background:#bfdbfe;color:#1d4ed8;' : 'background:#a7f3d0;color:#065f46;' }}">
                {{ $user->role === 'wholesale_distributor' ? 'موزع جملة معتمد' : 'تاجر قطاعي معتمد' }}
            </div>
        </div>
    </div>
</div>
