@php
    $user = auth()->user();
    if (! $user || ! $user->isCentralWarehouse()) {
        return;
    }

    if (blank($user->network_code)) {
        try {
            app(\App\Services\NetworkCodeService::class)->ensure($user);
            $user->refresh();
        } catch (\Throwable) {}
    }

    $storeImageUrl = $user->storeBrandImageUrl();
    $initials = collect(explode(' ', $user->brand_name ?? $user->name))
        ->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');
@endphp

<div style="
    background: linear-gradient(90deg, #134e4a 0%, #0f766e 100%);
    padding: 6px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    direction: rtl;
    font-family: 'Cairo', sans-serif;
    min-height: 48px;
">
    <img src="{{ asset('images/logo-light.png') }}"
         style="height:28px;width:auto;object-fit:contain;opacity:0.9;"
         alt="MG Plastic">

    @if(filled($user->network_code))
        <x-copyable-network-code :code="$user->network_code" variant="dark" />
    @endif

    <span style="width:1px;background:rgba(255,255,255,0.3);align-self:stretch;"></span>

    <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:0">
        @if($storeImageUrl)
            <img src="{{ $storeImageUrl }}"
                 style="width:32px;height:32px;border-radius:8px;object-fit:cover;border:1.5px solid rgba(255,255,255,0.4);"
                 alt="{{ $user->brand_name ?? $user->name }}">
        @else
            <div style="
                width:32px;height:32px;border-radius:8px;
                background:rgba(255,255,255,0.2);
                border:1.5px solid rgba(255,255,255,0.4);
                display:flex;align-items:center;justify-content:center;
                font-size:11px;font-weight:800;color:white;
            ">{{ $initials }}</div>
        @endif

        <div style="min-width:0">
            <div style="font-size:12px;font-weight:700;color:white;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                {{ $user->brand_name ?? $user->name }}
            </div>
            <div style="font-size:10px;font-weight:600;padding:1px 6px;border-radius:999px;display:inline-block;margin-top:2px;background:#99f6e4;color:#115e59;">
                مخزن مركزي معتمد
            </div>
        </div>
    </div>
</div>
