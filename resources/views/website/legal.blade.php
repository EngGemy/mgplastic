@extends('layouts.website')

@section('title', $pageTitle.' — '.($settings->site_name ?? 'MG Plastic'))

@section('content')
    <nav id="nav" style="position:relative">
        <a class="nav-logo" href="{{ route('landing') }}">
            <div class="logo-box">MG</div>
            <div>
                <div style="font-weight:800;color:var(--ink);font-size:14px;line-height:1.2">{{ $settings->site_name ?? 'MG Plastic' }}</div>
                <div class="fm" style="font-size:9px;color:var(--hint)">{{ $settings->site_domain ?? 'mg-plastic.com' }}</div>
            </div>
        </a>
        <ul class="nav-links" style="display:flex;gap:18px;list-style:none;margin:0;padding:0;align-items:center">
            <li><a href="{{ route('landing') }}">الرئيسية</a></li>
            <li><a href="{{ route('contact') }}">التواصل</a></li>
            <li><a href="{{ route('privacy') }}" @if(($pageTitle ?? '') === 'سياسة الخصوصية') style="color:var(--blue);font-weight:700" @endif>الخصوصية</a></li>
            <li><a href="{{ route('policy') }}" @if(str_contains($pageTitle ?? '', 'السياسات') || str_contains($pageTitle ?? '', 'الشروط')) style="color:var(--blue);font-weight:700" @endif>السياسات</a></li>
        </ul>
        <a href="{{ route('landing') }}" class="nav-cta">العودة للموقع</a>
    </nav>

    <section style="padding:120px 5vw 60px;background:#fff;min-height:60vh">
        <div class="sec-inner" style="max-width:800px">
            <div style="font-family:'JetBrains Mono',monospace;font-size:10px;letter-spacing:.14em;color:var(--hint);text-transform:uppercase;margin-bottom:10px">Legal</div>
            <h1 class="sec-h2" style="font-family:'Amiri',serif;margin-bottom:24px">{{ $pageTitle }}</h1>
            <div style="font-size:15px;color:var(--muted);line-height:1.9">
                @if($record)
                    <h2 style="font-size:1.2rem;color:var(--ink);margin-bottom:12px">{{ optional($record->translate('ar'))->title ?? optional($record->translate('en'))->title }}</h2>
                    {!! nl2br(e(optional($record->translate('ar'))->content ?? optional($record->translate('en'))->content ?? '')) !!}
                @else
                    <p>المحتوى غير متوفر حالياً. يمكن إدارته من لوحة التحكم.</p>
                @endif
            </div>

            <div style="margin-top:40px;padding-top:24px;border-top:1px solid var(--border);display:flex;flex-wrap:wrap;gap:14px">
                <a href="{{ route('privacy') }}" style="color:var(--blue);font-weight:600;text-decoration:none">سياسة الخصوصية</a>
                <span style="color:var(--border)">|</span>
                <a href="{{ route('policy') }}" style="color:var(--blue);font-weight:600;text-decoration:none">السياسات والشروط</a>
                <span style="color:var(--border)">|</span>
                <a href="{{ route('terms') }}" style="color:var(--blue);font-weight:600;text-decoration:none">الشروط والأحكام</a>
                <span style="color:var(--border)">|</span>
                <a href="{{ route('contact') }}" style="color:var(--blue);font-weight:600;text-decoration:none">تواصل معنا</a>
            </div>
        </div>
    </section>

    <footer style="background:var(--ink);padding:32px 5vw;margin-top:0">
        <div style="max-width:1200px;margin:0 auto;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
            <div style="font-size:12px;color:rgba(255,255,255,.45)">© {{ date('Y') }} {{ $settings->site_name ?? 'MG Plastic' }}. جميع الحقوق محفوظة.</div>
            <div style="display:flex;gap:18px;flex-wrap:wrap">
                <a href="{{ route('contact') }}" style="color:rgba(255,255,255,.55);font-size:12px;text-decoration:none">التواصل</a>
                <a href="{{ route('privacy') }}" style="color:rgba(255,255,255,.55);font-size:12px;text-decoration:none">الخصوصية</a>
                <a href="{{ route('policy') }}" style="color:rgba(255,255,255,.55);font-size:12px;text-decoration:none">السياسات</a>
                <a href="{{ route('landing') }}" style="color:rgba(255,255,255,.55);font-size:12px;text-decoration:none">الرئيسية</a>
            </div>
        </div>
    </footer>
@endsection
