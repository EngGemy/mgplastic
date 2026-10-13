<?php

namespace App\Filament\Resources\PriceListResource\Pages;

use App\Filament\Resources\PriceListResource;
use App\Models\PriceList;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\URL;

class EditPriceList extends EditRecord
{
    protected static string $resource = PriceListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('print')
                ->label('طباعة')
                ->icon('heroicon-o-printer')
                ->url(fn () => route('admin.price-lists.print', $this->record))
                ->openUrlInNewTab(),
            Actions\Action::make('share')
                ->label('رابط العميل')
                ->icon('heroicon-o-link')
                ->action(function () {
                    /** @var PriceList $record */
                    $record = $this->record;
                    $url = URL::temporarySignedRoute('price-lists.share', now()->addDays(30), $record);

                    Notification::make()
                        ->title('رابط العرض جاهز لمدة 30 يوماً')
                        ->body($url)
                        ->success()
                        ->persistent()
                        ->send();
                }),
            Actions\DeleteAction::make()->label('حذف'),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'تم تحديث قائمة الأسعار';
    }
}
