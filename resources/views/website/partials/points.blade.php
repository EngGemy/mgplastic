<section id="points" class="pts-bg" style="padding:80px 5vw">
    <div class="sec-inner">
        <div class="pts-grid">
            <div class="reveal">
                @if($settings->points_eyebrow)
                    <span style="font-family:'JetBrains Mono',monospace;font-size:10px;letter-spacing:.18em;color:rgba(255,255,255,.4);text-transform:uppercase;margin-bottom:12px;display:block">{{ $settings->points_eyebrow }}</span>
                @endif
                @if($settings->points_title)
                    <h2 style="font-family:'Amiri',serif;font-size:clamp(1.8rem,3.5vw,2.8rem);color:#fff;margin-bottom:8px">{{ $settings->points_title }}</h2>
                @endif
                @if($settings->points_subtitle)
                    <div class="fm" style="font-size:11px;color:rgba(255,255,255,.35);letter-spacing:.06em;margin-bottom:24px">{{ $settings->points_subtitle }}</div>
                @endif

                @if(!empty($settings->points_chain))
                    <div class="pts-chain">
                        @foreach($settings->points_chain as $index => $step)
                            @if($index > 0)
                                <div class="pchain-line"></div>
                            @endif
                            <div class="pchain-row">
                                <div class="pchain-dot" style="background:{{ $step['color'] ?? '#60a5fa' }};margin-top:5px"></div>
                                <div style="{{ $loop->last ? '' : 'padding-bottom:6px' }}">
                                    <div style="color:#fff;font-weight:700;font-size:14px">{{ $step['title'] ?? '' }}</div>
                                    <div class="fm" style="font-size:9px;color:rgba(255,255,255,.4);letter-spacing:.08em;text-transform:uppercase;margin-top:2px">{{ $step['subtitle'] ?? '' }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if(!empty($settings->points_features))
                    <div class="pts-features">
                        @foreach($settings->points_features as $feature)
                            <div class="pts-feat">
                                <div class="pts-feat-ar">{{ $feature['title'] ?? '' }}</div>
                                <div class="pts-feat-desc">{{ $feature['desc'] ?? '' }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div id="register" class="pts-form-card reveal">
                @include('website.partials.register-form')
            </div>
        </div>
    </div>
</section>
