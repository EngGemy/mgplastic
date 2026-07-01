@extends('layouts.website')

@section('title', 'دخول النظام — '.$settings->site_name)

@section('content')
    <nav id="nav" style="position:relative">
        <a class="nav-logo" href="{{ route('landing') }}">
            <div class="logo-box">MG</div>
            <div>
                <div style="font-weight:800;color:var(--ink);font-size:14px;line-height:1.2">{{ $settings->site_name }}</div>
                <div class="fm" style="font-size:9px;color:var(--hint)">{{ $settings->site_domain }}</div>
            </div>
        </a>
        <a href="{{ route('landing') }}" class="nav-cta" style="background:var(--surface);color:var(--ink);border:1px solid var(--border)">العودة للموقع</a>
    </nav>

    <section style="padding:110px 5vw 80px;background:var(--surface);min-height:85vh">
        <div class="sec-inner" style="max-width:920px">
            <div style="text-align:center;margin-bottom:40px">
                <span class="sec-eyebrow">Secure Portal Access</span>
                <h1 class="sec-h2" style="font-family:'Amiri',serif;margin-bottom:8px">دخول النظام</h1>
                <p style="font-size:14px;color:var(--muted);max-width:520px;margin:0 auto;line-height:1.8">
                    اختر بوابتك حسب نوع حسابك. لوحة الإدارة غير متاحة من الموقع العام لأسباب أمنية.
                </p>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px">
                @foreach($portals as $portal)
                    <a href="{{ $portal['url'] }}"
                       @if(!empty($portal['external'])) target="_blank" rel="noopener" @endif
                       style="display:block;background:#fff;border:1.5px solid var(--border);border-radius:16px;padding:24px;text-decoration:none;transition:transform .15s,border-color .15s,box-shadow .15s"
                       onmouseover="this.style.transform='translateY(-3px)';this.style.borderColor='{{ $portal['color'] }}';this.style.boxShadow='0 12px 40px rgba(0,0,0,.06)'"
                       onmouseout="this.style.transform='';this.style.borderColor='var(--border)';this.style.boxShadow=''">
                        <div style="width:48px;height:48px;background:{{ $portal['bg'] }};border-radius:12px;display:flex;align-items:center;justify-content:center;margin-bottom:14px">
                            <i class="ti {{ $portal['icon'] }}" style="font-size:22px;color:{{ $portal['color'] }}"></i>
                        </div>
                        <div style="font-weight:800;font-size:16px;color:var(--ink);margin-bottom:4px">{{ $portal['title'] }}</div>
                        <div class="fm" style="font-size:9px;color:var(--hint);letter-spacing:.08em;margin-bottom:10px">{{ $portal['subtitle'] }}</div>
                        <p style="font-size:12px;color:var(--muted);line-height:1.75;margin-bottom:14px">{{ $portal['description'] }}</p>
                        <span style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;color:{{ $portal['color'] }}">
                            متابعة الدخول <i class="ti ti-arrow-left"></i>
                        </span>
                    </a>
                @endforeach
            </div>

            <div style="margin-top:28px;padding:16px 18px;background:#fff;border:1px solid var(--border);border-radius:12px;font-size:12px;color:var(--muted);line-height:1.8;text-align:center">
                <i class="ti ti-shield-lock" style="color:var(--blue);vertical-align:-2px"></i>
                لا يوجد تسجيل دخول عام للإدارة من الموقع. مسؤولو النظام يستخدمون رابط الإدارة المخصص لهم فقط.
            </div>
        </div>
    </section>
@endsection
