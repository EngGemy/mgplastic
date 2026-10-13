<?php

namespace App\Filament\Resources\PriceListResource\Pages;

use App\Filament\Concerns\ManagesPriceListCatalog;
use App\Filament\Resources\PriceListResource;
use App\Models\PriceList;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\URL;

class CreatePriceList extends CreateRecord
{
    use ManagesPriceListCatalog;

    protected static string $resource = PriceListResource::class;

    protected static ?string $title = 'عرض سعر جديد';

    public bool $printAfterSave = false;

    public bool $shareAfterSave = false;

    public function getSubheading(): ?string
    {
        return 'من الكتالوج اختَر تصنيفًا وأضِفه، أو صنفًا واحدًا. راجع السعر في العرض ثم احفظ أو أرسل واتساب.';
    }

    public function mount(): void
    {
        parent::mount();
        $this->loadAutomaticQuoteLines();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return PriceList::withCustomerSnapshot($data);
    }

    protected function getRedirectUrl(): string
    {
        if ($this->printAfterSave) {
            return route('admin.price-lists.print', $this->record);
        }

        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }

    protected function afterCreate(): void
    {
        $this->syncQuoteLines();
        $this->openWhatsAppIfRequested();

        if (! $this->shareAfterSave) {
            return;
        }

        $url = URL::temporarySignedRoute('price-lists.share', now()->addDays(30), $this->record);

        Notification::make()
            ->title('الرابط جاهز لمدة 30 يوماً')
            ->body($url)
            ->success()
            ->persistent()
            ->send();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'تم حفظ عرض السعر';
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()->label('حفظ'),
            Actions\Action::make('savePrint')
                ->label('حفظ وطباعة')
                ->color('gray')
                ->action(function () {
                    $this->printAfterSave = true;
                    $this->create();
                }),
            Actions\Action::make('saveWhatsApp')
                ->label('حفظ وإرسال واتساب')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->action(function () {
                    $this->whatsAppAfterSave = true;
                    $this->create();
                }),
            Actions\Action::make('saveShare')
                ->label('حفظ ونسخ الرابط')
                ->color('gray')
                ->action(function () {
                    $this->shareAfterSave = true;
                    $this->create();
                }),
            $this->getCancelFormAction()->label('إلغاء'),
        ];
    }
}
