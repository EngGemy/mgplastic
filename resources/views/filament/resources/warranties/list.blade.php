@php
    $total = \App\Models\Warranty::query()->count();
    $active = \App\Models\Warranty::query()->where('status', 'active')->count();
    $settingsUrl = \App\Filament\Pages\ReputationWarrantySettings::getUrl();
@endphp

<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    <div class="mg-points-page" dir="rtl">
        <x-mg-ops-rail
            kicker="نظام النقاط · سجل الضمانات"
            title="الضمانات تُصدر من التطبيق — لا من هنا"
            lead="هذه قائمة تشغيلية لما أصدره السباكون المؤهّلون. السياسة (من يحق له الإصدار) تُضبط في <em>استوديو السمعة والضمان</em>."
            :steps="[
                'اضبط عتبات السمعة ومدة الضمان من استوديو السمعة.',
                'السباك المؤهّل يصدر ضماناً لعميله من تطبيق السباك.',
                'يظهر السجل هنا مع الكود، PDF، وإعادة واتساب.',
                'عند مشكلة عميل → تفتح <strong>مطالبة ضمان</strong> للمعالجة.',
            ]"
            note="السجل: <strong>{{ number_format($total) }}</strong> ضمان · منها <strong>{{ number_format($active) }}</strong> ساري."
            :actions="[
                ['label' => 'سياسة الإصدار', 'url' => $settingsUrl, 'tone' => 'brass'],
                ['label' => 'مطالبات الضمان', 'url' => \App\Filament\Resources\WarrantyClaimResource::getUrl('index'), 'tone' => 'ghost'],
            ]"
        />

        <div class="flex flex-col gap-y-6">
            <x-filament-panels::resources.tabs />
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>
