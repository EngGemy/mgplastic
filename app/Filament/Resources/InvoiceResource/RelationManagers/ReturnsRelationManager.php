<?php

namespace App\Filament\Resources\InvoiceResource\RelationManagers;

use App\Filament\Concerns\HandlesInvoiceReturns;
use App\Models\Invoice;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ReturnsRelationManager extends RelationManager
{
    use HandlesInvoiceReturns;

    protected static string $relationship = 'returns';

    protected static ?string $title = 'المرتجعات';

    protected static ?string $icon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $recordTitleAttribute = 'return_number';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        /** @var Invoice $ownerRecord */
        return $ownerRecord->isWholesalePos() && $ownerRecord->isOutgoing();
    }

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->with(['items.product.translations', 'fromUser', 'toUser', 'creator'])
                ->where('status', 'confirmed')
                ->latest('id'))
            ->columns([
                Tables\Columns\TextColumn::make('return_number')
                    ->label('رقم المرتجع')
                    ->weight('bold')
                    ->searchable()
                    ->color('danger'),

                Tables\Columns\TextColumn::make('total_quantity')
                    ->label('الكمية')
                    ->formatStateUsing(fn ($state) => '−'.number_format((int) $state))
                    ->badge()
                    ->color('danger'),

                Tables\Columns\TextColumn::make('total_points')
                    ->label('النقاط')
                    ->formatStateUsing(fn ($state) => '−'.number_format((int) $state))
                    ->badge()
                    ->color('warning'),

                Tables\Columns\TextColumn::make('fromUser.name')
                    ->label('من (المستلم)')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('toUser.name')
                    ->label('إلى (المورّد)')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('confirmed_at')
                    ->label('التاريخ')
                    ->dateTime('Y/m/d H:i')
                    ->timezone('Africa/Tripoli')
                    ->sortable(),

                Tables\Columns\TextColumn::make('note')
                    ->label('ملاحظة')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->headerActions([
                $this->invoiceReturnTableAction(),
            ])
            ->actions([
                Tables\Actions\Action::make('details')
                    ->label('التفاصيل')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn ($record) => 'مرتجع '.$record->return_number)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('إغلاق')
                    ->modalContent(fn ($record) => view('filament.infolists.invoice-return-details', [
                        'return' => $record->loadMissing(['items.product.translations', 'fromUser', 'toUser']),
                    ])),
            ])
            ->bulkActions([])
            ->emptyStateHeading('لا توجد مرتجعات')
            ->emptyStateDescription('سجّل مرتجعاً لخصم الكميات والنقاط من صافي هذه الفاتورة')
            ->emptyStateIcon('heroicon-o-arrow-uturn-left')
            ->heading(function () {
                $summary = $this->getOwnerRecord()->returnSummary();

                if ($summary['returns_count'] <= 0) {
                    return 'المرتجعات';
                }

                return sprintf(
                    'المرتجعات — صافي %s وحدة / %s نقطة (مرتجع −%s / −%s)',
                    number_format($summary['net_qty']),
                    number_format($summary['net_points']),
                    number_format($summary['returned_qty']),
                    number_format($summary['returned_points']),
                );
            });
    }
}
