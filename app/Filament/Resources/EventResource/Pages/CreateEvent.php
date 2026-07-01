<?php

namespace App\Filament\Resources\EventResource\Pages;

use App\Filament\Resources\EventResource;
use App\Models\Event;
use Filament\Resources\Pages\CreateRecord;

class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;

    protected function handleRecordCreation(array $data): Event
    {
        // Extract translations
        $enTitle = $data['title_en'] ?? '';
        $enDesc  = $data['description_en'] ?? '';
        $arTitle = $data['title_ar'] ?? '';
        $arDesc  = $data['description_ar'] ?? '';

        // Remove from base array so they don't go to base table
        unset($data['title_en'], $data['description_en'], $data['title_ar'], $data['description_ar']);

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
}
