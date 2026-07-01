<?php

namespace App\Filament\Resources\EventResource\Pages;

use App\Filament\Resources\EventResource;
use App\Models\Event;
use Filament\Resources\Pages\EditRecord;

class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    /** Prefill virtual translation fields on edit */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Event $record */
        $record = $this->record;

        $data['title_en']       = optional($record->translate('en'))->title;
        $data['description_en'] = optional($record->translate('en'))->description;
        $data['title_ar']       = optional($record->translate('ar'))->title;
        $data['description_ar'] = optional($record->translate('ar'))->description;

        return $data;
    }

    /** Save translations */
    protected function handleRecordUpdate($record, array $data): Event
    {
        $enTitle = $data['title_en'] ?? '';
        $enDesc  = $data['description_en'] ?? '';
        $arTitle = $data['title_ar'] ?? '';
        $arDesc  = $data['description_ar'] ?? '';

        unset($data['title_en'], $data['description_en'], $data['title_ar'], $data['description_ar']);

        $record->update($data);

        $record->translateOrNew('en')->title       = $enTitle;
        $record->translateOrNew('en')->description = $enDesc;
        $record->translateOrNew('ar')->title       = $arTitle;
        $record->translateOrNew('ar')->description = $arDesc;
        $record->save();

        return $record;
    }
}
