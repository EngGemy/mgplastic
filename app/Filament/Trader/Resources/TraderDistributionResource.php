<?php

namespace App\Filament\Trader\Resources;

use App\Filament\Trader\Resources\TraderDistributionResource;
use App\Filament\Resources\InvoiceDistributionResource;
use App\Models\InvoiceDistribution;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TraderDistributionResource extends Resource
{
    protected static ?string $slug = 'invoice-distributions';

    protected static ?string $model = InvoiceDistribution::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-pointing-out';

    protected static ?string $navigationLabel = 'التوزيعات';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return 'نظام النقاط';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(fn ($q) => $q
                ->where('from_user_id', auth()->id())
                ->orWhere('to_user_id', auth()->id())
            )
            ->with(['invoice', 'fromUser:id,name', 'toUser:id,name', 'items.invoiceItem.product']);
    }

    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        return true;
    }

    public static function form(Form $form): Form
    {
        return InvoiceDistributionResource::form($form);
    }

    public static function table(Table $table): Table
    {
        return InvoiceDistributionResource::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => TraderDistributionResource\Pages\ListTraderDistributions::route('/'),
            'create' => TraderDistributionResource\Pages\CreateTraderDistribution::route('/create'),
            'view' => TraderDistributionResource\Pages\ViewTraderDistribution::route('/{record}'),
        ];
    }
}
