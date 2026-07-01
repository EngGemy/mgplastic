<?php

namespace App\Filament\Support;

use Filament\Forms;

class WithdrawalPaymentForm
{
    /** @return array<int, Forms\Components\Component> */
    public static function confirmationFields(): array
    {
        return [
            Forms\Components\Placeholder::make('payment_hint')
                ->label('')
                ->content('أدخل رقم الإيصال أو رقم التحويل (أو كلاهما) لتأكيد أن الدفع تم فعلياً.'),

            Forms\Components\TextInput::make('receipt_number')
                ->label('رقم الإيصال')
                ->maxLength(100)
                ->placeholder('مثال: RCP-2026-00123'),

            Forms\Components\TextInput::make('transfer_number')
                ->label('رقم التحويل')
                ->maxLength(100)
                ->placeholder('مثال: TRX987654321'),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function validateConfirmation(array $data): void
    {
        $receipt = trim((string) ($data['receipt_number'] ?? ''));
        $transfer = trim((string) ($data['transfer_number'] ?? ''));

        if ($receipt === '' && $transfer === '') {
            throw new \DomainException('يجب إدخال رقم الإيصال أو رقم التحويل (أو كلاهما)');
        }
    }

    /** @param  array<string, mixed>  $data
     * @return array{receipt_number: ?string, transfer_number: ?string}
     */
    public static function normalizeConfirmation(array $data): array
    {
        self::validateConfirmation($data);

        return [
            'receipt_number' => ($v = trim((string) ($data['receipt_number'] ?? ''))) !== '' ? $v : null,
            'transfer_number' => ($v = trim((string) ($data['transfer_number'] ?? ''))) !== '' ? $v : null,
        ];
    }
}
