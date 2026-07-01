<section id="about" style="background:#fff">
    <div class="sec-inner">
        <div class="about-grid">
            <div class="reveal">
                <div class="about-img-wrap">
                    <div class="about-img" @if($settings->about_image_url) style="background:url('{{ $settings->about_image_url }}') center/cover" @endif>
                        @unless($settings->about_image_url)
                            <div style="text-align:center;color:rgba(255,255,255,.9)">
                                <div class="fm" style="font-size:3rem;font-weight:700;margin-bottom:8px">MG</div>
                                <div style="font-family:'Amiri',serif;font-size:1.4rem">مصنع أدوات السباكة</div>
                                <div class="fm" style="font-size:11px;opacity:.6;margin-top:4px;letter-spacing:.1em">TRIPOLI · LIBYA · SINCE 2010</div>
                            </div>
                        @endunless
                    </div>
                    @if($settings->about_badge_year)
                        <div class="about-badge">
                            <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--hint);letter-spacing:.1em;text-transform:uppercase;margin-bottom:4px">Est.</div>
                            <div style="font-family:'Amiri',serif;font-size:2rem;font-weight:700;color:var(--blue);line-height:1">{{ $settings->about_badge_year }}</div>
                            @if($settings->about_badge_text)
                                <div style="font-size:11px;color:var(--muted);margin-top:2px">{{ $settings->about_badge_text }}</div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
            <div class="reveal">
                @if($settings->about_eyebrow)
                    <span class="sec-eyebrow">{{ $settings->about_eyebrow }}</span>
                @endif
                @if($settings->about_title)
                    <h2 class="sec-h2" style="font-family:'Amiri',serif">{{ $settings->about_title }}</h2>
                @endif
                @if($settings->about_subtitle)
                    <div class="sec-en" style="margin-bottom:20px">{{ $settings->about_subtitle }}</div>
                @endif
                @foreach($settings->about_paragraphs ?? [] as $paragraph)
                    <p class="about-p">{{ $paragraph }}</p>
                @endforeach
                @if(!empty($settings->about_values))
                    <div class="about-vals">
                        @foreach($settings->about_values as $value)
                            <div class="about-val">
                                <div class="av-icon"><i class="ti {{ $value['icon'] ?? 'ti-star' }}"></i></div>
                                <div class="av-title">{{ $value['title'] ?? '' }}</div>
                                <div class="av-desc">{{ $value['desc'] ?? '' }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>
