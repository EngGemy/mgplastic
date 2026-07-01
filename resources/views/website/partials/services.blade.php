<section id="services" style="background:#fff">
    <div class="sec-inner">
        <div style="text-align:center;margin-bottom:48px" class="reveal">
            <span class="sec-eyebrow">Our Services</span>
            <h2 class="sec-h2" style="font-family:'Amiri',serif">خدماتنا</h2>
            <div class="sec-en">// what_we_offer.beyond_the_product</div>
        </div>
        <div class="svc-grid">
            @foreach($services as $service)
                <div class="svc-card reveal">
                    <div class="svc-icon"><i class="ti {{ $service->icon }}"></i></div>
                    <div class="svc-ar">{{ $service->title_ar }}</div>
                    @if($service->subtitle_en)
                        <div class="svc-mono fm">{{ $service->subtitle_en }}</div>
                    @endif
                    @if($service->description_ar)
                        <p class="svc-desc">{{ $service->description_ar }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</section>
