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

    protected static ?string $title = 'تعديل عرض السعر';

    public bool $printAfterSave = false;

    public function getSubheading(): ?string
    {
        /** @var PriceList $record */
        $record = $this->record;

        return $record->number;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return PriceList::withCustomerSnapshot($data);
    }

    protected function getRedirectUrl(): string
    {
        if ($this->printAfterSave) {
            return route('admin.price-lists.print', $this->record);
        }

        return parent::getRedirectUrl();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'تم تحديث عرض السعر';
    }

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
                    $url = URL::temporarySignedRoute('price-lists.share', now()->addDays(30), $this->record);

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

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()->label('حفظ'),
            Actions\Action::make('savePrint')
                ->label('حفظ وطباعة')
                ->color('gray')
                ->action(function () {
                    $this->printAfterSave = true;
                    $this->save();
                }),
            $this->getCancelFormAction()->label('رجوع'),
        ];
    }
}
