@extends('layouts.website')

@section('title', $settings->seo_title)
@section('description', $settings->seo_description)

@section('content')
    @include('website.partials.nav')
    @include('website.partials.hero')
    @include('website.partials.stats')
    @include('website.partials.about')
    @include('website.partials.catalog')
    @include('website.partials.services')
    @include('website.partials.points')
    @include('website.partials.contact')
    @include('website.partials.footer')
    @include('website.partials.product-modal')
@endsection

@push('scripts')
<script>
    window.MG_CATEGORIES = @json($categories);
    window.MG_CATALOG_URL = @json(route('website.catalog'));
    window.MG_MAP = {
        lat: {{ $settings->map_latitude }},
        lng: {{ $settings->map_longitude }},
        zoom: 12,
        popup: @json('<div style="font-family:Cairo,sans-serif;direction:rtl;text-align:right;min-width:200px;padding:4px"><div style="font-weight:800;font-size:15px;color:#0d1b2a;margin-bottom:4px">مصنع '.$settings->site_name.'</div><div style="font-size:12px;color:#64748b;margin-bottom:8px">'.e($settings->contact_address).'</div></div>')
    };
    window.MG_REGISTER_URL = @json(route('website.register'));
    window.MG_PORTAL_URL = @json(route('portal'));
    window.MG_CSRF = @json(csrf_token());
</script>
<script src="{{ asset('js/website.js') }}"></script>
@endpush
