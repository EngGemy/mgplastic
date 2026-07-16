<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderService;
use App\Support\OrderStatus;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 15;

    public static function getNavigationGroup(): ?string { return 'الطلبيات'; }
    public static function getNavigationLabel(): string { return 'الطلبيات'; }
    public static function getModelLabel(): string { return 'طلبية'; }
    public static function getPluralModelLabel(): string { return 'الطلبيات'; }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', OrderStatus::PLACED)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['requester:id,name,brand_name', 'supplier:id,name,brand_name']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    // ── role helpers (shared across panels) ─────────────────────

    public static function isSupplierSide(Order $record): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($record->channel === OrderStatus::CHANNEL_FACTORY_TO_WHOLESALE) {
            return in_array($user->role, ['super_admin', 'admin'], true);
        }

        return (int) $user->id === (int) $record->supplier_id
            || in_array($user->role, ['super_admin', 'admin'], true);
    }

    public static function isBuyerSide(Order $record): bool
    {
        $user = auth()->user();

        return $user !== null && (int) $user->id === (int) $record->requester_id;
    }

    /** Shared product options for placing an order. */
    public static function productOptions(?string $search = null): array
    {
        return Product::query()
            ->where('points_per_unit', '>', 0)
            ->with('translations')
            ->when($search, fn ($q) => $q->whereHas(
                'translations',
                fn ($t) => $t->where('name', 'like', '%'.$search.'%')
            ))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Product $p) => [
                $p->id => localized_name($p, 'name', "منتج #{$p->id}"),
            ])
            ->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('أصناف الطلب')
                ->description('اختر المنتجات والكميات المطلوبة.')
                ->icon('heroicon-o-shopping-cart')
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->label('')
                        ->addActionLabel('إضافة منتج')
                        ->schema([
                            Forms\Components\Select::make('product_id')
                                ->label('المنتج')
                                ->required()
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search) => static::productOptions($search))
                                ->getOptionLabelUsing(function ($value) {
                                    $product = Product::find($value);

                                    return $product ? localized_name($product, 'name', "منتج #{$value}") : null;
                                })
                                ->options(fn () => static::productOptions())
                                ->columnSpan(3),

                            Forms\Components\TextInput::make('quantity')
                                ->label('الكمية')
                                ->numeric()
                                ->minValue(1)
                                ->default(1)
                                ->required()
                                ->columnSpan(1),
                        ])
                        ->columns(4)
                        ->minItems(1)
                        ->reorderable(false)
                        ->cloneable()
                        ->columnSpanFull(),
                ]),

            Forms\Components\Textarea::make('note')
                ->label('ملاحظات للمورّد (اختياري)')
                ->placeholder('أي تفاصيل إضافية عن الطلب...')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label('رقم الطلب')
                    ->searchable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('channel')
                    ->label('النوع')
                    ->badge()
                    ->color(fn ($state) => $state === OrderStatus::CHANNEL_FACTORY_TO_WHOLESALE ? 'info' : 'warning')
                    ->formatStateUsing(fn ($state) => OrderStatus::channelLabel($state)),

                Tables\Columns\TextColumn::make('requester.name')
                    ->label('الطالب')
                    ->description(fn (Order $r) => $r->requester?->brand_name)
                    ->searchable(),

                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('المورّد')
                    ->placeholder('المصنع')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_quantity')
                    ->label('الكمية')
                    ->numeric()
                    ->alignCenter()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('total_points')
                    ->label('النقاط')
                    ->numeric()
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->icon(fn ($state) => OrderStatus::icon($state))
                    ->color(fn ($state) => OrderStatus::color($state))
                    ->formatStateUsing(fn ($state) => OrderStatus::label($state)),

                Tables\Columns\TextColumn::make('expected_delivery_at')
                    ->label('التسليم المتوقع')
                    ->date()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(OrderStatus::options()),

                Tables\Filters\SelectFilter::make('channel')
                    ->label('نوع الطلب')
                    ->options(OrderStatus::channelOptions()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('عرض'),

                Tables\Actions\Action::make('confirm')
                    ->label('تأكيد الطلب')
                    ->icon('heroicon-o-check-circle')
                    ->color('info')
                    ->visible(fn (Order $record) => static::isSupplierSide($record) && $record->status === OrderStatus::PLACED)
                    ->form([
                        Forms\Components\Textarea::make('note')
                            ->label('ملاحظة (اختياري)')
                            ->rows(2),
                    ])
                    ->action(fn (Order $record, array $data) => static::runTransition(
                        fn () => app(OrderService::class)->confirm($record, auth()->user(), $data['note'] ?? null),
                        'تم تأكيد الطلب',
                    )),

                Tables\Actions\Action::make('ship')
                    ->label('شحن الطلب')
                    ->icon('heroicon-o-truck')
                    ->color('primary')
                    ->visible(fn (Order $record) => static::isSupplierSide($record)
                        && in_array($record->status, [OrderStatus::PLACED, OrderStatus::CONFIRMED], true))
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
                    ->action(fn (Order $record, array $data) => static::runTransition(
                        fn () => app(OrderService::class)->ship($record, auth()->user(), $data),
                        'تم شحن الطلب',
                    )),

                Tables\Actions\Action::make('receive')
                    ->label('تأكيد الاستلام')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Order $record) => static::isBuyerSide($record)
                        && in_array($record->status, [OrderStatus::SHIPPING, OrderStatus::CONFIRMED], true))
                    ->requiresConfirmation()
                    ->modalHeading('تأكيد استلام الطلب')
                    ->modalDescription('عند التأكيد ستُضاف الكميات المطلوبة إلى مخزونك. هل استلمت الطلب بالكامل؟')
                    ->action(fn (Order $record) => static::runTransition(
                        fn () => app(OrderService::class)->deliver($record, auth()->user()),
                        'تم تأكيد الاستلام وإضافة الكميات لمخزونك',
                    )),

                Tables\Actions\Action::make('reject')
                    ->label('رفض')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (Order $record) => static::isSupplierSide($record)
                        && in_array($record->status, [OrderStatus::PLACED, OrderStatus::CONFIRMED], true))
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('سبب الرفض')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(fn (Order $record, array $data) => static::runTransition(
                        fn () => app(OrderService::class)->reject($record, auth()->user(), $data['reason'] ?? null),
                        'تم رفض الطلب',
                    )),

                Tables\Actions\Action::make('cancel')
                    ->label('إلغاء')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->visible(fn (Order $record) => static::isBuyerSide($record)
                        && in_array($record->status, [OrderStatus::PLACED, OrderStatus::CONFIRMED], true))
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('سبب الإلغاء (اختياري)')
                            ->rows(2),
                    ])
                    ->action(fn (Order $record, array $data) => static::runTransition(
                        fn () => app(OrderService::class)->cancel($record, auth()->user(), $data['reason'] ?? null),
                        'تم إلغاء الطلب',
                    )),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(1)
            ->schema([
                Infolists\Components\ViewEntry::make('profile')
                    ->view('filament.infolists.order-profile')
                    ->columnSpanFull(),
            ]);
    }

    protected static function runTransition(callable $fn, string $successTitle): void
    {
        try {
            $fn();
            Notification::make()->success()->title($successTitle)->send();
        } catch (\DomainException $e) {
            Notification::make()->danger()->title('تعذّر إتمام العملية')->body($e->getMessage())->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }
}
