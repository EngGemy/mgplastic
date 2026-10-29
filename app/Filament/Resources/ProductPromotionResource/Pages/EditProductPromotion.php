<?php

namespace App\Filament\Resources\ProductPromotionResource\Pages;

use App\Filament\Resources\ProductPromotionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProductPromotion extends EditRecord
{
    protected static string $resource = ProductPromotionResource::class;

    public function getTitle(): string
    {
        return 'استوديو الدعاية';
    }

    public function getHeading(): string
    {
        return 'استوديو الدعاية';
    }

    public function getSubheading(): ?string
    {
        $type = match ($this->record->media_type) {
            'video' => $this->record->videoSource() === 'file' ? 'فيديو مرفوع على السيرفر' : 'فيديو برابط خارجي',
            'comparison' => 'مقارنة صورتين',
            default => 'إعلان صورة',
        };

        return $this->record->title.' · '.$type;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ProductPromotionResource::normalizeVideoPayload($data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('preview_video')
                ->label('فتح الفيديو')
                ->icon('heroicon-o-play')
                ->color('danger')
                ->url(fn () => $this->record->resolvedVideoUrl())
                ->openUrlInNewTab()
                ->visible(fn () => $this->record->media_type === 'video' && filled($this->record->resolvedVideoUrl())),
            Actions\DeleteAction::make()->label('حذف الإعلان'),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'تم تحديث الإعلان';
    }
}
