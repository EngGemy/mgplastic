<?php

namespace App\Filament\Resources\StoreResource\RelationManagers;

use App\Filament\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\WholesaleInvoiceStatsService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class WholesaleInvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'wholesaleInvoices';

    protected static ?string $title = 'الفواتير والنقاط';

    protected static ?string $icon = 'heroicon-o-document-text';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->approvedWholesale()
                ->withDistributionStats()
                ->latest('approved_at'))
            ->heading('فواتير الجملة المعتمدة')
            ->description(fn () => new HtmlString(
                view('filament.components.store-invoice-stats', [
                    'stats' => WholesaleInvoiceStatsService::forDistributor($this->getOwnerRecord()->id),
                ])->render()
            ))
            ->defaultSort('approved_at', 'desc')
            ->striped()
            ->paginated([10, 25, 50])
            ->emptyStateHeading('لا توجد فواتير معتمدة')
            ->emptyStateDescription('ستظهر هنا فواتير الجملة بعد اعتمادها')
            ->emptyStateIcon('heroicon-o-document-text')
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('رقم الفاتورة')
                    ->searchable()
                    ->weight('bold')
                    ->icon('heroicon-o-document-text')
                    ->iconColor('primary')
                    ->description(fn (Invoice $record) => $record->approved_at?->format('Y/m/d')),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('البنود')
                    ->alignCenter()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('total_item_points')
                    ->label('إجمالي النقاط')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => number_format((int) $state))
                    ->suffix(' نقطة')
                    ->color('warning')
                    ->weight('semibold'),

                Tables\Columns\ViewColumn::make('distribution_progress')
                    ->label('التوزيع')
                    ->view('filament.tables.columns.invoice-distribution-progress'),

                Tables\Columns\TextColumn::make('distributed_points_sum')
                    ->label('موزّع')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => number_format((int) $state))
                    ->color('success')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('remaining_points')
                    ->label('متبقي')
                    ->alignCenter()
                    ->state(fn (Invoice $record) => $record->remainingPointsSum())
                    ->formatStateUsing(fn ($state) => number_format((int) $state))
                    ->color(fn ($state) => (int) $state > 0 ? 'warning' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('has_remaining')
                    ->label('فيها نقاط متبقية')
                    ->query(fn ($query) => $query->whereRaw('(
                        SELECT COALESCE(SUM(ii.total_points), 0) FROM invoice_items ii WHERE ii.invoice_id = invoices.id
                    ) > (
                        SELECT COALESCE(SUM(idi.points_value), 0)
                        FROM invoice_distribution_items idi
                        INNER JOIN invoice_distributions id ON id.id = idi.distribution_id
                        WHERE id.invoice_id = invoices.id
                        AND id.status IN (\'confirmed\', \'points_awarded\')
                    )')),

                Tables\Filters\Filter::make('fully_distributed')
                    ->label('موزّعة بالكامل')
                    ->query(fn ($query) => $query->whereRaw('(
                        SELECT COALESCE(SUM(ii.total_points), 0) FROM invoice_items ii WHERE ii.invoice_id = invoices.id
                    ) <= (
                        SELECT COALESCE(SUM(idi.points_value), 0)
                        FROM invoice_distribution_items idi
                        INNER JOIN invoice_distributions id ON id.id = idi.distribution_id
                        WHERE id.invoice_id = invoices.id
                        AND id.status IN (\'confirmed\', \'points_awarded\')
                    )')),
            ])
            ->actions([
                Tables\Actions\Action::make('items')
                    ->label('البنود')
                    ->icon('heroicon-o-list-bullet')
                    ->color('gray')
                    ->modalHeading(fn (Invoice $record) => 'بنود الفاتورة '.$record->number)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('إغلاق')
                    ->modalWidth('3xl')
                    ->modalContent(fn (Invoice $record) => view('filament.modals.wholesale-invoice-items', [
                        'invoice' => $record->load([
                            'items.product.translations',
                            'items.distributionItems.distribution',
                        ]),
                    ])),

                Tables\Actions\Action::make('view')
                    ->label('عرض')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Invoice $record) => InvoiceResource::getUrl('view', ['record' => $record]))
                    ->openUrlInNewTab(false),
            ]);
    }
}
