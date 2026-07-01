<div class="osm-wrap" wire:ignore>
    <p class="osm-hint">📍 انقر على الخريطة لتحديد موقع المتجر — OpenStreetMap</p>
    <div id="osm-map-{{ $field->getId() }}" class="osm-map"></div>
</div>

@assets
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
@endassets

@script
<script>
    const mapId = 'osm-map-{{ $field->getId() }}';
    const mapEl = document.getElementById(mapId);
    if (!mapEl || mapEl.dataset.initialized) return;
    mapEl.dataset.initialized = '1';

    const defaultLat = 32.8872;
    const defaultLng = 13.1913;

    let lat = parseFloat($wire.get('data.latitude')) || defaultLat;
    let lng = parseFloat($wire.get('data.longitude')) || defaultLng;

    const map = L.map(mapEl).setView([lat, lng], 13);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap',
    }).addTo(map);

    let marker = L.marker([lat, lng], { draggable: true }).addTo(map);

    function syncMarker(newLat, newLng) {
        if (isNaN(newLat) || isNaN(newLng)) return;
        marker.setLatLng([newLat, newLng]);
        map.panTo([newLat, newLng]);
    }

    map.on('click', (e) => {
        $wire.set('data.latitude', parseFloat(e.latlng.lat.toFixed(6)));
        $wire.set('data.longitude', parseFloat(e.latlng.lng.toFixed(6)));
        syncMarker(e.latlng.lat, e.latlng.lng);
    });

    marker.on('dragend', () => {
        const pos = marker.getLatLng();
        $wire.set('data.latitude', parseFloat(pos.lat.toFixed(6)));
        $wire.set('data.longitude', parseFloat(pos.lng.toFixed(6)));
    });

    $wire.watch('data.latitude', (value) => {
        syncMarker(parseFloat(value), parseFloat($wire.get('data.longitude')));
    });

    $wire.watch('data.longitude', (value) => {
        syncMarker(parseFloat($wire.get('data.latitude')), parseFloat(value));
    });

    setTimeout(() => map.invalidateSize(), 400);
</script>
@endscript
