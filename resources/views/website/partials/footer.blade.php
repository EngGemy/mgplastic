@php
    $iconMap = [
        'facebook' => 'ti-brand-facebook',
        'instagram' => 'ti-brand-instagram',
        'x' => 'ti-brand-x',
        'twitter' => 'ti-brand-x',
        'youtube' => 'ti-brand-youtube',
        'tiktok' => 'ti-brand-tiktok',
        'linkedin' => 'ti-brand-linkedin',
        'whatsapp' => 'ti-brand-whatsapp',
        'website' => 'ti-world',
        'other' => 'ti-link',
    ];
@endphp

<footer>
    <div class="footer-grid">
        <div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
                <div class="logo-box">MG</div>
                <div>
                    <div style="font-weight:800;color:#fff;font-size:15px">{{ $settings->site_name }}</div>
                    <div class="fm" style="font-size:10px;color:rgba(255,255,255,.3)">{{ $settings->site_domain }}</div>
                </div>
            </div>
            @if($settings->footer_tagline)
                <p style="font-size:12px;color:rgba(255,255,255,.45);line-height:1.75;max-width:260px">{{ $settings->footer_tagline }}</p>
            @endif
            @if($socialLinks->isNotEmpty())
                <div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap">
                    @foreach($socialLinks as $link)
                        <a href="{{ $link->url }}" target="_blank" rel="noopener"
                           style="width:34px;height:34px;background:rgba(255,255,255,.08);border-radius:8px;display:flex;align-items:center;justify-content:center;color:{{ $link->platform === 'whatsapp' ? '#25D366' : 'rgba(255,255,255,.5)' }};font-size:16px;text-decoration:none">
                            <i class="ti {{ $iconMap[$link->platform] ?? 'ti-link' }}"></i>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
        <div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;color:rgba(255,255,255,.5);letter-spacing:.12em;text-transform:uppercase;margin-bottom:14px">المنتجات</div>
            <ul class="footer-links">
                @foreach(collect($categories)->take(5) as $category)
                    <li><a href="#catalog">{{ $category['name'] }}</a></li>
                @endforeach
            </ul>
        </div>
        <div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;color:rgba(255,255,255,.5);letter-spacing:.12em;text-transform:uppercase;margin-bottom:14px">الشبكة</div>
            <ul class="footer-links">
                <li><a href="{{ route('portal') }}">دخول النظام</a></li>
                <li><a href="/distributor">بوابة الموزع</a></li>
                <li><a href="/trader">بوابة التاجر</a></li>
                <li><a href="#register">التسجيل</a></li>
                <li><a href="#points">نظام النقاط</a></li>
            </ul>
        </div>
        <div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;color:rgba(255,255,255,.5);letter-spacing:.12em;text-transform:uppercase;margin-bottom:14px">التواصل</div>
            <ul class="footer-links">
                <li><a href="{{ $contactUrl ?? route('contact') }}">صفحة التواصل</a></li>
                @if($settings->contact_phone)
                    <li><a href="tel:{{ $settings->contact_phone }}" dir="ltr">{{ $settings->contact_phone }}</a></li>
                @endif
                @if($settings->contact_email)
                    <li><a href="mailto:{{ $settings->contact_email }}">{{ $settings->contact_email }}</a></li>
                @endif
                @if($settings->contact_address)
                    <li style="color:rgba(255,255,255,.3);font-size:12px">{{ $settings->contact_address }}</li>
                @endif
                @if($settings->contact_work_days)
                    <li style="color:rgba(255,255,255,.3);font-size:12px">{{ $settings->contact_work_days }} {{ $settings->contact_work_hours }}</li>
                @endif
                <li><a href="{{ $privacyUrl ?? route('privacy') }}">سياسة الخصوصية</a></li>
                <li><a href="{{ $policyUrl ?? route('policy') }}">السياسات والشروط</a></li>
                <li><a href="{{ $termsUrl ?? route('terms') }}">الشروط والأحكام</a></li>
            </ul>
        </div>
    </div>
    <div style="max-width:1200px;margin:0 auto;padding-top:24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div class="fm" style="font-size:10px;color:rgba(255,255,255,.25)">© {{ date('Y') }} {{ $settings->site_name }}. جميع الحقوق محفوظة.</div>
        <div class="fm" style="font-size:10px;color:rgba(255,255,255,.25)">Built with Laravel + Filament</div>
    </div>
</footer>
