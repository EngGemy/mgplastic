@extends('layouts.website')

@section('title', 'تواصل معنا — '.($settings->site_name ?? 'MG Plastic'))
@section('description', 'بيانات التواصل مع '.($settings->site_name ?? 'MG Plastic').' — الهاتف، الواتساب، البريد، والموقع على الخريطة')

@section('content')
    <nav id="nav" style="position:relative">
        <a class="nav-logo" href="{{ route('landing') }}">
            <div class="logo-box">MG</div>
            <div>
                <div style="font-weight:800;color:var(--ink);font-size:14px;line-height:1.2">{{ $settings->site_name }}</div>
                <div class="fm" style="font-size:9px;color:var(--hint)">{{ $settings->site_domain }}</div>
            </div>
        </a>
        <ul class="nav-links" style="display:flex;gap:18px;list-style:none;margin:0;padding:0;align-items:center">
            <li><a href="{{ route('landing') }}">الرئيسية</a></li>
            <li><a href="{{ route('contact') }}" style="color:var(--blue);font-weight:700">التواصل</a></li>
            <li><a href="{{ route('privacy') }}">الخصوصية</a></li>
            <li><a href="{{ route('policy') }}">السياسات</a></li>
        </ul>
        <a href="{{ route('landing') }}" class="nav-cta">العودة للموقع</a>
    </nav>

    @include('website.partials.contact')

    @include('website.partials.footer')
@endsection

@push('scripts')
@php
    $mapPopup = '<div style="font-family:Cairo,sans-serif;direction:rtl;text-align:right;min-width:200px;padding:4px">'
        .'<div style="font-weight:800;font-size:15px;color:#0d1b2a;margin-bottom:4px">مصنع '.e($settings->site_name).'</div>'
        .'<div style="font-size:12px;color:#64748b;margin-bottom:8px">'.e($settings->contact_address).'</div>'
        .'</div>';
@endphp
<script>
    window.MG_MAP = {
        lat: {{ $settings->map_latitude ?? 32.8872 }},
        lng: {{ $settings->map_longitude ?? 13.1913 }},
        zoom: 12,
        popup: @json($mapPopup)
    };
</script>
<script src="{{ asset('js/website.js') }}"></script>
@endpush
