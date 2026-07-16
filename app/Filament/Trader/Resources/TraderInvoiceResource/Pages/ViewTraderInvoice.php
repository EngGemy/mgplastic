<?php

namespace App\Filament\Trader\Resources\TraderInvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Trader\Resources\TraderInvoiceResource;
use App\Services\InvoiceReturnService;
use Filament\Actions;
use Filament\Notifications\Notification;

class ViewTraderInvoice extends ViewInvoice
{
    protected static string $resource = TraderInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('return_invoice')
                ->label('مرتجع على الفاتورة')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn () => $this->canReturnThisInvoice())
                ->form(fn () => $this->returnFormSchema())
                ->modalHeading('مرتجع بضاعة ونقاط')
                ->modalDescription('يُعاد المخزون لموزّع الجملة وتُخصم النقاط من رصيدك وتُضاف له.')
                ->modalSubmitActionLabel('تأكيد المرتجع')
                ->action(function (array $data) {
                    try {
                        $lines = collect($data['items'] ?? [])
                            ->filter(fn ($row) => (int) ($row['quantity'] ?? 0) > 0)
                            ->map(fn ($row) => [
                                'invoice_item_id' => (int) $row['invoice_item_id'],
                                'quantity' => (int) $row['quantity'],
                            ])
                            ->values()
                            ->all();

                        $ret = app(InvoiceReturnService::class)
                            ->returnOutgoingInvoice($this->record, $lines, auth()->user(), $data['note'] ?? null);

                        Notification::make()
                            ->success()
                            ->title('تم تسجيل المرتجع ✓')
                            ->body("رقم {$ret->return_number} — {$ret->total_quantity} وحدة / {$ret->total_points} نقطة")
                            ->persistent()
                            ->send();

                        $this->record->refresh();
                    } catch (\DomainException $e) {
                        Notification::make()->danger()->title('تعذّر المرتجع')->body($e->getMessage())->send();
                    }
                }),

            Actions\Action::make('print_invoice')
                ->label('طباعة الفاتورة')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn () => route('admin.invoices.print', $this->record))
                ->openUrlInNewTab(),
        ];
    }
}
