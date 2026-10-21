<?php

namespace App\Filament\Concerns;

use App\Services\CustomerWhatsAppNotifier;
use Filament\Actions;
use Filament\Notifications\Notification;
use Illuminate\Support\Js;

trait HasInvoiceDocumentActions
{
    /** @return array<int, Actions\Action> */
    protected function invoiceDocumentHeaderActions(bool $includeAdminExtras = false): array
    {
        $actions = [
            Actions\Action::make('print_invoice')
                ->label('طباعة')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn () => route('admin.invoices.print', $this->record))
                ->openUrlInNewTab(),

            Actions\Action::make('pdf_invoice')
                ->label('تصدير PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('danger')
                ->url(fn () => route('admin.invoices.pdf', $this->record))
                ->openUrlInNewTab(),

            Actions\Action::make('whatsapp_invoice')
                ->label('إرسال واتساب')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('success')
                ->action(function () {
                    $invoice = $this->record->fresh([
                        'wholesaleDistributor',
                        'counterparty',
                        'plumber',
                    ]);
                    $notifier = app(CustomerWhatsAppNotifier::class);
                    $result = $notifier->notifyInvoiceIssued($invoice);

                    if ($result['sent']) {
                        Notification::make()->success()->title('تم إرسال واتساب للعميل')->send();

                        return;
                    }

                    $chatUrl = $result['chat_url'] ?? $notifier->invoiceChatUrl($invoice);
                    if ($chatUrl === null) {
                        Notification::make()
                            ->warning()
                            ->title('أضف هاتف المستلم لإرسال واتساب')
                            ->body($result['error'] ?? null)
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('فتح واتساب')
                        ->body('الرسالة تتضمن رابط الفاتورة وملف PDF — اضغط إرسال في واتساب.')
                        ->send();

                    $this->js('window.open('.Js::from($chatUrl).', "_blank", "noopener")');
                }),
        ];

        if ($includeAdminExtras) {
            $actions[] = Actions\Action::make('download_invoice')
                ->label('تنزيل HTML')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('info')
                ->url(fn () => route('admin.invoices.download', $this->record));

            $actions[] = Actions\Action::make('export_json')
                ->label('تصدير JSON')
                ->icon('heroicon-o-code-bracket')
                ->color('gray')
                ->url(fn () => route('admin.invoices.export', $this->record))
                ->visible(fn () => $this->record->isWholesalePos());
        }

        return $actions;
    }
}
