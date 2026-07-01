@extends('layouts.website')

@section('title', $pageTitle)

@section('content')
    <nav id="nav" style="position:relative">
        <a class="nav-logo" href="{{ route('landing') }}">
            <div class="logo-box">MG</div>
            <div>
                <div style="font-weight:800;color:var(--ink);font-size:14px;line-height:1.2">MG Plastic</div>
            </div>
        </a>
        <a href="{{ route('landing') }}" class="nav-cta">العودة للموقع</a>
    </nav>

    <section style="padding:120px 5vw 80px;background:#fff;min-height:70vh">
        <div class="sec-inner" style="max-width:800px">
            <h1 class="sec-h2" style="font-family:'Amiri',serif;margin-bottom:24px">{{ $pageTitle }}</h1>
            <div style="font-size:15px;color:var(--muted);line-height:1.9">
                @if($record)
                    <h2 style="font-size:1.2rem;color:var(--ink);margin-bottom:12px">{{ optional($record->translate('ar'))->title ?? optional($record->translate('en'))->title }}</h2>
                    {!! nl2br(e(optional($record->translate('ar'))->content ?? optional($record->translate('en'))->content ?? '')) !!}
                @else
                    <p>المحتوى غير متوفر حالياً. يمكن إدارته من لوحة التحكم.</p>
                @endif
            </div>
        </div>
    </section>
@endsection
