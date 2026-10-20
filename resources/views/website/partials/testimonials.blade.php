@if(($testimonials ?? collect())->isNotEmpty())
<section id="testimonials" class="testimonials-section" aria-label="آراء المتعاملين مع MG">
    <div class="sec-inner">
        <div class="testimonials-head reveal">
            <span class="sec-eyebrow">Trusted Voices</span>
            <h2 class="sec-h2" style="font-family:'Amiri',serif">ماذا يقول المتعاملون</h2>
            <div class="sec-en">// five_star.real_libyan_network</div>
            <p class="testimonials-lead">سباكون وتجّار ومهندسون وصاحبوا مشاريع يقيّمون منتجات MG بخمس نجوم.</p>
        </div>
    </div>

    <div class="testimonials-marquee" data-testimonials-marquee>
        <div class="testimonials-track">
            @foreach($testimonials->concat($testimonials) as $t)
                <article class="testimonial-card {{ $t->is_featured ? 'is-featured' : '' }}">
                    <div class="testimonial-card__glow" aria-hidden="true"></div>
                    <div class="testimonial-card__top">
                        <div class="testimonial-avatar" aria-hidden="true">{{ $t->avatar_initials ?: $t->initials() }}</div>
                        <div class="testimonial-meta">
                            <div class="testimonial-name">{{ $t->name_ar }}</div>
                            <div class="testimonial-role">{{ $t->role_label_ar }}@if($t->city_ar) · {{ $t->city_ar }}@endif</div>
                        </div>
                        <div class="testimonial-stars" aria-label="تقييم 5 من 5">
                            @for($i = 0; $i < 5; $i++)
                                <span class="star" style="--d:{{ $i * 0.08 }}s">★</span>
                            @endfor
                        </div>
                    </div>
                    <p class="testimonial-body">«{{ $t->body_ar }}»</p>
                    @if($t->product_hint)
                        <div class="testimonial-product">
                            <span class="testimonial-product__dot"></span>
                            {{ $t->product_hint }}
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    </div>

    <div class="testimonials-fade testimonials-fade--left" aria-hidden="true"></div>
    <div class="testimonials-fade testimonials-fade--right" aria-hidden="true"></div>
</section>

<script>
(function () {
    var root = document.querySelector('[data-testimonials-marquee]');
    if (!root) return;
    var track = root.querySelector('.testimonials-track');
    if (!track) return;
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) { track.style.animation = 'none'; return; }
    // Pause on hover / touch for readability
    root.addEventListener('mouseenter', function () { track.style.animationPlayState = 'paused'; });
    root.addEventListener('mouseleave', function () { track.style.animationPlayState = 'running'; });
    root.addEventListener('touchstart', function () { track.style.animationPlayState = 'paused'; }, { passive: true });
    root.addEventListener('touchend', function () { track.style.animationPlayState = 'running'; }, { passive: true });
})();
</script>
@endif
