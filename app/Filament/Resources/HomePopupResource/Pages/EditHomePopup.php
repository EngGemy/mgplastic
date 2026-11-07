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
            Actions\Action::make('preview_payload')
                ->label('معاينة JSON')
                ->icon('heroicon-o-code-bracket')
                ->color('gray')
                ->modalHeading('شكل الـ popup اللي هيوصّل للتطبيق (عربي · guest)')
                ->modalContent(function (): HtmlString {
                    /** @var HomePopup $record */
                    $record = $this->getRecord();
                    $json = json_encode(
                        $record->toMobilePayload('guest', 'ar'),
                        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                    );

                    return new HtmlString(
                        '<pre style="direction:ltr;text-align:left;max-height:420px;overflow:auto;font-size:12px;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;">'
                        .e((string) $json)
                        .'</pre>'
                    );
                })
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
