<?php

namespace App\Filament\Resources\SocialMediaResource\Pages;

use App\Filament\Resources\SocialMediaResource;
use App\Models\SocialMedia;
use Filament\Resources\Pages\EditRecord;

class EditSocialMedia extends EditRecord
{
    protected static string $resource = SocialMediaResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var SocialMedia $record */
        $record = $this->record;

        $data['name_en'] = optional($record->translate('en'))->name;
        $data['name_ar'] = optional($record->translate('ar'))->name;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Keep only base columns
        return [
            'platform' => $data['platform'] ?? null,
            'url'      => $data['url'] ?? null,
        ];
    }

    protected function afterSave(): void
    {
        /** @var SocialMedia $record */
        $record = $this->record;
        $data   = $this->form->getState();

        $record->translateOrNew('en')->name = $data['name_en'] ?? '';
        $record->translateOrNew('ar')->name = $data['name_ar'] ?? '';
        $record->save();
    }
}
