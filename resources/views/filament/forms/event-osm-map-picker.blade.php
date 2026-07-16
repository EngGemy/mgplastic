{{-- Premium OpenStreetMap location picker for Events --}}
@php
    $mapUid = 'evt-map-'.str_replace(['.', '#'], '-', $field->getId());
    $isAr = app()->getLocale() === 'ar';
@endphp

<div
    class="evt-loc"
    dir="{{ $isAr ? 'rtl' : 'ltr' }}"
    wire:ignore
    x-data="eventOsmPicker({
        mapId: '{{ $mapUid }}',
        defaultLat: 32.8872,
        defaultLng: 13.1913,
        isAr: {{ $isAr ? 'true' : 'false' }},
    })"
    x-init="init()"
>
    <div class="evt-loc__hero">
        <div class="evt-loc__hero-text">
            <div class="evt-loc__eyebrow">{{ $isAr ? 'موقع الفعالية' : 'Event location' }}</div>
            <h3 class="evt-loc__title">{{ $isAr ? 'حدّد المكان على الخريطة' : 'Pin the venue on the map' }}</h3>
            <p class="evt-loc__sub">
                {{ $isAr
                    ? 'ابحث بالعنوان، أو انقر على الخريطة، أو اسحب الدبوس — الإحداثيات تُحفظ تلقائياً ضمن النطاق الصحيح.'
                    : 'Search an address, click the map, or drag the pin — coordinates save automatically within valid ranges.' }}
            </p>
        </div>
        <div class="evt-loc__chips">
            <span class="evt-loc__chip" :class="valid ? 'is-ok' : 'is-bad'">
                <span class="evt-loc__chip-dot"></span>
                <span x-text="valid ? (isAr ? 'إحداثيات صالحة' : 'Valid coords') : (isAr ? 'إحداثيات غير صالحة' : 'Invalid coords')"></span>
            </span>
            <span class="evt-loc__chip evt-loc__chip--muted" x-show="placeLabel" x-text="placeLabel"></span>
        </div>
    </div>

    <div class="evt-loc__toolbar">
        <div class="evt-loc__search">
            <svg class="evt-loc__search-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16Z" stroke="currentColor" stroke-width="1.8"/>
                <path d="M21 21l-4.3-4.3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
            <input
                type="search"
                class="evt-loc__input"
                x-model="query"
                @keydown.enter.prevent="search()"
                :placeholder="isAr ? 'ابحث: طرابلس، بنغازي، قاعة، فندق…' : 'Search: Tripoli, Benghazi, hall, hotel…'"
                autocomplete="off"
            />
            <button type="button" class="evt-loc__btn evt-loc__btn--primary" @click="search()" :disabled="searching">
                <span x-show="!searching" x-text="isAr ? 'بحث' : 'Search'"></span>
                <span x-show="searching" x-text="isAr ? '…' : '…'"></span>
            </button>
        </div>

        <div class="evt-loc__actions">
            <button type="button" class="evt-loc__btn" @click="locateMe()" title="{{ $isAr ? 'موقعي الحالي' : 'My location' }}">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" aria-hidden="true">
                    <path d="M12 3v2M12 19v2M3 12h2M19 12h2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    <circle cx="12" cy="12" r="7" stroke="currentColor" stroke-width="1.8"/>
                    <circle cx="12" cy="12" r="2.2" fill="currentColor"/>
                </svg>
                <span x-text="isAr ? 'موقعي' : 'Locate'"></span>
            </button>
            <button type="button" class="evt-loc__btn" @click="recenter()" title="{{ $isAr ? 'العودة للعلامة' : 'Recenter' }}">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" aria-hidden="true">
                    <path d="M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11Z" stroke="currentColor" stroke-width="1.8"/>
                    <circle cx="12" cy="10" r="2.2" fill="currentColor"/>
                </svg>
                <span x-text="isAr ? 'تمركز' : 'Center'"></span>
            </button>
        </div>
    </div>

    <div class="evt-loc__results" x-show="results.length" x-cloak>
        <template x-for="(item, idx) in results" :key="item.place_id">
            <button type="button" class="evt-loc__result" @click="pickResult(item)">
                <span class="evt-loc__result-idx" x-text="idx + 1"></span>
                <span class="evt-loc__result-label" x-text="item.display_name"></span>
            </button>
        </template>
    </div>

    <div class="evt-loc__map-shell">
        <div :id="mapId" class="evt-loc__map"></div>
        <div class="evt-loc__map-hint">
            <span x-text="isAr ? 'انقر أو اسحب الدبوس' : 'Click or drag the pin'"></span>
        </div>
    </div>

    <div class="evt-loc__coords">
        <div class="evt-loc__coord">
            <label>{{ $isAr ? 'خط العرض' : 'Latitude' }}</label>
            <div class="evt-loc__coord-row">
                <input type="number" step="0.000001" min="-90" max="90" x-model.number="lat" @change="applyManual()" />
                <span class="evt-loc__range">−90 … 90</span>
            </div>
        </div>
        <div class="evt-loc__coord">
            <label>{{ $isAr ? 'خط الطول' : 'Longitude' }}</label>
            <div class="evt-loc__coord-row">
                <input type="number" step="0.000001" min="-180" max="180" x-model.number="lng" @change="applyManual()" />
                <span class="evt-loc__range">−180 … 180</span>
            </div>
        </div>
        <div class="evt-loc__coord evt-loc__coord--preview">
            <label>{{ $isAr ? 'معاينة' : 'Preview' }}</label>
            <a class="evt-loc__osm-link" :href="osmLink" target="_blank" rel="noopener">
                OpenStreetMap ↗
            </a>
        </div>
    </div>

    <p class="evt-loc__error" x-show="error" x-text="error" x-cloak></p>
