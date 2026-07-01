@php
    $record = $getRecord();
    $lat = (float) ($record->latitude ?? 32.8872);
    $lng = (float) ($record->longitude ?? 13.1913);
    $hasLocation = $record->hasMapLocation();
@endphp

@if($hasLocation)
    <div class="osm-display-wrap" wire:ignore>
        <div id="osm-display-{{ $record->id }}" class="osm-map osm-map--display"></div>
        <div class="osm-display-links">
            <a href="{{ $record->mapUrl() }}" target="_blank" rel="noopener" class="osm-link">
                🗺️ فتح في OpenStreetMap
            </a>
            <span class="osm-coords">{{ number_format($lat, 5) }}° , {{ number_format($lng, 5) }}°</span>
        </div>
    </div>

    @assets
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    @endassets

    @script
    <script>
        const el = document.getElementById('osm-display-{{ $record->id }}');
        if (el && !el.dataset.init) {
            el.dataset.init = '1';
            const map = L.map(el).setView([{{ $lat }}, {{ $lng }}], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap',
            }).addTo(map);
            L.marker([{{ $lat }}, {{ $lng }}]).addTo(map);
            setTimeout(() => map.invalidateSize(), 400);
        }
    </script>
    @endscript
@else
    <p class="osm-empty">📍 لم يُحدَّد موقع على الخريطة بعد</p>
@endif
