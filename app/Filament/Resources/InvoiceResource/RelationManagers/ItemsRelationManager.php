<?php

namespace App\Filament\Resources\InvoiceResource\RelationManagers;

use App\Filament\Resources\InvoiceResource;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Services\InvoiceAdjustmentService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
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

            Forms\Components\Hidden::make('unit_price_cents')
                ->default(0)
                ->dehydrated(),

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

                Tables\Columns\TextColumn::make('points_per_unit')
                    ->label('النقاط/وحدة')
                    ->badge()
                    ->color('warning')
                    ->visible(fn () => \App\Support\PointsVisibility::canSeePerUnit()),

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
                        $data['unit_price_cents'] = (int) ($data['unit_price_cents'] ?? 0);
                        $data['total_points'] = (int) floor(
                            $data['quantity'] * $data['points_per_unit']
                        );

                        return $data;
                    })
                    ->visible(fn () => ($this->getOwnerRecord()->status === 'pending_review'
                            && ! $this->getOwnerRecord()->isWholesalePos())
                        || InvoiceResource::canAdjustInvoiceQuantities($this->getOwnerRecord()))
                    ->using(function (array $data) {
                        return app(InvoiceAdjustmentService::class)->addLine(
                            $this->getOwnerRecord(),
                            (int) $data['product_id'],
                            (int) $data['quantity'],
                            isset($data['points_per_unit']) ? (float) $data['points_per_unit'] : null,
                        );
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('editQuantity')
                    ->label('تعديل الكمية')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn () => InvoiceResource::canAdjustInvoiceQuantities($this->getOwnerRecord()))
                    ->fillForm(fn (InvoiceItem $record) => ['quantity' => $record->quantity])
                    ->form([
                        Forms\Components\TextInput::make('quantity')
                            ->label('الكمية')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->helperText(function (InvoiceItem $record) {
                                $committed = app(InvoiceAdjustmentService::class)->committedQuantity($record);

                                return $committed > 0
                                    ? "الحد الأدنى {$committed} لأن هذه الكمية موزّعة"
                                    : 'تعديل الكمية يعيد حساب نقاط البند';
                            }),
                    ])
                    ->action(function (InvoiceItem $record, array $data) {
                        try {
                            app(InvoiceAdjustmentService::class)->updateQuantity($record, (int) $data['quantity']);
                            Notification::make()->success()->title('تم تعديل الكمية')->send();
                        } catch (\DomainException $e) {
                            Notification::make()->danger()->title('تعذّر التعديل')->body($e->getMessage())->send();
                        }
                    }),

                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_price_cents'] = (int) ($data['unit_price_cents'] ?? 0);
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