</div>

@assets
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
    .evt-loc{
        --sea:#0f766e; --sea-deep:#0b4f4a; --sand:#c4a574; --ink:#12231f;
        --mist:#e8f3f1; --line:rgba(15,118,110,.14);
        font-family:'Cairo',ui-sans-serif,system-ui,sans-serif;
        color:var(--ink);
        border:1px solid var(--line);
        border-radius:22px;
        overflow:hidden;
        background:
            radial-gradient(1200px 280px at 100% -10%, rgba(196,165,116,.22), transparent 55%),
            linear-gradient(165deg, #f7fbfa 0%, #eef6f4 48%, #f8faf9 100%);
        box-shadow:0 18px 40px -28px rgba(11,79,74,.55);
    }
    .evt-loc__hero{
        display:flex; flex-wrap:wrap; gap:14px; justify-content:space-between; align-items:flex-end;
        padding:18px 18px 8px;
    }
    .evt-loc__eyebrow{
        font-size:11px; font-weight:800; letter-spacing:.08em; text-transform:uppercase;
        color:var(--sea); margin-bottom:4px;
    }
    .evt-loc__title{ margin:0; font-size:1.15rem; font-weight:800; letter-spacing:-.01em; }
    .evt-loc__sub{ margin:6px 0 0; font-size:13px; line-height:1.55; color:#4b635e; max-width:52ch; }
    .evt-loc__chips{ display:flex; flex-wrap:wrap; gap:8px; }
    .evt-loc__chip{
        display:inline-flex; align-items:center; gap:7px;
        padding:6px 11px; border-radius:999px; font-size:12px; font-weight:800;
        background:#fff; border:1px solid var(--line);
    }
    .evt-loc__chip-dot{ width:8px; height:8px; border-radius:50%; background:#94a3b8; }
    .evt-loc__chip.is-ok{ color:#0f766e; background:#ecfdf5; border-color:#a7f3d0; }
    .evt-loc__chip.is-ok .evt-loc__chip-dot{ background:#10b981; box-shadow:0 0 0 4px rgba(16,185,129,.18); }
    .evt-loc__chip.is-bad{ color:#b91c1c; background:#fef2f2; border-color:#fecaca; }
    .evt-loc__chip.is-bad .evt-loc__chip-dot{ background:#ef4444; }
    .evt-loc__chip--muted{ color:#64748b; font-weight:700; max-width:280px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

    .evt-loc__toolbar{
        display:flex; flex-wrap:wrap; gap:10px; align-items:center;
        padding:8px 18px 12px;
    }
    .evt-loc__search{
        flex:1 1 280px; display:flex; align-items:center; gap:8px;
        background:#fff; border:1px solid var(--line); border-radius:14px; padding:6px 6px 6px 12px;
        box-shadow:inset 0 1px 0 rgba(255,255,255,.8);
    }
    .evt-loc__search-icon{ width:18px; height:18px; color:#6b8a84; flex:0 0 auto; }
    .evt-loc__input{
        flex:1; border:0; outline:0; background:transparent; font:inherit; font-size:14px; min-width:0;
        color:var(--ink);
    }
    .evt-loc__actions{ display:flex; gap:8px; flex-wrap:wrap; }
    .evt-loc__btn{
        display:inline-flex; align-items:center; gap:6px;
        border:1px solid var(--line); background:#fff; color:var(--sea-deep);
        border-radius:12px; padding:9px 12px; font:inherit; font-size:13px; font-weight:800;
        cursor:pointer; transition:transform .15s ease, box-shadow .15s ease, background .15s ease;
    }
    .evt-loc__btn:hover{ transform:translateY(-1px); box-shadow:0 8px 18px -12px rgba(11,79,74,.45); }
    .evt-loc__btn:disabled{ opacity:.55; cursor:wait; transform:none; box-shadow:none; }
    .evt-loc__btn--primary{
        background:linear-gradient(135deg, var(--sea), var(--sea-deep));
        color:#fff; border-color:transparent;
        box-shadow:0 10px 22px -14px rgba(15,118,110,.9);
    }

    .evt-loc__results{
        margin:0 18px 10px; display:grid; gap:6px;
        max-height:160px; overflow:auto; padding:2px;
    }
    .evt-loc__result{
        display:flex; gap:10px; align-items:flex-start; text-align:start;
        width:100%; border:1px solid var(--line); background:#fff; border-radius:12px;
        padding:10px 12px; cursor:pointer; font:inherit; color:inherit;
        transition:border-color .15s ease, background .15s ease;
    }
    .evt-loc__result:hover{ border-color:#99d5cb; background:#f3fbf9; }
    .evt-loc__result-idx{
        flex:0 0 auto; width:22px; height:22px; border-radius:8px;
        display:grid; place-items:center; font-size:11px; font-weight:800;
        background:var(--mist); color:var(--sea);
    }
    .evt-loc__result-label{ font-size:13px; line-height:1.45; font-weight:600; }

    .evt-loc__map-shell{
        position:relative; margin:0 18px; border-radius:18px; overflow:hidden;
        border:1px solid var(--line); box-shadow:0 16px 36px -24px rgba(11,79,74,.55);
        background:#d7ebe6;
    }
    .evt-loc__map{ height:360px; width:100%; }
    .evt-loc__map-hint{
        position:absolute; inset-inline-start:12px; bottom:12px; z-index:500;
        background:rgba(18,35,31,.78); color:#fff; backdrop-filter:blur(8px);
        font-size:12px; font-weight:700; padding:7px 11px; border-radius:999px;
        pointer-events:none;
    }

    .evt-loc__coords{
        display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px;
        padding:14px 18px 18px;
    }
    @media (max-width:860px){ .evt-loc__coords{ grid-template-columns:1fr; } .evt-loc__map{ height:300px; } }
    .evt-loc__coord{
        background:#fff; border:1px solid var(--line); border-radius:14px; padding:12px;
    }
    .evt-loc__coord label{
        display:block; font-size:11px; font-weight:800; color:#6b8a84;
        letter-spacing:.04em; text-transform:uppercase; margin-bottom:8px;
    }
    .evt-loc__coord-row{ display:flex; align-items:center; gap:8px; }
    .evt-loc__coord input{
        flex:1; min-width:0; border:1px solid #d7e5e1; border-radius:10px;
        padding:9px 10px; font:inherit; font-size:14px; font-weight:700; color:var(--ink);
        background:#f8fbfa;
    }
    .evt-loc__coord input:focus{ outline:2px solid rgba(15,118,110,.25); border-color:#0f766e; }
    .evt-loc__range{ font-size:11px; color:#94a3b8; font-weight:700; white-space:nowrap; }
    .evt-loc__coord--preview{ display:flex; flex-direction:column; justify-content:space-between; }
    .evt-loc__osm-link{
        display:inline-flex; align-items:center; justify-content:center;
        margin-top:4px; padding:10px 12px; border-radius:10px; text-decoration:none;
        background:linear-gradient(135deg,#134e4a,#0f766e); color:#fff; font-weight:800; font-size:13px;
    }
    .evt-loc__error{
        margin:0 18px 16px; color:#b91c1c; font-size:13px; font-weight:700;
        background:#fef2f2; border:1px solid #fecaca; border-radius:12px; padding:10px 12px;
    }
    [x-cloak]{ display:none !important; }
</style>
@endassets

@script
<script>
    const wire = $wire;

    Alpine.data('eventOsmPicker', (cfg) => ({
        mapId: cfg.mapId,
        defaultLat: cfg.defaultLat,
        defaultLng: cfg.defaultLng,
        isAr: cfg.isAr,
        map: null,
        marker: null,
        lat: cfg.defaultLat,
        lng: cfg.defaultLng,
        query: '',
        results: [],
        searching: false,
        error: '',
        placeLabel: '',
        syncing: false,

        get valid() {
            return this.lat >= -90 && this.lat <= 90 && this.lng >= -180 && this.lng <= 180;
        },

        get osmLink() {
            return `https://www.openstreetmap.org/?mlat=${this.lat}&mlon=${this.lng}#map=16/${this.lat}/${this.lng}`;
        },

        init() {
            const boot = () => {
                if (!window.L) {
                    setTimeout(boot, 50);
                    return;
                }
                this.bootMap();
            };
            boot();
        },

        clamp(lat, lng) {
            const la = Math.max(-90, Math.min(90, Number(lat)));
            const ln = Math.max(-180, Math.min(180, Number(lng)));
            return {
                lat: Number.isFinite(la) ? Number(la.toFixed(6)) : this.defaultLat,
                lng: Number.isFinite(ln) ? Number(ln.toFixed(6)) : this.defaultLng,
            };
        },

        readWire() {
            const wLat = parseFloat(wire.get('data.latitude'));
            const wLng = parseFloat(wire.get('data.longitude'));
            return this.clamp(
                Number.isFinite(wLat) ? wLat : this.defaultLat,
                Number.isFinite(wLng) ? wLng : this.defaultLng,
            );
        },

        writeWire(lat, lng) {
            const c = this.clamp(lat, lng);
            this.syncing = true;
            this.lat = c.lat;
            this.lng = c.lng;
            wire.set('data.latitude', c.lat);
            wire.set('data.longitude', c.lng);
            queueMicrotask(() => { this.syncing = false; });
        },

        bootMap() {
            const el = document.getElementById(this.mapId);
            if (!el || el.dataset.ready === '1') return;
            el.dataset.ready = '1';

            const start = this.readWire();
            this.lat = start.lat;
            this.lng = start.lng;

            // Persist defaults if empty / invalid (e.g. user typed 1155)
            this.writeWire(start.lat, start.lng);

            this.map = L.map(el, {
                zoomControl: true,
                attributionControl: true,
            }).setView([this.lat, this.lng], 13);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            }).addTo(this.map);

            const icon = L.divIcon({
                className: '',
                html: `<div style="
                    width:28px;height:28px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);
                    background:linear-gradient(135deg,#0f766e,#134e4a);border:3px solid #fff;
                    box-shadow:0 8px 18px rgba(15,118,110,.45);"></div>`,
                iconSize: [28, 28],
                iconAnchor: [14, 28],
            });

            this.marker = L.marker([this.lat, this.lng], { draggable: true, icon }).addTo(this.map);

            this.map.on('click', (e) => {
                this.placeLabel = '';
                this.error = '';
                this.writeWire(e.latlng.lat, e.latlng.lng);
                this.marker.setLatLng([this.lat, this.lng]);
            });

            this.marker.on('dragend', () => {
                const pos = this.marker.getLatLng();
                this.placeLabel = '';
                this.error = '';
                this.writeWire(pos.lat, pos.lng);
            });

            wire.watch('data.latitude', (value) => {
                if (this.syncing) return;
                const c = this.clamp(value, wire.get('data.longitude'));
                this.lat = c.lat;
                this.lng = c.lng;
                this.marker.setLatLng([c.lat, c.lng]);
                this.map.panTo([c.lat, c.lng]);
            });

            wire.watch('data.longitude', (value) => {
                if (this.syncing) return;
                const c = this.clamp(wire.get('data.latitude'), value);
                this.lat = c.lat;
                this.lng = c.lng;
                this.marker.setLatLng([c.lat, c.lng]);
                this.map.panTo([c.lat, c.lng]);
            });

            setTimeout(() => this.map.invalidateSize(), 250);
            setTimeout(() => this.map.invalidateSize(), 700);
        },

        applyManual() {
            this.error = '';
            if (!this.valid) {
                this.error = this.isAr
                    ? 'خط العرض يجب أن يكون بين −90 و 90، وخط الطول بين −180 و 180.'
                    : 'Latitude must be −90…90 and longitude −180…180.';
            }
            const c = this.clamp(this.lat, this.lng);
            this.writeWire(c.lat, c.lng);
            this.marker?.setLatLng([c.lat, c.lng]);
            this.map?.panTo([c.lat, c.lng]);
        },

        recenter() {
            if (!this.map) return;
            this.map.setView([this.lat, this.lng], Math.max(this.map.getZoom(), 14));
            this.marker?.setLatLng([this.lat, this.lng]);
        },

        locateMe() {
            this.error = '';
            if (!navigator.geolocation) {
                this.error = this.isAr ? 'المتصفح لا يدعم تحديد الموقع.' : 'Geolocation is not supported.';
                return;
            }
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    this.placeLabel = this.isAr ? 'موقعك الحالي' : 'Current location';
                    this.writeWire(pos.coords.latitude, pos.coords.longitude);
                    this.marker?.setLatLng([this.lat, this.lng]);
                    this.map?.setView([this.lat, this.lng], 15);
                },
                () => {
                    this.error = this.isAr ? 'تعذّر الحصول على موقعك. اسمح بالوصول للموقع أو حدّد يدوياً.' : 'Could not get your location.';
                },
                { enableHighAccuracy: true, timeout: 10000 },
            );
        },

        async search() {
            const q = (this.query || '').trim();
            if (q.length < 2) return;
            this.searching = true;
            this.error = '';
            this.results = [];
            try {
                const url = new URL('https://nominatim.openstreetmap.org/search');
                url.searchParams.set('format', 'json');
                url.searchParams.set('q', q);
                url.searchParams.set('limit', '6');
                url.searchParams.set('addressdetails', '0');
                // Bias toward Libya / MENA
                url.searchParams.set('countrycodes', 'ly,eg,tn,dz,ma');
                url.searchParams.set('accept-language', this.isAr ? 'ar' : 'en');

                const res = await fetch(url.toString(), {
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok) throw new Error('search failed');
                this.results = await res.json();
                if (!this.results.length) {
                    this.error = this.isAr ? 'لا نتائج — جرّب اسم مدينة أو معلم أوضح.' : 'No results — try a clearer place name.';
                }
            } catch (e) {
                this.error = this.isAr ? 'فشل البحث. تحقق من الاتصال وحاول مجدداً.' : 'Search failed. Check your connection.';
            } finally {
                this.searching = false;
            }
        },

        pickResult(item) {
            const lat = parseFloat(item.lat);
            const lng = parseFloat(item.lon);
            this.placeLabel = item.display_name;
            this.results = [];
            this.query = item.display_name.split(',')[0] || this.query;
            this.writeWire(lat, lng);
            this.marker?.setLatLng([this.lat, this.lng]);
            this.map?.setView([this.lat, this.lng], 15);

            // Help fill address if empty
            try {
                const currentAddress = (wire.get('data.address') || '').trim();
                if (!currentAddress && item.display_name) {
                    wire.set('data.address', item.display_name);
                }
            } catch (_) {}
        },
    }));
</script>
@endscript
