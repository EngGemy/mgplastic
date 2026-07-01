<section id="catalog" class="catalog-bg">
    <div class="sec-inner">
        <div style="text-align:center;margin-bottom:36px" class="reveal">
            <span class="sec-eyebrow">Product Catalog</span>
            <h2 class="sec-h2" style="font-family:'Amiri',serif">كتالوج منتجاتنا</h2>
            <div class="sec-en">// browse_filter_download_catalog</div>
        </div>

        <div class="cat-tabs reveal" id="cat-tabs">
            <button class="cat-tab active" data-cat="all" onclick="filterCat(this,'all')">الكل</button>
            @foreach($categories as $category)
                <button class="cat-tab" data-cat="{{ $category->slug }}" onclick="filterCat(this,'{{ $category->slug }}')">{{ $category->name }}</button>
            @endforeach
        </div>

        <div class="prod-grid" id="prod-grid"></div>

        @if($settings->catalog_pdf_url)
            <div style="text-align:center;margin-top:32px" class="reveal">
                <a href="{{ $settings->catalog_pdf_url }}" class="btn-primary" style="margin:0 auto" target="_blank">
                    <i class="ti ti-download"></i> تحميل الكتالوج الكامل PDF
                </a>
            </div>
        @endif
    </div>
</section>
