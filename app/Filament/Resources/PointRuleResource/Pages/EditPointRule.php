<?php

namespace App\Filament\Resources\PointRuleResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\PointRuleResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPointRule extends EditRecord
{
    protected static string $resource = PointRuleResource::class;

    public function mount(int|string $record): void
    {
        Notification::make()
            ->title('قواعد النقاط أصبحت على مستوى كل منتج')
            ->body('عدّل النقاط وقيمة التحويل من صفحة المنتجات.')
            ->info()
            ->send();

        $this->redirect(ProductResource::getUrl('index'), navigate: true);
    }
}
