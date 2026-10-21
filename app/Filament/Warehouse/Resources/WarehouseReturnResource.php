<?php

namespace App\Filament\Warehouse\Resources;

use App\Filament\Warehouse\Resources\WarehouseReturnResource\Pages;
use App\Models\InvoiceReturn;
use App\Services\InvoiceReturnService;
use App\Support\InvoiceReturnStatus;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WarehouseReturnResource extends Resource
{
    protected static ?string $model = InvoiceReturn::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationLabel = 'المرتجعات';

    protected static ?string $slug = 'returns';

    protected static ?int $navigationSort = 25;

    public static function getNavigationGroup(): ?string
    {
        return 'المخزون والنقاط';
    }

    public static function getModelLabel(): string
    {
        return 'مرتجع';
    }

    public static function getPluralModelLabel(): string
    {
        return 'المرتجعات';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isCentralWarehouse();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        if (! $user?->isCentralWarehouse()) {
            return null;
        }

        $count = (int) \Illuminate\Support\Facades\Cache::store('array')->remember(
            'nav_badge:returns_pending:'.$user->id,
            60,
            fn () => InvoiceReturn::query()
                ->where('to_user_id', $user->id)
                ->where('status', InvoiceReturnStatus::PENDING)
                ->count()
        );

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        $userId = auth()->id();

        // Own returns + returns on the wider wholesale network (for oversight).
        return parent::getEloquentQuery()
            ->where(function (Builder $q) use ($userId) {
                $q->where('from_user_id', $userId)
                    ->orWhere('to_user_id', $userId)
                    ->orWhereHas('toUser', fn (Builder $u) => $u->where('role', 'wholesale_distributor'))
                    ->orWhereHas('fromUser', fn (Builder $u) => $u->where('role', 'wholesale_distributor'));
            })
            ->with([
                'items.product.translations',
                'fromUser:id,name,brand_name,role',
                'toUser:id,name,brand_name,role',
                'invoice:id,number',
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canView($record): bool
    {
        return static::canAccess();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('return_number')
                    ->label('رقم المرتجع')
                    ->searchable()
                    ->weight('bold')
                    ->color('danger'),
                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->formatStateUsing(fn ($state) => InvoiceReturnStatus::label(InvoiceReturnStatus::normalize($state)))
                    ->badge()
                    ->color(fn ($state) => InvoiceReturnStatus::color(InvoiceReturnStatus::normalize($state))),
                Tables\Columns\TextColumn::make('tier')
                    ->label('المسار')
                    ->formatStateUsing(fn ($state) => match ((int) $state) {
                        2 => 'جملة → قطاعي',
                        3 => 'قطاعي → سباك',
                        default => '#'.$state,
                    }),
                Tables\Columns\TextColumn::make('fromUser.brand_name')
                    ->label('من')
                    ->description(fn (InvoiceReturn $r) => $r->fromUser?->name)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('toUser.brand_name')
                    ->label('إلى (المستلم)')
                    ->description(fn (InvoiceReturn $r) => $r->toUser?->name)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('total_quantity')
                    ->label('الكمية')
                    ->numeric(),
                Tables\Columns\TextColumn::make('total_points')
                    ->label('النقاط')
                    ->numeric(),
                Tables\Columns\TextColumn::make('invoice.number')
                    ->label('الفاتورة')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('Y/m/d H:i')
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        InvoiceReturnStatus::PENDING => 'بانتظار الموافقة',
                        InvoiceReturnStatus::APPROVED => 'مقبول',
                        InvoiceReturnStatus::REJECTED => 'مرفوض',
                    ]),
                Tables\Filters\SelectFilter::make('tier')
                    ->label('المسار')
                    ->options([2 => 'جملة → قطاعي', 3 => 'قطاعي → سباك']),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('قبول')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (InvoiceReturn $record) => InvoiceReturnStatus::isPending($record->status)
                        && auth()->user()
                        && app(InvoiceReturnService::class)->actorCanDecide($record, auth()->user()))
                    ->requiresConfirmation()
                    ->modalDescription('الموافقة للبائع الأصلي فقط — يُسجَّل القرار في التدقيق.')
                    ->action(function (InvoiceReturn $record) {
                        try {
                            app(InvoiceReturnService::class)->approve($record, auth()->user());
                            Notification::make()->success()->title('تم قبول المرتجع')->send();
                        } catch (\DomainException $e) {
                            Notification::make()->danger()->title('تعذّر القبول')->body($e->getMessage())->send();
                        }
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('رفض')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (InvoiceReturn $record) => InvoiceReturnStatus::isPending($record->status)
                        && auth()->user()
                        && app(InvoiceReturnService::class)->actorCanDecide($record, auth()->user()))
                    ->requiresConfirmation()
                    ->action(function (InvoiceReturn $record) {
                        try {
                            app(InvoiceReturnService::class)->reject($record, auth()->user());
                            Notification::make()->success()->title('تم رفض المرتجع')->send();
                        } catch (\DomainException $e) {
                            Notification::make()->danger()->title('تعذّر الرفض')->body($e->getMessage())->send();
                        }
                    }),
                Tables\Actions\ViewAction::make()->label('تفاصيل'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWarehouseReturns::route('/'),
            'view' => Pages\ViewWarehouseReturn::route('/{record}'),
        ];
    }
}
