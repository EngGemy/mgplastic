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

        $data['title_en'] = optional($record->translate('en'))->title;
        $data['description_en'] = optional($record->translate('en'))->description;
        $data['title_ar'] = optional($record->translate('ar'))->title;
        $data['description_ar'] = optional($record->translate('ar'))->description;

        $lat = isset($data['latitude']) ? (float) $data['latitude'] : 32.8872;
        $lng = isset($data['longitude']) ? (float) $data['longitude'] : 13.1913;
        if ($lat < -90 || $lat > 90) {
            $lat = 32.8872;
        }
        if ($lng < -180 || $lng > 180) {
            $lng = 13.1913;
        }
        $data['latitude'] = $lat;
        $data['longitude'] = $lng;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->sanitizeCoordinates($data);
    }

    /** Save translations */
    protected function handleRecordUpdate($record, array $data): Event
    {
        $enTitle = $data['title_en'] ?? '';
        $enDesc = $data['description_en'] ?? '';
        $arTitle = $data['title_ar'] ?? '';
        $arDesc = $data['description_ar'] ?? '';

        unset($data['title_en'], $data['description_en'], $data['title_ar'], $data['description_ar']);

        $data = $this->sanitizeCoordinates($data);

        $record->update($data);

        $record->translateOrNew('en')->title       = $enTitle;
        $record->translateOrNew('en')->description = $enDesc;
        $record->translateOrNew('ar')->title       = $arTitle;
        $record->translateOrNew('ar')->description = $arDesc;
        $record->save();

        return $record;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function sanitizeCoordinates(array $data): array
    {
        $lat = isset($data['latitude']) ? (float) $data['latitude'] : 32.8872;
        $lng = isset($data['longitude']) ? (float) $data['longitude'] : 13.1913;

        if (! is_finite($lat) || $lat < -90 || $lat > 90) {
            $lat = 32.8872;
        }
        if (! is_finite($lng) || $lng < -180 || $lng > 180) {
            $lng = 13.1913;
        }

        $data['latitude'] = round($lat, 6);
        $data['longitude'] = round($lng, 6);

        return $data;
    }
}
