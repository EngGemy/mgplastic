@php
    $count = \App\Models\Badge::query()->count();
    $active = \App\Models\Badge::query()->where('is_active', true)->count();
@endphp

<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    <div class="mg-points-page" dir="rtl">
        <x-mg-ops-rail
            kicker="نظام النقاط · كتالوج الشارات"
            title="أنت تضبط كل شارة وتطبّقها بنفسك"
            lead="أنشئ شارات جديدة بالتفصيل (اسم، وصف، ندرة، معيار). اختر <em>منح يدوي فقط</em> أو معياراً تلقائياً — وفي الحالتين يمكنك <em>منحها أو سحبها يدوياً</em> لأي سباك."
            :steps="[
                'شارة جديدة → املأ الهوية والمظهر والوصف.',
                'اختر كيف تُمنح: يدوي فقط، أو شرط تلقائي (طلبات، نقاط، مستوى…).',
                'احفظ، ثم من تعديل الشارة → تبويب المستلمين → منح لسباك.',
                'أو من لوحة السمعة → صف السباك → منح شارة.',
            ]"
            note="الكتالوج الحالي: <strong>{{ number_format($count) }}</strong> شارة · منها <strong>{{ number_format($active) }}</strong> مفعّلة."
            :actions="[
                ['label' => 'شارة جديدة', 'url' => \App\Filament\Resources\BadgeResource::getUrl('create'), 'tone' => 'brass'],
                ['label' => 'استوديو السمعة', 'url' => \App\Filament\Pages\ReputationWarrantySettings::getUrl(), 'tone' => 'ghost'],
            ]"
        />

        <div class="flex flex-col gap-y-6">
            <x-filament-panels::resources.tabs />
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>
