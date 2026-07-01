<section id="catalog" class="catalog-bg">
    <div class="sec-inner">
        <div style="text-align:center;margin-bottom:36px" class="reveal">
            <span class="sec-eyebrow">Product Catalog</span>
            <h2 class="sec-h2" style="font-family:'Amiri',serif">كتالوج منتجاتنا</h2>
            <div class="sec-en">// premium_carousel_browse_500_products</div>
        </div>

        <div class="cat-tabs reveal" id="cat-tabs">
            <button class="cat-tab active" data-cat="all" onclick="filterCat(this,'all')">الكل</button>
            @foreach($categories as $category)
                <button class="cat-tab" data-cat="{{ $category->slug }}" onclick="filterCat(this,'{{ $category->slug }}')">{{ $category->name }}</button>
            @endforeach
        </div>

        <div class="prod-carousel-wrap reveal" id="prod-carousel-wrap">
            <div class="prod-carousel-head">
                <div class="prod-carousel-count" id="catalog-counter">جاري التحميل...</div>
                <div class="prod-carousel-arrows">
                    <button type="button" class="pc-arrow" id="pc-prev" aria-label="السابق" onclick="catalogPrev()">
                        <i class="ti ti-chevron-right"></i>
                    </button>
                    <button type="button" class="pc-arrow" id="pc-next" aria-label="التالي" onclick="catalogNext()">
                        <i class="ti ti-chevron-left"></i>
                    </button>
                </div>
            </div>

            <div class="prod-carousel-stage">
                <div class="prod-carousel-fade prod-carousel-fade-right"></div>
                <div class="prod-carousel-fade prod-carousel-fade-left"></div>
                <div class="prod-carousel-viewport" id="prod-carousel-viewport">
                    <div class="prod-carousel-track" id="prod-carousel-track"></div>
                </div>
            </div>

            <div class="prod-carousel-dots" id="pc-dots"></div>
            <div class="catalog-loading" id="catalog-loading" hidden>
                <span class="catalog-loading-spin"></span>
                <span>تحميل المزيد من المنتجات...</span>
            </div>
        </div>

        @if($settings->catalog_pdf_url)
            <div style="text-align:center;margin-top:32px" class="reveal">
                <a href="{{ $settings->catalog_pdf_url }}" class="btn-primary" style="margin:0 auto" target="_blank">
                    <i class="ti ti-download"></i> تحميل الكتالوج الكامل PDF
                </a>
            </div>
        @endif
    </div>
</section>
