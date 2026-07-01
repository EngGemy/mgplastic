<?php
// app/Filament/Resources/PrivacyResource/Pages/EditPrivacy.php
namespace App\Filament\Resources\PrivacyResource\Pages;

use App\Filament\Resources\PrivacyResource;
use App\Models\Privacy;
use Filament\Resources\Pages\EditRecord;

class EditPrivacy extends EditRecord
{
    protected static string $resource = PrivacyResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Privacy $record */
        $record = $this->record;

        $data['title_en']   = optional($record->translate('en'))->title;
        $data['content_en'] = optional($record->translate('en'))->content;
        $data['title_ar']   = optional($record->translate('ar'))->title;
        $data['content_ar'] = optional($record->translate('ar'))->content;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return [
            'slug' => $data['slug'] ?? null,
        ];
    }

    protected function afterSave(): void
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
