<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Concerns\HandlesInvoiceReturns;
use App\Filament\Resources\InvoiceDistributionResource;
use App\Filament\Resources\InvoiceResource;
use App\Models\InvoiceDistribution;
use App\Models\User;
use App\Services\DistributionService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewInvoice extends ViewRecord
{
    use HandlesInvoiceReturns;

    protected static string $resource = InvoiceResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->load([
            'items.product.translations',
            'distributions.fromUser',
            'distributions.toUser',
            'distributions.items',
            'sourceDistribution.items.invoiceItem.product.translations',
            'returns.items.product.translations',
            'returns.fromUser',
            'returns.toUser',
            'wholesaleDistributor',
            'issuer',
        ]);
    }

    protected function getHeaderActions(): array
    {
        $user = auth()->user();
        $isAdmin = in_array($user?->role, ['super_admin', 'admin'], true);
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
                ->modalDescription('بعد التأكيد ستتمكن من إصدار فواتير فرعية لتجار القطاعي من هذه الفاتورة.')
                ->action(function () {
                    $distribution = $this->pendingTierOneReceipt();
                    if (! $distribution) {
                        return;
                    }

                    try {
                        app(DistributionService::class)->confirmDistribution($distribution);
                        Notification::make()->success()->title('تم تأكيد الاستلام')->send();
                        $this->record->refresh()->load([
                            'distributions.fromUser',
                            'distributions.toUser',
                            'distributions.items',
                        ]);
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
                ->modalDescription('وزّع كميات من فاتورتك على أحد تجار القطاعي التابعين لك.')
                ->modalSubmitActionLabel('إصدار الفاتورة الفرعية')
                ->action(function (array $data) {
                    $parent = InvoiceResource::tierOneParentForWholesaler($this->record);
                    if (! $parent) {
                        Notification::make()->danger()->title('يجب تأكيد استلام الفاتورة أولاً')->send();

                        return;
                    }

                    $fromUser = auth()->user();
                    $toUser = User::findOrFail($data['retail_trader_id']);

                    try {
                        $distribution = app(DistributionService::class)->createDistribution(
                            invoice: $this->record,
                            fromUser: $fromUser,
                            toUser: $toUser,
                            tier: 2,
                            items: $data['items'] ?? [],
                            parentId: $parent->id,
                        );

                        Notification::make()
                            ->success()
                            ->title('تم إنشاء الفاتورة الفرعية')
                            ->body('راجع التوزيع ثم أكّده لإتمام العملية')
                            ->send();

                        $this->redirect(InvoiceDistributionResource::getUrl('view', ['record' => $distribution]));
                    } catch (\DomainException $e) {
                        Notification::make()->danger()->title('خطأ')->body($e->getMessage())->send();
                    }
                }),

            $this->invoiceReturnAction(),

            Actions\Action::make('print_invoice')
                ->label('طباعة الفاتورة')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn () => route('admin.invoices.print', $this->record))
                ->openUrlInNewTab(),

            Actions\Action::make('download_invoice')
                ->label('تنزيل للنظام')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('info')
                ->url(fn () => route('admin.invoices.download', $this->record))
                ->visible(fn () => $isAdmin),

            Actions\Action::make('export_json')
                ->label('تصدير JSON')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(fn () => route('admin.invoices.export', $this->record))
                ->visible(fn () => $isAdmin && $this->record->isWholesalePos()),

            Actions\Action::make('pos_new')
                ->label('بيع للقطاعي')
                ->icon('heroicon-o-arrow-up-circle')
                ->color('warning')
                ->url(fn () => InvoiceResource::getUrl('pos-retail'))
                ->visible(fn () => $user?->isWholesaleDistributor()),

            Actions\Action::make('start_tier1_distribution')
                ->label('بدء توزيع النقاط — طبقة ①')
                ->icon('heroicon-o-arrows-pointing-out')
                ->color('success')
                ->visible(fn () => $isAdmin
                    && $this->record->isWholesalePos()
                    && $this->record->status === 'approved'
                    && $this->record->wholesale_distributor_id)
                ->url(function () {
                    $superAdmin = User::where('role', 'super_admin')->first();

                    return InvoiceDistributionResource::getUrl('create', [
                        'invoice_id' => $this->record->id,
                        'tier' => 1,
                        'from_user_id' => $superAdmin?->id ?? auth()->id(),
                        'to_user_id' => $this->record->wholesale_distributor_id,
                    ]);
                }),

            Actions\EditAction::make()
                ->visible(fn () => $isAdmin && ! $this->record->isWholesalePos()),

            Actions\Action::make('approve_with_totals')
                ->label('اعتماد الفاتورة')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $isAdmin
                    && $this->record->status === 'pending_review'
                    && ! $this->record->isWholesalePos())
                ->form([
                    Forms\Components\TextInput::make('points_awarded')
                        ->label('النقاط الممنوحة')
                        ->numeric()
                        ->minValue(0)
                        ->default(fn () => (int) ($this->record->items->sum('total_points') ?: $this->record->points_awarded ?: 0))
                        ->suffix('نقطة')
                        ->helperText('افتراضياً مجموع نقاط بنود الفاتورة')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $invoice = $this->record;
                    $invoice->approveByAdmin(auth()->user(), (int) $data['points_awarded']);

                    $this->redirect(InvoiceResource::getUrl('view', ['record' => $invoice]));
                })
                ->requiresConfirmation()
                ->modalHeading('اعتماد الفاتورة')
                ->modalSubmitActionLabel('اعتماد'),
        ];
    }

    protected function pendingTierOneReceipt(): ?InvoiceDistribution
    {
        $user = auth()->user();
        if (! $user?->isWholesaleDistributor()) {
            return null;
        }

        return $this->record->distributions()
            ->where('tier', 1)
            ->where('to_user_id', $user->id)
            ->where('status', 'draft')
            ->latest('id')
            ->first();
    }

    protected function subInvoiceFormSchema(): array
    {
        $parent = InvoiceResource::tierOneParentForWholesaler($this->record);
        $wholesalerId = auth()->id();

        return [
            Forms\Components\Select::make('retail_trader_id')
                ->label('تاجر القطاعي')
                ->options(fn () => app(\App\Services\RetailNetworkLinkService::class)
                    ->linkedRetailersFor(auth()->user())
                    ->mapWithKeys(fn (User $u) => [
                        $u->id => ($u->network_code ? "[{$u->network_code}] " : '').$u->name,
                    ]))
                ->searchable()
                ->required()
                ->helperText('التجار المرتبطون بشبكتك عبر الرقم الموحّد أو التسجيل'),

            Forms\Components\Repeater::make('items')
                ->label('بنود الفاتورة الفرعية')
                ->schema([
                    Forms\Components\Select::make('invoice_item_id')
                        ->label('المنتج')
                        ->options(function () use ($parent) {
                            if (! $parent) {
                                return [];
                            }

                            return $this->record->items
                                ->mapWithKeys(function ($item) use ($parent) {
                                    $available = $item->availableQuantityForTier(2, $parent->id);
                                    $name = localized_name($item->product, 'name', 'منتج');

                                    return [$item->id => "{$name} (متاح: {$available})"];
                                })
                                ->filter(fn ($label, $id) => $this->record->items->firstWhere('id', $id)?->availableQuantityForTier(2, $parent->id) > 0);
                        })
                        ->required()
                        ->distinct()
                        ->disableOptionsWhenSelectedInSiblingRepeaterItems(),

                    Forms\Components\TextInput::make('quantity')
                        ->label('الكمية')
                        ->numeric()
                        ->minValue(1)
                        ->required(),
                ])
                ->columns(2)
                ->defaultItems(1)
                ->addActionLabel('إضافة منتج'),
        ];
    }
}
