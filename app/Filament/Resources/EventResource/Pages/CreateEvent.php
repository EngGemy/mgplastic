<?php

namespace App\Filament\Resources\EventResource\Pages;

use App\Filament\Resources\EventResource;
use App\Models\Event;
use Filament\Resources\Pages\CreateRecord;

class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->sanitizeCoordinates($data);
    }

    protected function handleRecordCreation(array $data): Event
    {
        // Extract translations
        $enTitle = $data['title_en'] ?? '';
        $enDesc = $data['description_en'] ?? '';
        $arTitle = $data['title_ar'] ?? '';
        $arDesc = $data['description_ar'] ?? '';

        // Remove from base array so they don't go to base table
        unset($data['title_en'], $data['description_en'], $data['title_ar'], $data['description_ar']);

        $data = $this->sanitizeCoordinates($data);

        /** @var Event $event */
        $event = Event::create($data);

        // Save translations
        $event->translateOrNew('en')->title       = $enTitle;
        $event->translateOrNew('en')->description = $enDesc;

        $event->translateOrNew('ar')->title       = $arTitle;
        $event->translateOrNew('ar')->description = $arDesc;

        $event->save();

        return $event;
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
