<?php

namespace App\Filament\Trader\Resources\TraderOrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Filament\Trader\Resources\TraderOrderResource;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderService;
use App\Support\OrderStatus;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewTraderOrder extends ViewRecord
{
    protected static string $resource = TraderOrderResource::class;

    public function getTitle(): string
    {
        return 'طلب رقم '.($this->record->order_number ?? $this->record->id);
    }

    protected function getHeaderActions(): array
    {
        /** @var Order $order */
        $order = $this->record;
        $isSupplier = OrderResource::isSupplierSide($order);
        $isPlumberOrder = $order->isPlumberChannel();
        $editable = $isSupplier && in_array($order->status, [OrderStatus::PLACED, OrderStatus::CONFIRMED], true);
        $fulfillable = $isSupplier && $isPlumberOrder
            && in_array($order->status, [OrderStatus::PLACED, OrderStatus::CONFIRMED, OrderStatus::SHIPPING], true);

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
                    try {
                        $lines = collect($data['items'] ?? [])->map(fn ($row) => [
                            'product_id' => (int) $row['product_id'],
                            'quantity' => (int) $row['quantity'],
                        ])->all();

                        app(OrderService::class)->updateItems($this->record, auth()->user(), $lines);
                        Notification::make()->success()->title('تم تحديث أصناف الطلب')->send();
                        $this->refreshFormData(['status', 'total_quantity', 'total_points', 'supplier_note']);
                        $this->record->refresh()->load('items');
                    } catch (\DomainException $e) {
                        Notification::make()->danger()->title('خطأ')->body($e->getMessage())->send();
                    }
                }),

            Actions\Action::make('apply_stock')
                ->label('تطبيق المتوفر فقط')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('warning')
                ->visible($editable && $isPlumberOrder)
                ->requiresConfirmation()
                ->modalHeading('تطبيق المتوفر من المخزون')
                ->modalDescription('سيتم تقليص الكميات لما هو متوفر عندك وحذف الأصناف غير الموجودة. هل تريد المتابعة؟')
                ->action(function () {
                    try {
                        app(OrderService::class)->applyAvailableStock($this->record, auth()->user());
                        Notification::make()->success()->title('تم تطبيق المتوفر من المخزون')->send();
                        $this->record->refresh()->load('items');
                    } catch (\DomainException $e) {
                        Notification::make()->danger()->title('خطأ')->body($e->getMessage())->send();
                    }
                }),

            Actions\Action::make('fulfill_invoice')
                ->label('تنفيذ وتحويل لفاتورة')
                ->icon('heroicon-o-document-check')
                ->color('success')
                ->visible($fulfillable)
                ->requiresConfirmation()
                ->modalHeading('تنفيذ الطلب كفاتورة')
                ->modalDescription('سيتم التحقق من المخزون، خصم الكميات، منح النقاط للسباك، وتغيير الحالة إلى «تم التسليم». لا يمكن تنفيذ أصناف غير متوفرة.')
                ->form([
                    Forms\Components\Textarea::make('note')
                        ->label('ملاحظة (اختياري)')
                        ->rows(2),
                ])
                ->action(function (array $data) {
                    try {
                        app(OrderService::class)->fulfillAsInvoice(
                            $this->record,
                            auth()->user(),
                            $data['note'] ?? null,
                        );
                        Notification::make()
                            ->success()
                            ->title('تم التنفيذ ✓')
                            ->body('تم تحويل الطلب لفاتورة وتحديث المخزون والحالة')
                            ->send();
                        $this->record->refresh()->load('items');
                    } catch (\DomainException $e) {
                        Notification::make()->danger()->title('تعذّر التنفيذ')->body($e->getMessage())->send();
                    }
                }),

            Actions\Action::make('confirm')
                ->label('تأكيد الطلب')
                ->icon('heroicon-o-check-circle')
                ->color('info')
                ->visible($isSupplier && $order->status === OrderStatus::PLACED)
                ->form([
                    Forms\Components\Textarea::make('note')->label('ملاحظة (اختياري)')->rows(2),
                ])
                ->action(function (array $data) {
                    try {
                        app(OrderService::class)->confirm($this->record, auth()->user(), $data['note'] ?? null);
                        Notification::make()->success()->title('تم تأكيد الطلب')->send();
                        $this->record->refresh();
                    } catch (\DomainException $e) {
                        Notification::make()->danger()->title('خطأ')->body($e->getMessage())->send();
                    }
                }),

            Actions\Action::make('ship')
                ->label('شحن الطلب')
                ->icon('heroicon-o-truck')
                ->color('primary')
                ->visible($isSupplier && in_array($order->status, [OrderStatus::PLACED, OrderStatus::CONFIRMED], true))
                ->form([
                    Forms\Components\TextInput::make('carrier_name')->label('شركة الشحن'),
                    Forms\Components\TextInput::make('tracking_number')->label('رقم التتبّع'),
                    Forms\Components\DatePicker::make('expected_delivery_at')->label('التسليم المتوقع')->native(false),
                ])
                ->action(function (array $data) {
                    try {
                        app(OrderService::class)->ship($this->record, auth()->user(), $data);
                        Notification::make()->success()->title('تم شحن الطلب')->send();
                        $this->record->refresh();
                    } catch (\DomainException $e) {
                        Notification::make()->danger()->title('خطأ')->body($e->getMessage())->send();
                    }
                }),

            Actions\Action::make('reject')
                ->label('رفض')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible($isSupplier && in_array($order->status, [OrderStatus::PLACED, OrderStatus::CONFIRMED], true))
                ->form([
                    Forms\Components\Textarea::make('reason')->label('سبب الرفض')->required()->rows(2),
                ])
                ->action(function (array $data) {
                    try {
                        app(OrderService::class)->reject($this->record, auth()->user(), $data['reason'] ?? null);
                        Notification::make()->success()->title('تم رفض الطلب')->send();
                        $this->record->refresh();
                    } catch (\DomainException $e) {
                        Notification::make()->danger()->title('خطأ')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
