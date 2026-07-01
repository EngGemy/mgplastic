<?php

namespace App\Filament\Resources\ConversionRuleResource\Pages;

use App\Filament\Resources\ConversionRuleResource;
use App\Models\ConversionRule;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateConversionRule extends CreateRecord
{
    protected static string $resource = ConversionRuleResource::class;

    public function mount(): void
    {
        Notification::make()
            ->title('قيمة النقاط تُحدَّد على كل منتج')
            ->body('هذه الصفحة لإعدادات فترة الصرف والإشعارات فقط — وليس لقيمة تحويل النقاط.')
            ->info()
            ->send();

        $settings = ConversionRule::globalSettings();

        $this->redirect(
            ConversionRuleResource::getUrl('edit', ['record' => $settings]),
            navigate: true
        );
    }
}
