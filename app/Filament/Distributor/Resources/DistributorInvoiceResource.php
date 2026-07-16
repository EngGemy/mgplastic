<?php

namespace App\Filament\Distributor\Resources;

use App\Filament\Distributor\Resources\DistributorInvoiceResource\Pages;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\InvoiceResource\RelationManagers\ReturnsRelationManager;
use App\Models\Invoice;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DistributorInvoiceResource extends Resource
{
    protected static ?string $slug = 'invoices';

    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationLabel = 'الفواتير';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return 'نظام النقاط';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('invoice_type', 'wholesale_pos')
            ->where('wholesale_distributor_id', auth()->id())
            ->with(['items.product.translations', 'distributions']);
    }

    public static function canViewAny(): bool
    {
        return true;
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

    public static function table(Table $table): Table
    {
        return InvoiceResource::table($table);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return InvoiceResource::infolist($infolist);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDistributorInvoices::route('/'),
            'view' => Pages\ViewDistributorInvoice::route('/{record}'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            ReturnsRelationManager::class,
        ];
    }
}
