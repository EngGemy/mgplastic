@php
    $open = \App\Models\WarrantyClaim::query()->whereIn('status', ['open', 'in_review'])->count();
    $total = \App\Models\WarrantyClaim::query()->count();
@endphp

<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    <div class="mg-points-page" dir="rtl">
        <x-mg-ops-rail
            kicker="نظام النقاط · مطالبات الضمان"
            title="طابور معالجة شكاوى العملاء"
            lead="المطالبة تُفتح عندما يبلّغ العميل عن عيب على ضمان ساري. هنا تغيّر الحالة وتوثّق القرار — <em>لا تُنشأ المطالبات يدوياً من اللوحة</em>."
            :steps="[
                'العميل/السباك يفتح مطالبة مرتبطة بكود ضمان.',
                'تظهر هنا بحالة <strong>مفتوحة</strong> أو قيد المراجعة.',
                'افتح «معالجة»، حدّث الحالة، واكتب ملاحظة القرار.',
                'محلولة / مرفوضة تُغلق الطابور وتُظهر في السجل.',
            ]"
            note="الآن: <strong>{{ number_format($open) }}</strong> قيد المعالجة · إجمالي السجل <strong>{{ number_format($total) }}</strong>."
            :actions="[
                ['label' => 'سجل الضمانات', 'url' => \App\Filament\Resources\WarrantyResource::getUrl('index'), 'tone' => 'ghost'],
                ['label' => 'سياسة الضمان', 'url' => \App\Filament\Pages\ReputationWarrantySettings::getUrl(), 'tone' => 'brass'],
            ]"
        />

        <div class="flex flex-col gap-y-6">
            <x-filament-panels::resources.tabs />
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>
