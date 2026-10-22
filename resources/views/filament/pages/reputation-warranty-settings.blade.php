@php
    $repOn = (bool) ($this->data['reputation_enabled'] ?? false);
    $warOn = (bool) ($this->data['warranty_enabled'] ?? false);
    $weightTotal = max(1, array_sum($this->weights));
    $weightLabels = $this->weightLabels();
    $activeBadges = collect($this->badgeCards)->where('active', true)->count();
    $totalPlumbers = array_sum($this->levelCounts);
@endphp

<x-filament-panels::page>
    <div class="mg-rep-studio" dir="rtl">
        {{-- Hero --}}
        <header class="mg-rep-hero">
            <div class="mg-rep-hero__grid" aria-hidden="true"></div>
            <div class="mg-rep-hero__copy">
                <span class="mg-rep-hero__kicker">سياسة تشغيل · نظام النقاط</span>
                <h1 class="mg-rep-hero__title">استوديو السمعة والضمان</h1>
                <p class="mg-rep-hero__lead">
                    كل مستوى له نية تشغيلية، وكل شارة لها معيار فوز.
                    هنا تضبطون <em>متى</em> يُفتح الضمان — لا مجرّد مفاتيح تشغيل.
                </p>
                <div class="mg-rep-hero__actions">
                    <a href="{{ $this->overviewUrl }}" class="mg-rep-btn mg-rep-btn--ghost">لوحة السمعة</a>
                    <a href="{{ $this->badgesUrl }}" class="mg-rep-btn mg-rep-btn--brass">إدارة الشارات</a>
                </div>
            </div>
            <div class="mg-rep-hero__status">
                <div class="mg-rep-pill {{ $repOn ? 'is-on' : 'is-off' }}">
                    <span class="mg-rep-pill__dot"></span>
                    السمعة {{ $repOn ? 'مفعّلة' : 'متوقفة' }}
                    <small>.env</small>
                </div>
                <div class="mg-rep-pill {{ $warOn ? 'is-on' : 'is-off' }}">
                    <span class="mg-rep-pill__dot"></span>
                    الضمان {{ $warOn ? 'مفعّل' : 'متوقف' }}
                    <small>.env</small>
                </div>
                <div class="mg-rep-stat">
                    <strong>{{ number_format($totalPlumbers) }}</strong>
                    <span>سباك مُقيَّم</span>
                </div>
                <div class="mg-rep-stat">
                    <strong>{{ $activeBadges }}</strong>
                    <span>شارة نشطة</span>
                </div>
            </div>
        </header>

        {{-- Level ladder --}}
        <section class="mg-rep-section">
            <div class="mg-rep-section__head">
                <div>
                    <span class="mg-rep-section__kicker">01 · سلم المستويات</span>
                    <h2 class="mg-rep-section__title">أربع درجات — أربع خطط</h2>
                    <p class="mg-rep-section__desc">الدرجات من <code>config/reputation.php</code>. السياسة هنا تشرح <em>لماذا</em> كل مستوى موجود.</p>
                </div>
            </div>

            <div class="mg-rep-levels">
                @foreach ($this->levelCards as $i => $level)
                    <article class="mg-rep-level mg-rep-level--{{ $level['metal'] }}" style="--i: {{ $i }}">
                        <div class="mg-rep-level__top">
                            <span class="mg-rep-level__medal" aria-hidden="true"></span>
                            <div>
                                <h3 class="mg-rep-level__name">{{ $level['label'] }}</h3>
                                <p class="mg-rep-level__intent">{{ $level['intent'] }}</p>
                            </div>
                            <span class="mg-rep-level__range">{{ $level['min'] }}–{{ $level['max'] }}</span>
                        </div>
                        <p class="mg-rep-level__plan">{{ $level['plan'] }}</p>
                        <ul class="mg-rep-level__unlocks">
                            @foreach ($level['unlocks'] as $u)
                                <li>{{ $u }}</li>
                            @endforeach
                        </ul>
                        <footer class="mg-rep-level__foot">
                            <span>{{ number_format($level['count']) }} سباك</span>
                            <span class="mg-rep-level__bar"><i style="width: {{ $totalPlumbers > 0 ? min(100, ($level['count'] / $totalPlumbers) * 100) : 0 }}%"></i></span>
                        </footer>
                    </article>
                @endforeach
            </div>
        </section>

        {{-- Weights --}}
        <section class="mg-rep-section mg-rep-section--split">
            <div class="mg-rep-section__head">
                <div>
                    <span class="mg-rep-section__kicker">02 · معادلة الدرجة</span>
                    <h2 class="mg-rep-section__title">أوزان السمعة</h2>
                    <p class="mg-rep-section__desc">مجموع الأوزان = {{ $weightTotal }}. التعديل من ملف الإعدادات على السيرفر.</p>
                </div>
            </div>

            <div class="mg-rep-weights">
                @foreach ($this->weights as $key => $value)
                    @php $pct = round(((int) $value / $weightTotal) * 100); @endphp
                    <div class="mg-rep-weight" style="--w: {{ $pct }}%">
                        <div class="mg-rep-weight__meta">
                            <span class="mg-rep-weight__label">{{ $weightLabels[$key] ?? $key }}</span>
                            <span class="mg-rep-weight__val">{{ (int) $value }} <small>({{ $pct }}%)</small></span>
                        </div>
                        <div class="mg-rep-weight__track"><i></i></div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Warranty gate form --}}
        <section class="mg-rep-section">
            <div class="mg-rep-section__head">
                <div>
                    <span class="mg-rep-section__kicker">03 · بوابة الضمان</span>
                    <h2 class="mg-rep-section__title">شروط الإصدار القابلة للحفظ</h2>
                    <p class="mg-rep-section__desc">هذه القيم تُخزَّن في قاعدة البيانات وتتجاوز إعدادات البيئة عند التوفر.</p>
                </div>
            </div>

            <form wire:submit="save" class="mg-rep-form">
                {{ $this->form }}

                <div class="mg-rep-form__footer">
                    <p class="mg-rep-form__note">
                        حد الإصدار اليومي: <strong>{{ (int) ($this->data['daily_issue_cap'] ?? 10) }}</strong>
                        · تبريد إعادة الإرسال: <strong>{{ (int) ($this->data['resend_cooldown_hours'] ?? 24) }}س</strong>
                        <span>(من config)</span>
                    </p>
                    <x-filament::button type="submit" icon="heroicon-o-check-badge" class="mg-rep-save">
                        حفظ سياسة الضمان
                    </x-filament::button>
                </div>
            </form>
        </section>

        {{-- Badges roadmap --}}
        <section class="mg-rep-section">
            <div class="mg-rep-section__head mg-rep-section__head--row">
                <div>
                    <span class="mg-rep-section__kicker">04 · خريطة الشارات</span>
                    <h2 class="mg-rep-section__title">كل شارة = سلوك تريدون مكافأته</h2>
                    <p class="mg-rep-section__desc">عدّلوا المعيار والندرة من صفحة الشارات — هنا نظرة سريعة على الخطة كاملة.</p>
                </div>
                <a href="{{ $this->badgesUrl }}" class="mg-rep-btn mg-rep-btn--ghost">فتح الشارات ←</a>
            </div>

            <div class="mg-rep-badges">
                @forelse ($this->badgeCards as $badge)
                    <a
                        href="{{ $badge['edit_url'] }}"
                        class="mg-rep-badge mg-rep-badge--{{ $badge['tier'] }} {{ $badge['active'] ? '' : 'is-off' }}"
                    >
                        <span class="mg-rep-badge__glyph">{{ $badge['glyph'] }}</span>
                        <span class="mg-rep-badge__name">{{ $badge['name'] }}</span>
                        <span class="mg-rep-badge__rule">{{ $badge['rule'] }}</span>
                        @unless ($badge['active'])
                            <span class="mg-rep-badge__off">موقوفة</span>
                        @endunless
                    </a>
                @empty
                    <p class="mg-rep-empty">لا توجد شارات بعد — أنشئوا الأولى من إدارة الشارات.</p>
                @endforelse
            </div>
        </section>
    </div>
</x-filament-panels::page>
