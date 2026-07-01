<?php

namespace App\Filament\Resources\SocialMediaResource\Pages;

use App\Filament\Resources\SocialMediaResource;
use App\Models\SocialMedia;
use Filament\Resources\Pages\CreateRecord;

class CreateSocialMedia extends CreateRecord
{
    protected static string $resource = SocialMediaResource::class;

    // Only base fields mass-assigned; translations set after create
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            'platform' => $data['platform'] ?? null,
            'url'      => $data['url'] ?? null,
        ];
    }

    protected function afterCreate(): void
    {
        /** @var SocialMedia $record */
        $record = $this->record;
        $data   = $this->form->getState();

        // Astrotomic Translatable: name only
        $record->translateOrNew('en')->name = $data['name_en'] ?? '';
        $record->translateOrNew('ar')->name = $data['name_ar'] ?? '';
        $record->save();
    }
}
