<?php

namespace App\Filament\Resources\RetailTraderResource\Pages;

use App\Filament\Resources\RetailTraderResource;
use App\Filament\Concerns\HasStoreLocationForm;
use App\Models\User;
use Filament\Forms\Form;
use Filament\Resources\Pages\CreateRecord;

class CreateRetailTrader extends CreateRecord
{
    use HasStoreLocationForm;

    protected static string $resource = RetailTraderResource::class;

    protected static ?string $title = 'إضافة موزع قطاعي';

    public function form(Form $form): Form
    {
        return RetailTraderResource::createWizardForm($form);
    }

    public function mount(): void
    {
        parent::mount();

        $storeId = request()->query('store');

        $fill = [
            'country_id' => self::defaultLibyaCountryId(),
            'latitude' => 32.8872,
            'longitude' => 13.1913,
            'affiliation_type' => $storeId ? 'linked' : 'linked',
            'is_independent' => false,
        ];

        if ($storeId && User::where('id', $storeId)->where('role', 'wholesale_distributor')->exists()) {
            $fill['parent_distributor_id'] = (int) $storeId;
        }

        if (auth()->user()?->isWholesaleDistributor()) {
            $fill['parent_distributor_id'] = auth()->id();
            $fill['is_independent'] = false;
            $fill['affiliation_type'] = 'linked';
        }

        $this->form->fill($fill);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['role'] = 'retail_trader';
        $data['is_approved'] = true;
        $data['is_active'] = true;
        $data['is_phone_verified'] = true;

        if (auth()->user()?->isWholesaleDistributor()) {
            $data['parent_distributor_id'] = auth()->id();
            $data['is_independent'] = false;
        } elseif (empty($data['parent_distributor_id'])) {
            $data['is_independent'] = true;
        } else {
            $data['is_independent'] = false;
        }

        unset($data['affiliation_type']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'تم إضافة الموزع القطاعي بنجاح';
    }

    protected function getCreateFormAction(): \Filament\Actions\Action
    {
        return parent::getCreateFormAction()->label('حفظ الموزع القطاعي');
    }
}
