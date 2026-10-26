@php
    $preview = $this->previewState();
@endphp

<x-filament-panels::page>
    <div class="mg-home-studio" dir="rtl" wire:key="home-studio">
        <header class="mg-home-studio__hero">
            <div>
                <span class="mg-home-studio__eyebrow">محتوى التطبيق · الرئيسية</span>
                <h2 class="mg-home-studio__title">سكاشن الرئيسية</h2>
                <p class="mg-home-studio__lead">
                    عدّل بانر «منتجات MG»، عنوان «الأقسام الرئيسية»، بطاقات الأنواع، وأيقونات البانر —
                    التغيير يظهر في الـ API فوراً بعد الحفظ.
                </p>
            </div>
            <div class="mg-home-studio__hero-chip" aria-hidden="true">
                <span>mg-cp</span>
                <strong>موبايل حي</strong>
            </div>
        </header>

        <div class="mg-home-studio__grid">
            <div class="mg-home-studio__editor">
                <form wire:submit="save" class="mg-home-studio__form">
                    {{ $this->form }}
                    <div class="mg-home-studio__actions">
                        <x-filament::button type="submit" size="lg" icon="heroicon-o-check-circle">
                            حفظ ونشر للتطبيق
                        </x-filament::button>
                        <span class="mg-home-studio__api">
                            GET /api/v1/mobile/home/layout
                        </span>
                    </div>
                </form>
            </div>

            <aside class="mg-home-studio__preview" wire:ignore.self>
                <div class="mg-home-studio__phone">
                    <div class="mg-home-studio__notch"></div>
                    <div class="mg-home-studio__screen">
                        <div class="mg-pv-store-label">{{ $preview['stores_title'] }}</div>

                        @if ($preview['banner_is_active'])
                            <div class="mg-pv-banner">
                                <div class="mg-pv-banner__copy">
                                    <strong>{{ $preview['banner_title'] }}</strong>
                                    <p>{{ $preview['banner_subtitle'] }}</p>
                                    <span class="mg-pv-banner__cta">{{ $preview['banner_cta_text'] }} ←</span>
                                </div>
                                <div class="mg-pv-banner__art" aria-hidden="true">
                                    <span></span><span></span><span></span>
                                </div>
                                @if (! empty($preview['highlights']))
                                    <div class="mg-pv-banner__icons">
                                        @foreach ($preview['highlights'] as $hl)
                                            <div>
                                                <i></i>
                                                <em>{{ $hl['label'] ?? '' }}</em>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endif

                        <div class="mg-pv-section-title">{{ $preview['main_sections_title'] }}</div>
                        <div class="mg-pv-types">
                            @forelse ($preview['product_types'] as $type)
                                <article>
                                    <div class="mg-pv-types__icon"></div>
                                    <strong>{{ $type['name'] ?? '' }}</strong>
                                    <small>{{ $type['subtitle'] ?? '' }}</small>
                                </article>
                            @empty
                                <p class="mg-pv-empty">أضف أقساماً من التبويب</p>
                            @endforelse
                        </div>

                        <div class="mg-pv-section-title">{{ $preview['new_products_title'] }}</div>
                        <div class="mg-pv-new">معاينة فيديو / عرض</div>
                    </div>
                </div>
                <p class="mg-home-studio__preview-note">معاينة حيّة — تتحدّث وأنت تكتب</p>
            </aside>
        </div>
    </div>
</x-filament-panels::page>
