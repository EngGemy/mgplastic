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

            Actions\Action::make('print_invoice')
                ->label('طباعة الفاتورة')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn () => route('admin.invoices.print', $this->record))
                ->openUrlInNewTab(),
        ];
    }
}
