<?php

namespace App\Filament\Resources\StoreResource\Pages;

use App\Filament\Resources\StoreResource;
use App\Services\StoreApprovalService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewStore extends ViewRecord
{
    protected static string $resource = StoreResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->load(['country', 'city', 'parentDistributor', 'socialLinks'])
            ->loadCount('retailTraders');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('approve')
                ->label('تفعيل المتجر')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn () => ! $this->record->is_approved)
                ->requiresConfirmation()
                ->modalHeading('تفعيل المتجر')
                ->modalDescription('سيتم اعتماد المتجر وإظهاره في الشبكة وإرسال إشعار لصاحبه.')
                ->action(function () {
                    app(StoreApprovalService::class)->approve($this->record, auth()->user());
                    Notification::make()->success()->title('تم تفعيل المتجر')->send();
                    $this->refreshFormData(['is_approved', 'is_active', 'approved_at']);
                }),

            Actions\EditAction::make()->label('تعديل المتجر'),

            Actions\Action::make('open_map')
                ->label('OpenStreetMap')
                ->icon('heroicon-o-map')
                ->color('info')
                ->url(fn () => $this->record->mapUrl())
                ->openUrlInNewTab()
                ->visible(fn () => $this->record->hasMapLocation()),
        ];
    }
}
