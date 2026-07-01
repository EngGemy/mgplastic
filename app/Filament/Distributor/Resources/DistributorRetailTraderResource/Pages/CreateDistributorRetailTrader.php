<?php

namespace App\Filament\Distributor\Resources\DistributorRetailTraderResource\Pages;

use App\Filament\Distributor\Resources\DistributorRetailTraderResource;
use App\Filament\Resources\RetailTraderResource;
use Filament\Forms\Form;
use Filament\Resources\Pages\CreateRecord;

class CreateDistributorRetailTrader extends CreateRecord
{
    protected static string $resource = DistributorRetailTraderResource::class;

    protected static ?string $title = 'إضافة تاجر قطاعي';

    public function form(Form $form): Form
    {
        return RetailTraderResource::createWizardForm($form);
    }

    public function mount(): void
    {
        parent::mount();

        $this->form->fill([
            'parent_distributor_id' => auth()->id(),
            'is_independent' => false,
            'affiliation_type' => 'linked',
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['role'] = 'retail_trader';
        $data['parent_distributor_id'] = auth()->id();
        $data['is_approved'] = true;
        $data['is_active'] = true;
        $data['is_phone_verified'] = true;
        $data['password'] = bcrypt($data['password'] ?? str()->random(12));

        unset($data['affiliation_type']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
