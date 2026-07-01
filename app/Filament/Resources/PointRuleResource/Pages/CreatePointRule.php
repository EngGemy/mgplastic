<?php

namespace App\Filament\Resources\PointRuleResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\PointRuleResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreatePointRule extends CreateRecord
{
    protected static string $resource = PointRuleResource::class;

    public function mount(): void
    {
        Notification::make()
            ->title('قواعد النقاط أصبحت على مستوى كل منتج')
            ->body('حدّد النقاط ونوع التحويل (نسبة أو ثابت) من صفحة إضافة/تعديل المنتج.')
            ->info()
            ->send();

        $this->redirect(ProductResource::getUrl('create'), navigate: true);
    }
}
