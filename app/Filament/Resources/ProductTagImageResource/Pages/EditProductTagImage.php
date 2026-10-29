<?php

namespace App\Filament\Resources\ProductTagImageResource\Pages;

use App\Filament\Resources\ProductTagImageResource;
use App\Models\ProductTag;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProductTagImage extends EditRecord
{
    protected static string $resource = ProductTagImageResource::class;

    public function getTitle(): string
    {
        return 'تغيير صورة التاج';
    }

    public function getHeading(): string
    {
        return 'صورة التاج: '.$this->record->name;
    }

    protected function afterSave(): void
    {
        /** @var ProductTag $record */
        $record = $this->record;
        $synced = ProductTagImageResource::syncImageToSameNameTags($record);
        if ($synced > 0) {
            \Filament\Notifications\Notification::make()
                ->success()
                ->title('تم تحديث الصورة')
                ->body("طُبّقت نفس الصورة على {$synced} تاج آخر بنفس الاسم.")
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('حذف التاج')
                ->visible(fn () => $this->record->products()->count() === 0),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'تم حفظ صورة التاج';
    }
}
