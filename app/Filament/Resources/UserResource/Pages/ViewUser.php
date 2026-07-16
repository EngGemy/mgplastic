<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\StoreApprovalService;
use Filament\Actions;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return $this->record->name ?? 'عرض المستخدم';
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(1)
            ->schema([
                ViewEntry::make('profile')
                    ->view('filament.infolists.user-profile')
                    ->columnSpanFull(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('approve')
                ->label(fn () => $this->record->role === 'wholesale_distributor' ? 'تفعيل المتجر' : 'اعتماد الحساب')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn () => ! $this->record->is_approved)
                ->requiresConfirmation()
                ->action(function () {
                    if ($this->record->role === 'wholesale_distributor') {
                        app(StoreApprovalService::class)->approve($this->record, auth()->user());
                    } else {
                        $this->record->forceFill([
                            'is_approved' => true,
                            'approved_at' => now(),
                        ])->save();
                    }

                    Notification::make()->success()->title('تم الاعتماد بنجاح')->send();
                    $this->refreshFormData(['is_approved', 'approved_at', 'is_active']);
                }),

            Actions\EditAction::make()->label('تعديل البيانات'),
        ];
    }
}
