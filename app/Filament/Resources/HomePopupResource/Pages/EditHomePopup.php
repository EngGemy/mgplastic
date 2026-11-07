<?php

namespace App\Filament\Resources\HomePopupResource\Pages;

use App\Filament\Resources\HomePopupResource;
use App\Models\HomePopup;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;

class EditHomePopup extends EditRecord
{
    protected static string $resource = HomePopupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('toggle_active')
                ->label(fn (): string => $this->getRecord()->is_active ? 'إيقاف البوب أب' : 'تفعيل البوب أب')
                ->icon(fn (): string => $this->getRecord()->is_active ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
                ->color(fn (): string => $this->getRecord()->is_active ? 'warning' : 'success')
                ->requiresConfirmation()
                ->modalHeading(fn (): string => $this->getRecord()->is_active ? 'إيقاف البوب أب؟' : 'تفعيل البوب أب؟')
                ->modalDescription(fn (): string => $this->getRecord()->is_active
                    ? 'هيختفي من التطبيق فوراً (data.popup = null).'
                    : 'هيظهر للجمهور المستهدف حسب الجدولة.')
                ->action(function (): void {
                    /** @var HomePopup $record */
                    $record = $this->getRecord();
                    $record->is_active = ! $record->is_active;
                    $record->save();
                    $this->refreshFormData(['is_active']);
                }),
            Actions\Action::make('preview_payload')
                ->label('شكل JSON للتطبيق')
                ->icon('heroicon-o-code-bracket')
                ->color('info')
                ->modalHeading(fn (): string => 'معاينة data.popup · #'.$this->getRecord()->id)
                ->modalDescription('ده نفس الـ JSON اللي الموبايل بيقراه من home/layout.')
                ->modalContent(fn (): HtmlString => HomePopupResource::jsonPreviewHtml($this->getRecord()))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('إغلاق'),
            Actions\DeleteAction::make()->label('حذف'),
        ];
    }

    public function getTitle(): string
    {
        return 'تعديل البوب أب';
    }

    public function getHeading(): string
    {
        /** @var HomePopup $record */
        $record = $this->getRecord();

        return ($record->title_ar ?: 'بوب أب #'.$record->id).' · v'.$record->version;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['rebroadcast'], $data['stat_views'], $data['stat_clicks'], $data['stat_ctr'], $data['stat_dismiss'], $data['stat_buttons']);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var HomePopup $record */
        $record = $this->getRecord();

        if ((bool) ($this->data['rebroadcast'] ?? false)) {
            $record->bumpVersion();
        }

        $record->refreshImageRatioFromFile();
        if ($record->isDirty('image_ratio')) {
            $record->saveQuietly();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'تم حفظ البوب أب';
    }
}
