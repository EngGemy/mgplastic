<?php

namespace App\Filament\Trader\Resources;

use App\Filament\Concerns\ScopesByNetworkRole;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Trader\Resources\TraderInvoiceResource\Pages;
use App\Models\Invoice;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TraderInvoiceResource extends Resource
{
    use ScopesByNetworkRole;

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
        return static::scopeInvoicesForRole(parent::getEloquentQuery())
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
            'index' => Pages\ListTraderInvoices::route('/'),
            'view' => Pages\ViewTraderInvoice::route('/{record}'),
        ];
    }
}
