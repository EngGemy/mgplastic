<?php

namespace App\Filament\Warehouse\Resources;

use App\Filament\Warehouse\Resources\WarehouseWholesalerResource\Pages;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** مخازن / موزّعو الجملة تحت مخزن الدعوة الرئيسي */
class WarehouseWholesalerResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'مخازن الجملة';

    protected static ?string $slug = 'wholesale-stores';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return 'الشبكة';
    }

    public static function getModelLabel(): string
    {
        return 'مخزن جملة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'مخازن الجملة';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isCentralWarehouse();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('role', 'wholesale_distributor')
            ->where(function (Builder $q) {
                $q->where('is_central_warehouse', false)
                    ->orWhereNull('is_central_warehouse');
            })
            ->whereKeyNot(auth()->id() ?? 0)
            ->with(['country', 'city'])
            ->withCount([
                'linkedRetailTraders as retail_traders_count',
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
        return static::canAccess() && $record->isWholesaleDistributor();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('المخزن / العلامة')
                    ->formatStateUsing(fn ($state, User $record) => trim((string) ($record->brand_name ?: $record->name)) ?: '—')
                    ->description(fn (User $r) => filled($r->brand_name) && $r->brand_name !== $r->name ? $r->name : null)
                    ->searchable(query: function ($query, string $search) {
                        $query->where(function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                                ->orWhere('brand_name', 'like', "%{$search}%");
                        });
                    })
                    ->sortable()
                    ->weight('bold')
                    ->copyable()
                    ->copyMessage('تم نسخ اسم المخزن'),
                Tables\Columns\TextColumn::make('network_code')
                    ->label('الرقم الموحّد')
                    ->badge()
                    ->color('success')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('تم نسخ الرقم الموحّد'),
                Tables\Columns\TextColumn::make('phone')
                    ->label('الهاتف')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('city.name')
                    ->label('المدينة')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_approved')
                    ->label('معتمد')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
                Tables\Columns\TextColumn::make('retail_traders_count')
                    ->label('قطاعي مرتبط')
                    ->badge()
                    ->color('info'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_approved')->label('معتمد'),
                Tables\Filters\TernaryFilter::make('is_active')->label('نشط'),
            ])
            ->actions([
                Tables\Actions\Action::make('report')
                    ->label('تقرير')
                    ->icon('heroicon-o-chart-bar')
                    ->url(fn (User $record) => \App\Filament\Warehouse\Pages\WarehouseReports::getUrl().'?warehouse_id='.$record->id),
                Tables\Actions\ViewAction::make()->label('عرض'),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWarehouseWholesalers::route('/'),
            'view' => Pages\ViewWarehouseWholesaler::route('/{record}'),
        ];
    }
}
