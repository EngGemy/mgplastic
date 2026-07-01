<?php

namespace App\Filament\Resources\InvoiceResource\RelationManagers;

use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'بنود الفاتورة (المنتجات)';

    protected static ?string $icon = 'heroicon-o-shopping-cart';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('product_id')
                ->label('المنتج')
                ->options(fn () => Product::query()
                    ->with('translations')
                    ->get()
                    ->mapWithKeys(fn (Product $p) => [
                        $p->id => localized_name($p, 'name', "منتج #{$p->id}"),
                    ]))
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(function ($state, Forms\Set $set) {
                    $product = Product::find($state);
                    if ($product) {
                        $set('points_per_unit', $product->points_per_unit);
                        $set('unit_price_cents', 0);
                    }
                }),

            Forms\Components\TextInput::make('quantity')
                ->label('الكمية')
                ->numeric()
                ->minValue(1)
                ->required(),

            Forms\Components\TextInput::make('unit_price_cents')
                ->label('سعر الوحدة (د.ل)')
                ->numeric()
                ->minValue(0)
                ->helperText('أدخل السعر بالدينار الليبي')
                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : '0')
                ->required(),

            Forms\Components\TextInput::make('points_per_unit')
                ->label('النقاط/وحدة (snapshot)')
                ->numeric()
                ->minValue(0)
                ->helperText('يُحدَّد تلقائياً من المنتج — يمكن تعديله')
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('المنتج')
                    ->state(fn ($record) => localized_name($record->product, 'name'))
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('الكمية')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('unit_price_cents')
                    ->label('سعر الوحدة')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 2).' د.ل'),

                Tables\Columns\TextColumn::make('points_per_unit')
                    ->label('النقاط/وحدة')
                    ->badge()
                    ->color('warning'),

                Tables\Columns\TextColumn::make('total_points')
                    ->label('إجمالي النقاط')
                    ->badge()
                    ->color('success')
                    ->weight('bold'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('إضافة منتج')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['total_points'] = (int) floor(
                            $data['quantity'] * $data['points_per_unit']
                        );

                        return $data;
                    })
                    ->visible(fn () => $this->getOwnerRecord()->status === 'pending_review'
                        && ! $this->getOwnerRecord()->isWholesalePos()),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['total_points'] = (int) floor(
                            $data['quantity'] * $data['points_per_unit']
                        );

                        return $data;
                    })
                    ->visible(fn () => $this->getOwnerRecord()->status === 'pending_review'
                        && ! $this->getOwnerRecord()->isWholesalePos()),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => $this->getOwnerRecord()->status === 'pending_review'
                        && ! $this->getOwnerRecord()->isWholesalePos()),
            ])
            ->emptyStateHeading('لا يوجد منتجات في الفاتورة')
            ->emptyStateDescription('أضف المنتجات والكميات لتحديد نقاط كل منتج')
            ->emptyStateIcon('heroicon-o-shopping-cart');
    }
}
