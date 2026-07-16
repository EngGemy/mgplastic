@props([
    'code',
    'variant' => 'light', // light | dark | chip
    'label' => 'الرقم الموحّد',
    'showLabel' => true,
])

@php
    $code = (string) $code;
    $isDark = $variant === 'dark';
    $btnBg = $isDark ? 'rgba(255,255,255,0.2)' : '#dbeafe';
    $btnColor = $isDark ? '#fff' : '#1d4ed8';
@endphp

<div
    {{ $attributes->merge(['class' => 'mg-net-code']) }}
    x-data="{
        copied: false,
        async copy() {
            const value = @js($code);
            try {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(value);
                } else {
                    const ta = document.createElement('textarea');
                    ta.value = value;
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                }
                this.copied = true;
                setTimeout(() => this.copied = false, 1600);
            } catch (e) {}
        }
    }"
    x-on:click="copy()"
    role="button"
    tabindex="0"
    x-on:keydown.enter.prevent="copy()"
    x-on:keydown.space.prevent="copy()"
    style="
        display:inline-flex;
        align-items:center;
        gap:8px;
        @if($variant === 'dark')
            background:rgba(255,255,255,0.16);
            border:1px solid rgba(255,255,255,0.35);
            color:#fff;
        @elseif($variant === 'chip')
            background:#eff6ff;
            border:1px solid #bfdbfe;
            color:#1e3a8a;
        @else
            background:rgba(26,86,219,.1);
            border:1px solid rgba(26,86,219,.25);
            color:#1e3a8a;
        @endif
        border-radius:10px;
        padding:4px 10px;
        font-family:'Cairo',sans-serif;
        direction:rtl;
        max-width:100%;
        cursor:pointer;
        user-select:none;
    "
    title="اضغط لنسخ الرقم الموحّد"
>
    @if($showLabel)
        <span style="font-size:10px;font-weight:700;opacity:.85;letter-spacing:.03em;white-space:nowrap">{{ $label }}</span>
    @endif

    <strong
        dir="ltr"
        style="font-size:13px;font-weight:900;letter-spacing:.05em;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;white-space:nowrap"
    >{{ $code }}</strong>

    <span
        :style="`display:inline-flex;align-items:center;justify-content:center;gap:4px;border-radius:8px;padding:3px 8px;font-size:11px;font-weight:800;background:${copied ? '#16a34a' : '{{ $btnBg }}'};color:${copied ? '#fff' : '{{ $btnColor }}'};`"
        style="
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:4px;
            border-radius:8px;
            padding:3px 8px;
            font-size:11px;
            font-weight:800;
            background: {{ $btnBg }};
            color: {{ $btnColor }};
            white-space:nowrap;
        "
    >
        <span x-show="!copied" style="display:inline-flex;align-items:center;gap:4px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                <rect x="9" y="9" width="13" height="13" rx="2"></rect>
                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
            </svg>
            نسخ
        </span>
        <span x-show="copied" x-cloak style="display:inline-flex;align-items:center;gap:4px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <path d="M20 6L9 17l-5-5"></path>
            </svg>
            تم
        </span>
    </span>
</div>
