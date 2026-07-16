<?php

namespace App\Filament\Resources\RetailTraderResource\Pages;

use App\Filament\Resources\RetailTraderResource;
use App\Services\StoreApprovalService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewRetailTrader extends ViewRecord
{
    protected static string $resource = RetailTraderResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->load(['country', 'city', 'parentDistributor', 'linkedWholesalers'])
            ->loadCount(['plumbers', 'linkedWholesalers']);
    }

    protected function getHeaderActions(): array
    {
        $isAdmin = in_array(auth()->user()?->role, ['super_admin', 'admin'], true);

        return [
            Actions\Action::make('approve')
                ->label('تفعيل على النظام')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn () => $isAdmin && ! $this->record->is_approved)
                ->requiresConfirmation()
                ->modalHeading('تفعيل تاجر قطاعي')
                ->modalDescription('سيتم اعتماد الحساب ليتمكّن من العمل على النظام والظهور في الشبكة.')
                ->action(function () {
                    app(StoreApprovalService::class)->approve($this->record, auth()->user());
                    Notification::make()->success()->title('تم تفعيل التاجر القطاعي')->send();
                    $this->refreshFormData(['is_approved', 'is_active', 'approved_at', 'deactivated_at']);
                }),

            Actions\Action::make('activate')
                ->label('إعادة تفعيل النشاط')
                ->icon('heroicon-o-bolt')
                ->color('success')
                ->visible(fn () => $isAdmin && $this->record->is_approved && ! $this->record->is_active)
                ->requiresConfirmation()
                ->action(function () {
                    app(StoreApprovalService::class)->activate($this->record);
                    Notification::make()->success()->title('تم تفعيل النشاط')->send();
                    $this->refreshFormData(['is_approved', 'is_active', 'approved_at', 'deactivated_at']);
                }),

            Actions\Action::make('deactivate')
                ->label('إيقاف النشاط')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible(fn () => $isAdmin && (bool) $this->record->is_active)
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('سبب الإيقاف (اختياري)')
                        ->rows(2),
                ])
                ->requiresConfirmation()
                ->modalHeading('إيقاف نشاط التاجر')
                ->action(function (array $data) {
                    app(StoreApprovalService::class)->deactivate($this->record, $data['reason'] ?? null);
                    Notification::make()->success()->title('تم إيقاف النشاط')->send();
                    $this->refreshFormData(['is_approved', 'is_active', 'approved_at', 'deactivated_at']);
                }),

            Actions\EditAction::make()->label('تعديل'),
        ];
    }
}
