<?php

namespace App\Filament\Resources\StoreResource\Pages;

use App\Filament\Resources\StoreResource;
use App\Services\StoreApprovalService;
use Filament\Actions;
use Filament\Forms;
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
                ->label('اعتماد المتجر')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn () => ! $this->record->is_approved)
                ->requiresConfirmation()
                ->modalHeading('اعتماد المتجر')
                ->modalDescription('سيتم اعتماد المتجر وإظهاره في الشبكة وإرسال إشعار لصاحبه.')
                ->action(function () {
                    app(StoreApprovalService::class)->approve($this->record, auth()->user());
                    Notification::make()->success()->title('تم اعتماد المتجر')->send();
                    $this->refreshFormData(['is_approved', 'is_active', 'approved_at', 'deactivated_at']);
                }),

            Actions\Action::make('activate')
                ->label('تفعيل النشاط')
                ->icon('heroicon-o-bolt')
                ->color('success')
                ->visible(fn () => $this->record->is_approved && ! $this->record->is_active)
                ->requiresConfirmation()
                ->modalHeading('تفعيل نشاط المتجر')
                ->action(function () {
                    app(StoreApprovalService::class)->activate($this->record);
                    Notification::make()->success()->title('تم تفعيل نشاط المتجر')->send();
                    $this->refreshFormData(['is_approved', 'is_active', 'approved_at', 'deactivated_at']);
                }),

            Actions\Action::make('deactivate')
                ->label('إيقاف النشاط')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible(fn () => (bool) $this->record->is_active)
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('سبب الإيقاف (اختياري)')
                        ->rows(2),
                ])
                ->requiresConfirmation()
                ->modalHeading('إيقاف نشاط المتجر')
                ->modalDescription('لن يتمكن صاحب المتجر من تسجيل الدخول حتى تعيد تفعيله.')
                ->action(function (array $data) {
                    app(StoreApprovalService::class)->deactivate($this->record, $data['reason'] ?? null);
                    Notification::make()->success()->title('تم إيقاف نشاط المتجر')->send();
                    $this->refreshFormData(['is_approved', 'is_active', 'approved_at', 'deactivated_at']);
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
