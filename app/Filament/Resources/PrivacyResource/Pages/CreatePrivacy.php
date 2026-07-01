<?php
// app/Filament/Resources/PrivacyResource/Pages/CreatePrivacy.php
namespace App\Filament\Resources\PrivacyResource\Pages;

use App\Filament\Resources\PrivacyResource;
use App\Models\Privacy;
use Filament\Resources\Pages\CreateRecord;

class CreatePrivacy extends CreateRecord
{
    protected static string $resource = PrivacyResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            'slug' => $data['slug'] ?? null,
        ];
    }

    protected function afterCreate(): void
    {
        /** @var Privacy $record */
        $record = $this->record;
        $data   = $this->form->getState();

        $record->translateOrNew('en')->title   = $data['title_en'] ?? '';
        $record->translateOrNew('en')->content = $data['content_en'] ?? '';
        $record->translateOrNew('ar')->title   = $data['title_ar'] ?? '';
        $record->translateOrNew('ar')->content = $data['content_ar'] ?? '';
        $record->save();
    }
}
