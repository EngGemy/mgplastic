<?php

namespace App\Filament\Concerns;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderService;
use App\Support\OrderStatus;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;

/**
 * Shared order status actions for distributor / trader / admin view pages.
 * Button labels & flow mirror Mobins-style marketplace UX.
 */
trait ManagesOrderLifecycleActions
{
    protected function getOrderLifecycleHeaderActions(): array
    {
        /** @var Order $order */
        $order = $this->record;
        $isSupplier = OrderResource::isSupplierSide($order);
        $isBuyer = OrderResource::isBuyerSide($order);
        $isPlumberOrder = $order->isPlumberChannel();
        $editable = $isSupplier && in_array($order->status, [OrderStatus::PLACED, OrderStatus::CONFIRMED], true);
        $fulfillable = $isSupplier && $isPlumberOrder
            && in_array($order->status, [OrderStatus::PLACED, OrderStatus::CONFIRMED, OrderStatus::SHIPPING], true);
        $canSupplierDeliver = OrderResource::canMarkDeliveredAsSupplier($order);
        $canBuyerReceive = OrderResource::canMarkReceivedAsBuyer($order);

        return [
            Actions\Action::make('edit_items')
                ->label('تعديل الأصناف')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->visible($editable && $isPlumberOrder)
                ->fillForm(function () use ($order) {
                    $availability = collect(app(OrderService::class)->stockAvailability($order))
                        ->keyBy('product_id');

                    return [
                        'items' => $order->items->map(fn ($item) => [
                            'product_id' => $item->product_id,
                            'quantity' => $item->quantity,
                            'available_qty' => (int) ($availability->get($item->product_id)['available_qty'] ?? 0),
                        ])->all(),
                    ];
                })
                ->form([
                    Forms\Components\Placeholder::make('stock_hint')
                        ->label('')
                        ->content('عدّل الكميات حسب مخزونك. الأصناف غير المتوفرة احذفها أو خفّض كميتها قبل التنفيذ.'),
                    Forms\Components\Repeater::make('items')
                        ->label('الأصناف')
                        ->schema([
                            Forms\Components\Select::make('product_id')
                                ->label('المنتج')
                                ->required()
                                ->searchable()
                                ->options(fn () => OrderResource::productOptions())
                                ->getSearchResultsUsing(fn (string $search) => OrderResource::productOptions($search))
                                ->getOptionLabelUsing(function ($value) {
                                    $p = Product::find($value);

                                    return $p ? localized_name($p, 'name', "منتج #{$value}") : null;
                                })
                                ->columnSpan(3),
                            Forms\Components\TextInput::make('quantity')
                                ->label('الكمية')
                                ->numeric()
                                ->minValue(1)
                                ->required()
                                ->columnSpan(1),
                            Forms\Components\TextInput::make('available_qty')
                                ->label('متوفر عندك')
                                ->disabled()
                                ->dehydrated(false)
                                ->columnSpan(1),
                        ])
                        ->columns(5)
                        ->minItems(1)
                        ->addActionLabel('إضافة صنف')
                        ->reorderable(false),
                ])
                ->action(function (array $data) {
                    $this->runOrderTransition(
                        fn () => app(OrderService::class)->updateItems(
                            $this->record,
                            auth()->user(),
                            collect($data['items'] ?? [])->map(fn ($row) => [
                                'product_id' => (int) $row['product_id'],
                                'quantity' => (int) $row['quantity'],
                            ])->all(),
                        ),
                        'تم تحديث أصناف الطلب',
                    );
                }),

            Actions\Action::make('apply_stock')
                ->label('تطبيق المتوفر فقط')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('warning')
                ->visible($editable && $isPlumberOrder)
                ->requiresConfirmation()
                ->modalHeading('تطبيق المتوفر من المخزون')
                ->modalDescription('سيتم تقليص الكميات لما هو متوفر عندك وحذف الأصناف غير الموجودة. هل تريد المتابعة؟')
                ->action(fn () => $this->runOrderTransition(
                    fn () => app(OrderService::class)->applyAvailableStock($this->record, auth()->user()),
                    'تم تطبيق المتوفر من المخزون',
                )),

            Actions\Action::make('fulfill_invoice')
                ->label('تنفيذ وتحويل لفاتورة')
                ->icon('heroicon-o-document-check')
                ->color('success')
                ->size('lg')
                ->visible($fulfillable)
                ->requiresConfirmation()
                ->modalHeading('تنفيذ الطلب كفاتورة')
                ->modalDescription('سيتم التحقق من المخزون، خصم الكميات، منح النقاط للسباك، وتغيير الحالة إلى «تم التسليم».')
                ->form([
                    Forms\Components\Textarea::make('note')->label('ملاحظة (اختياري)')->rows(2),
                ])
                ->action(function (array $data) {
                    $this->runOrderTransition(
                        fn () => app(OrderService::class)->fulfillAsInvoice(
                            $this->record,
                            auth()->user(),
                            $data['note'] ?? null,
                        ),
                        'تم التنفيذ ✓',
                        'تم تحويل الطلب لفاتورة وتحديث المخزون والحالة',
                    );
                }),

            Actions\Action::make('confirm')
                ->label('تأكيد الطلب')
                ->icon('heroicon-o-check-circle')
                ->color('info')
                ->size('lg')
                ->visible($isSupplier && $order->status === OrderStatus::PLACED && ! $fulfillable)
                ->form([
                    Forms\Components\Textarea::make('note')->label('ملاحظة (اختياري)')->rows(2),
                ])
                ->action(fn (array $data) => $this->runOrderTransition(
                    fn () => app(OrderService::class)->confirm($this->record, auth()->user(), $data['note'] ?? null),
                    'تم تأكيد الطلب',
                )),

            Actions\Action::make('ship')
                ->label('شحن الطلب')
                ->icon('heroicon-o-truck')
                ->color('primary')
                ->size('lg')
                ->visible($isSupplier
                    && ! $isPlumberOrder
                    && in_array($order->status, [OrderStatus::PLACED, OrderStatus::CONFIRMED], true))
                ->form([
                    Forms\Components\TextInput::make('carrier_name')
                        ->label('شركة الشحن')
                        ->placeholder('اسم شركة الشحن / المندوب'),
                    Forms\Components\TextInput::make('tracking_number')
                        ->label('رقم التتبّع')
                        ->placeholder('اختياري'),
                    Forms\Components\DatePicker::make('expected_delivery_at')
                        ->label('التسليم المتوقع')
                        ->native(false),
                ])
                ->action(fn (array $data) => $this->runOrderTransition(
                    fn () => app(OrderService::class)->ship($this->record, auth()->user(), $data),
                    'تم شحن الطلب',
                )),

            Actions\Action::make('deliver_supplier')
                ->label('تم التسليم')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->size('lg')
                ->visible($canSupplierDeliver)
                ->requiresConfirmation()
                ->modalHeading('تأكيد تسليم الطلب')
                ->modalDescription('سيتم خصم الكميات من مخزونك وإضافتها لمخزون المشتري، وتغيير الحالة إلى «تم التسليم».')
                ->action(fn () => $this->runOrderTransition(
                    fn () => app(OrderService::class)->deliver($this->record, auth()->user()),
                    'تم تسليم الطلب ✓',
                    'أُضيفت الكميات لمخزون المشتري',
                )),

            Actions\Action::make('receive')
                ->label('تأكيد الاستلام')
                ->icon('heroicon-o-hand-thumb-up')
                ->color('success')
                ->size('lg')
                ->visible($canBuyerReceive)
                ->requiresConfirmation()
                ->modalHeading('تأكيد استلام الطلب')
                ->modalDescription('عند التأكيد ستُضاف الكميات المطلوبة إلى مخزونك. هل استلمت الطلب بالكامل؟')
                ->action(fn () => $this->runOrderTransition(
                    fn () => app(OrderService::class)->deliver($this->record, auth()->user()),
                    'تم تأكيد الاستلام وإضافة الكميات لمخزونك',
                )),

            Actions\Action::make('reject')
                ->label('رفض')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible($isSupplier && in_array($order->status, [OrderStatus::PLACED, OrderStatus::CONFIRMED], true))
                ->form([
                    Forms\Components\Textarea::make('reason')->label('سبب الرفض')->required()->rows(2),
                ])
                ->action(fn (array $data) => $this->runOrderTransition(
                    fn () => app(OrderService::class)->reject($this->record, auth()->user(), $data['reason'] ?? null),
                    'تم رفض الطلب',
                )),

            Actions\Action::make('cancel')
                ->label('إلغاء')
                ->icon('heroicon-o-x-circle')
                ->color('gray')
                ->visible($isBuyer && in_array($order->status, [OrderStatus::PLACED, OrderStatus::CONFIRMED], true))
                ->form([
                    Forms\Components\Textarea::make('reason')->label('سبب الإلغاء (اختياري)')->rows(2),
                ])
                ->action(fn (array $data) => $this->runOrderTransition(
                    fn () => app(OrderService::class)->cancel($this->record, auth()->user(), $data['reason'] ?? null),
                    'تم إلغاء الطلب',
                )),
        ];
    }

    protected function runOrderTransition(callable $fn, string $successTitle, ?string $successBody = null): void
    {
        try {
            $fn();
            $notification = Notification::make()->success()->title($successTitle);
            if ($successBody) {
                $notification->body($successBody);
            }
            $notification->send();
            $this->record->refresh()->load(['items', 'requester', 'supplier']);
        } catch (\DomainException $e) {
            Notification::make()->danger()->title('تعذّر إتمام العملية')->body($e->getMessage())->send();
        }
    }
}
