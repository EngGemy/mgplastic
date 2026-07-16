<x-filament-widgets::widget>
    <div class="mg-general-controls" dir="rtl" style="font-family:'Cairo',sans-serif">
        <div style="
            background:#fff;
            border:1.5px solid #e2e8f0;
            border-radius:16px;
            padding:18px 20px;
            box-shadow:0 1px 2px rgba(15,23,42,.04);
        ">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px">
                <div>
                    <h2 style="margin:0;font-size:1.05rem;font-weight:900;color:#0f172a">التحكم والإعدادات العامة</h2>
                    <p style="margin:4px 0 0;font-size:12px;color:#64748b;font-weight:600">
                        إعدادات تؤثر على التطبيق والـ API مباشرة من لوحة التحكم
                    </p>
                </div>
                <span style="
                    font-size:11px;font-weight:800;padding:4px 10px;border-radius:999px;
                    background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;
                ">Admin Controls</span>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">
                <div style="
                    border:1.5px solid {{ $showWallet ? '#bbf7d0' : '#fecaca' }};
                    background: {{ $showWallet ? '#f0fdf4' : '#fef2f2' }};
                    border-radius:14px;
                    padding:14px 16px;
                    display:flex;
                    align-items:center;
                    justify-content:space-between;
                    gap:12px;
                ">
                    <div style="min-width:0">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                            <span style="font-size:18px">👛</span>
                            <strong style="font-size:14px;color:#0f172a">المحفظة في التطبيق</strong>
                        </div>
                        <p style="margin:0;font-size:12px;color:#64748b;line-height:1.45">
                            On = ظاهرة للموبايل · Off = مخفية عبر
                            <code style="font-size:10px;background:rgba(15,23,42,.06);padding:1px 5px;border-radius:4px">wallet-visibility</code>
                        </p>
                        <p style="margin:6px 0 0;font-size:11px;font-weight:800;color:{{ $showWallet ? '#059669' : '#dc2626' }}">
                            الحالة الآن: {{ $showWallet ? 'مفعّلة' : 'موقوفة' }}
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="toggleWallet"
                        wire:loading.attr="disabled"
                        aria-pressed="{{ $showWallet ? 'true' : 'false' }}"
                        title="{{ $showWallet ? 'إيقاف المحفظة' : 'تشغيل المحفظة' }}"
                        style="
                            width:52px;height:30px;border-radius:999px;position:relative;transition:.2s;
                            border:none;cursor:pointer;flex-shrink:0;
                            background:{{ $showWallet ? '#16a34a' : '#94a3b8' }};
                            box-shadow:inset 0 0 0 1px rgba(0,0,0,.06);
                        "
                    >
                        <span style="
                            position:absolute;top:3px;width:24px;height:24px;border-radius:999px;
                            background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:.2s;
                            {{ $showWallet ? 'left:25px' : 'left:3px' }};
                        "></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
