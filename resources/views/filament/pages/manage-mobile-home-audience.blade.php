@php
    $preview = $this->previewState();
    $typeLabels = collect(config('home_layout.types', []))->mapWithKeys(fn ($m, $t) => [$t => $m['label'] ?? $t]);
    $roleLabels = $this->audienceRoleLabels();
    $nav = [
        ['label' => 'السباكين', 'url' => \App\Filament\Pages\ManageMobileHomePlumbers::getUrl(), 'on' => $this instanceof \App\Filament\Pages\ManageMobileHomePlumbers],
        ['label' => 'التجار', 'url' => \App\Filament\Pages\ManageMobileHomeTraders::getUrl(), 'on' => $this instanceof \App\Filament\Pages\ManageMobileHomeTraders],
        ['label' => 'مشترك / زائر', 'url' => \App\Filament\Pages\ManageMobileHomeShared::getUrl(), 'on' => $this instanceof \App\Filament\Pages\ManageMobileHomeShared],
    ];
@endphp

<x-filament-panels::page>
    <div class="mg-home-studio" dir="rtl" wire:key="home-studio-audience">
        <header class="mg-home-studio__hero">
            <div>
                <span class="mg-home-studio__eyebrow">رئيسية التطبيق · v{{ $preview['version'] }}</span>
                <h2 class="mg-home-studio__title">{{ $this->studioTitle() }}</h2>
                <p class="mg-home-studio__lead">{{ $this->studioLead() }}</p>
                <nav class="mg-home-studio__subnav" aria-label="أقسام الرئيسية">
                    @foreach ($nav as $item)
                        <a href="{{ $item['url'] }}" class="mg-home-studio__subnav-link {{ $item['on'] ? 'is-on' : '' }}">{{ $item['label'] }}</a>
                    @endforeach
                </nav>
            </div>
            <div class="mg-home-studio__hero-chip" aria-hidden="true">
                <span>layout version</span>
                <strong>{{ $preview['version'] }}</strong>
            </div>
        </header>

        <div class="mg-home-studio__grid">
            <div class="mg-home-studio__editor">
                <form wire:submit="save" class="mg-home-studio__form">
                    {{ $this->form }}
                    <div class="mg-home-studio__actions">
                        <x-filament::button type="submit" size="lg" icon="heroicon-o-rocket-launch">
                            حفظ ونشر
                        </x-filament::button>
                        <x-filament::button
                            type="button"
                            color="gray"
                            wire:click="resetToSampleDefaults"
                            wire:confirm="سيتم استبدال ترتيب الأقسام بالعينات الافتراضية لكل الأدوار. هل أنت متأكد؟"
                            icon="heroicon-o-arrow-path"
                        >
                            استعادة العينات
                        </x-filament::button>
                        <span class="mg-home-studio__api" dir="ltr">GET /api/v1/mobile/home/layout</span>
                    </div>
                </form>
            </div>

            <aside class="mg-home-studio__preview">
                @if (count($roleLabels) > 1)
                    <div class="mg-home-studio__role-tabs">
                        @foreach ($roleLabels as $role => $label)
                            <button
                                type="button"
                                wire:click="setPreviewRole('{{ $role }}')"
                                class="mg-home-studio__role-tab {{ $previewRole === $role ? 'is-on' : '' }}"
                            >{{ $label }}</button>
                        @endforeach
                    </div>
                @endif

                <div class="mg-home-studio__phone">
                    <div class="mg-home-studio__notch"></div>
                    <div class="mg-home-studio__screen">
                        <div class="mg-pv-header">Header ثابت · التطبيق</div>

                        @forelse ($preview['sections'] as $i => $section)
                            @php
                                $type = $section['type'] ?? '';
                                $label = $typeLabels[$type] ?? $type;
                            @endphp
                            <article class="mg-pv-section" style="--i: {{ $i % 12 }}">
                                <div class="mg-pv-section__top">
                                    <span class="mg-pv-section__idx">{{ $i + 1 }}</span>
                                    <div>
                                        <strong>{{ filled($section['title'] ?? null) ? $section['title'] : $label }}</strong>
                                        <em dir="ltr">{{ $section['key'] ?? '' }} · {{ $type }}</em>
                                    </div>
                                </div>
                                @if ($type === 'mg_banner' && ($preview['banner_is_active'] ?? true))
                                    <div class="mg-pv-banner mg-pv-banner--mini">
                                        <strong>{{ $preview['banner_title'] }}</strong>
                                        <p>{{ $preview['banner_subtitle'] }}</p>
                                        <span>{{ $preview['banner_cta_text'] }}</span>
                                    </div>
                                @elseif (in_array($type, ['wallet','search','reputation','points_summary','pending_receipts','low_stock'], true))
                                    <div class="mg-pv-pill">بيانات من التطبيق</div>
                                @else
                                    <div class="mg-pv-pill mg-pv-pill--api">محتوى API</div>
                                @endif
                            </article>
                        @empty
                            <p class="mg-pv-empty">لا أقسام ظاهرة — أضف أو فعّل أقساماً من اليسار.</p>
                        @endforelse
                    </div>
                </div>
                <p class="mg-home-studio__preview-note">معاينة · {{ $roleLabels[$previewRole] ?? $previewRole }}</p>
            </aside>
        </div>
    </div>
</x-filament-panels::page>
