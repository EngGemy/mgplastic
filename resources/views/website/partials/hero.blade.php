<div class="slider-wrap" id="slider">
    @forelse($sliders as $index => $slide)
        <div class="slide {{ $index === 0 ? 'active' : '' }}" id="sl{{ $index }}">
            <div class="slide-bg" style="{{ $slide->backgroundCss() }}"></div>
            <div class="slide-overlay"></div>
            <div class="slide-content">
                @if($slide->tag)
                    <span class="slide-tag">{{ $slide->tag }}</span>
                @endif
                @if($slide->title)
                    <h1 class="slide-h1">{!! nl2br(e($slide->title)) !!}</h1>
                @endif
                @if($slide->description)
                    <p class="slide-p">{{ $slide->description }}</p>
                @endif
                @if($slide->cta_primary_text || $slide->cta_secondary_text)
                    <div class="slide-btns">
                        @if($slide->cta_primary_text)
                            <a href="{{ $slide->cta_primary_url ?? '#catalog' }}" class="btn-white">
                                <i class="ti ti-box"></i> {{ $slide->cta_primary_text }}
                            </a>
                        @endif
                        @if($slide->cta_secondary_text)
                            <a href="{{ $slide->cta_secondary_url ?? '#about' }}" class="btn-ghost">
                                <i class="ti ti-info-circle"></i> {{ $slide->cta_secondary_text }}
                            </a>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @empty
        <div class="slide active">
            <div class="slide-bg" style="background:linear-gradient(135deg,#0d2d6e 0%,#1a56db 100%)"></div>
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <span class="slide-tag">MG Plastic</span>
                <h1 class="slide-h1">مصنع أدوات السباكة<br>الأول في ليبيا</h1>
            </div>
        </div>
    @endforelse

    @if($sliders->count() > 1)
        <button class="slider-arrow arrow-prev" onclick="prevSlide()" aria-label="السابق"><i class="ti ti-chevron-right"></i></button>
        <button class="slider-arrow arrow-next" onclick="nextSlide()" aria-label="التالي"><i class="ti ti-chevron-left"></i></button>
        <div class="slider-dots">
            @foreach($sliders as $index => $slide)
                <button class="sdot {{ $index === 0 ? 'active' : '' }}" onclick="goSlide({{ $index }})"></button>
            @endforeach
        </div>
    @endif
</div>
