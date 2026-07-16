<?php

namespace App\Filament\Distributor\Resources\DistributorInvoiceResource\Pages;

use App\Filament\Distributor\Resources\DistributorDistributionResource;
use App\Filament\Distributor\Resources\DistributorInvoiceResource;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Models\User;
use App\Services\DistributionService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;

class ViewDistributorInvoice extends ViewInvoice
{
    protected static string $resource = DistributorInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        $user = auth()->user();
        $isOwnerWholesaler = $user?->isWholesaleDistributor()
            && (int) $this->record->wholesale_distributor_id === (int) $user->id;

        return [
            Actions\Action::make('confirm_receipt')
                ->label('تأكيد استلام الفاتورة')
                ->icon('heroicon-o-inbox-arrow-down')
                ->color('info')
                ->visible(fn () => $isOwnerWholesaler && $this->pendingTierOneReceipt() !== null)
                ->requiresConfirmation()
                ->modalHeading('تأكيد استلام الفاتورة من المصنع')
                ->action(function () {
                    $distribution = $this->pendingTierOneReceipt();
                    if (! $distribution) {
                        return;
                    }

                    try {
                        app(DistributionService::class)->confirmDistribution($distribution);
                        Notification::make()->success()->title('تم تأكيد الاستلام')->send();
                        $this->record->refresh()->load(['distributions.fromUser', 'distributions.toUser', 'distributions.items']);
                    } catch (\DomainException $e) {
                        Notification::make()->danger()->title('خطأ')->body($e->getMessage())->send();
                    }
                }),

            Actions\Action::make('issue_sub_invoice')
                ->label('إصدار فاتورة فرعية — تاجر قطاعي')
                ->icon('heroicon-o-document-duplicate')
                ->color('warning')
                ->visible(fn () => InvoiceResource::canWholesalerIssueSubInvoice($this->record))
                ->form($this->subInvoiceFormSchema())
                ->modalHeading('فاتورة فرعية من الفاتورة الأصلية')
                ->modalSubmitActionLabel('إصدار الفاتورة الفرعية')
                ->action(function (array $data) {
                    $parent = InvoiceResource::tierOneParentForWholesaler($this->record);
                    if (! $parent) {
                        Notification::make()->danger()->title('يجب تأكيد استلام الفاتورة أولاً')->send();

                        return;
                    }

                    try {
                        $distribution = app(DistributionService::class)->createDistribution(
                            invoice: $this->record,
                            fromUser: auth()->user(),
                            toUser: User::findOrFail($data['retail_trader_id']),
                            tier: 2,
                            items: $data['items'] ?? [],
                            parentId: $parent->id,
                        );

                        Notification::make()->success()->title('تم إنشاء الفاتورة الفرعية')->send();
                        $this->redirect(DistributorDistributionResource::getUrl('view', ['record' => $distribution]));
                    } catch (\DomainException $e) {
                        Notification::make()->danger()->title('خطأ')->body($e->getMessage())->send();
                    }
                }),

            Actions\Action::make('return_invoice')
                ->label('مرتجع على الفاتورة')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn () => $this->canReturnThisInvoice())
                ->form(fn () => $this->returnFormSchema())
                ->modalHeading('مرتجع بضاعة ونقاط')
                ->modalDescription('سيتم إرجاع الكميات للمورّد وخصم النقاط من المستلم وإعادتها للأعلى في السلسلة.')
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

                        $ret = app(\App\Services\InvoiceReturnService::class)
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
