<?php

namespace App\Filament\Resources\TermsConditionResource\Pages;

use App\Filament\Resources\TermsConditionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTermsCondition extends EditRecord
{
    protected static string $resource = TermsConditionResource::class;

    // Pre-fill the pseudo fields from translations
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var TermsCondition $record */
        $record = $this->record;

        $data['title_en']   = optional($record->translate('en'))->title;
        $data['content_en'] = optional($record->translate('en'))->content;
        $data['title_ar']   = optional($record->translate('ar'))->title;
        $data['content_ar'] = optional($record->translate('ar'))->content;

        return $data;
    }

    // Keep only base columns for update; translations handled afterSave
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return [
            'slug' => $data['slug'],
        ];
    }

    protected function afterSave(): void
    {
        /** @var TermsCondition $record */
        $record = $this->record;
        $data   = $this->form->getState();

        $record->translateOrNew('en')->title    = $data['title_en'] ?? '';
        $record->translateOrNew('en')->content  = $data['content_en'] ?? '';
        $record->translateOrNew('ar')->title    = $data['title_ar'] ?? '';
        $record->translateOrNew('ar')->content  = $data['content_ar'] ?? '';
        $record->save();
    }
}

