@php
    $record = $this->getRecord();
    $window = $record?->redemptionWindowLabel() ?? '—';
    $active = (bool) ($record?->is_active);
@endphp

<x-filament-panels::page
    @class([
        'fi-resource-edit-record-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    <div class="mg-points-page" dir="rtl">
        <x-mg-ops-rail
            kicker="نظام النقاط · سياسة الصرف"
            title="متى وكيف يحوّل السباك نقاطه؟"
            lead="النقاط تُحسب <em>على المنتج</em>. هذه الصفحة تضبط فقط <em>نافذة الصرف</em> والحدود والإشعار — ليست مكان إضافة النقاط."
            :steps="[
                'حدّد قيمة النقاط من <strong>المنتجات</strong> (نسبة أو ثابت لكل وحدة).',
                'السباك يجمع النقاط من التوزيعات المؤكدة في التطبيق.',
                'هنا: فعّل الصرف، حدّد الفترة، الحد الأدنى/الأقصى، ورسالة الإشعار.',
                'بعد التحويل يظهر الرصيد في <strong>محفظة السباك</strong> ويمكن طلب السحب.',
            ]"
            note="حالة الآن: الصرف <strong>{{ $active ? 'مفعّل' : 'متوقف' }}</strong> · النافذة: <strong>{{ $window }}</strong> · حد أدنى: <strong>{{ number_format((int) ($record?->min_redeem_points ?? 0)) }}</strong> نقطة."
            :actions="[
                ['label' => 'تقرير المنتجات والنقاط', 'url' => \App\Filament\Pages\ProductsReport::getUrl(), 'tone' => 'ghost'],
                ['label' => 'إضافة منتج', 'url' => \App\Filament\Resources\ProductResource::getUrl('create'), 'tone' => 'brass'],
            ]"
        />

        <div class="mg-points-page__form">
            <x-filament-panels::form
                id="form"
                :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()"
                wire:submit="save"
            >
                {{ $this->form }}

                <x-filament-panels::form.actions
                    :actions="$this->getCachedFormActions()"
                    :full-width="$this->hasFullWidthFormActions()"
                />
            </x-filament-panels::form>
        </div>
    </div>

    <x-filament-panels::page.unsaved-data-changes-alert />
</x-filament-panels::page>
