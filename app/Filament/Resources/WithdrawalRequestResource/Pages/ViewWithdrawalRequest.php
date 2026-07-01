<?php

namespace App\Filament\Resources\WithdrawalRequestResource\Pages;

use App\Filament\Resources\WithdrawalRequestResource;
use App\Filament\Support\WithdrawalPaymentForm;
use App\Models\WithdrawalRequest;
use Filament\Forms;
use Filament\Resources\Pages\ViewRecord;

class ViewWithdrawalRequest extends ViewRecord
{
    protected static string $resource = WithdrawalRequestResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->load('plumber');
    }

    public function getTitle(): string
    {
        return 'طلب سحب';
    }

    protected function getHeaderActions(): array
    {
        /** @var WithdrawalRequest $record */
        $record = $this->record;

        $actions = [];

        if ($record->status === 'pending') {
            $actions[] = \Filament\Actions\Action::make('markPaid')
                ->label('تأكيد الدفع')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->form(WithdrawalPaymentForm::confirmationFields())
                ->modalHeading('تأكيد إتمام الدفع')
                ->modalDescription('أدخل رقم الإيصال أو رقم التحويل. الرصيد محجوز مسبقاً من محفظة السبّاك.')
                ->modalSubmitActionLabel('تأكيد الدفع')
                ->action(fn (array $data) => ListWithdrawalRequests::markPaid($this->record, $data));

            $actions[] = \Filament\Actions\Action::make('reject')
                ->label('رفض')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->form([
                    Forms\Components\Textarea::make('reason_ar')
                        ->label('سبب الرفض')
                        ->required()
                        ->rows(3)
                        ->maxLength(500),
                    Forms\Components\TextInput::make('reason_en')
                        ->label('Reason (EN) — اختياري')
                        ->maxLength(255),
                ])
                ->modalHeading('رفض الطلب وإرجاع الرصيد')
                ->modalDescription('سيُعاد المبلغ المحجوز إلى محفظة السبّاك.')
                ->modalSubmitActionLabel('رفض وإرجاع')
                ->action(fn (array $data) => ListWithdrawalRequests::rejectAndRefund($this->record, $data));
        }

        return $actions;
    }
}
