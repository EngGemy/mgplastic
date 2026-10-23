<?php

namespace App\Filament\Pages;

use App\Models\Product;
use App\Models\ProductPointsRateChange;
use App\Services\ProductPointsRateService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductPointsRates extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string $view = 'filament.pages.product-points-rates';

    protected static ?int $navigationSort = 2;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $impactPreview = null;

    public static function getNavigationGroup(): ?string
    {
        return 'أسعار نقاط المنتجات';
    }

    public static function getNavigationLabel(): string
    {
        return 'تغيير السعر';
    }

    public function getTitle(): string
    {
        return 'تغيير سعر نقاط منتج';
    }

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin', 'monitor'], true);
    }

    public function mount(): void
    {
        $this->form->fill([
            'product_id' => null,
            'new_points_per_unit' => null,
            'note' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('product_id')
                    ->label('المنتج')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->options(function () {
                        return Product::query()
                            ->with('translations')
                            ->orderByDesc('id')
                            ->limit(400)
                            ->get()
                            ->mapWithKeys(fn (Product $p) => [
                                $p->id => localized_name($p, 'name', 'منتج #'.$p->id)
                                    .' · '.number_format((float) ($p->points_per_unit ?? 0), 2).' نق/و',
                            ])
                            ->all();
                    })
                    ->getSearchResultsUsing(function (string $search) {
                        return Product::query()
                            ->with('translations')
                            ->search($search)
                            ->limit(40)
                            ->get()
                            ->mapWithKeys(fn (Product $p) => [
                                $p->id => localized_name($p, 'name', 'منتج #'.$p->id)
                                    .' · '.number_format((float) ($p->points_per_unit ?? 0), 2).' نق/و',
                            ])
                            ->all();
                    })
                    ->getOptionLabelUsing(function ($value) {
                        $p = Product::with('translations')->find($value);

                        return $p
                            ? localized_name($p, 'name', 'منتج #'.$p->id).' · '.number_format((float) ($p->points_per_unit ?? 0), 2)
                            : (string) $value;
                    })
                    ->live()
                    ->afterStateUpdated(fn () => $this->refreshImpactPreview()),

                Forms\Components\TextInput::make('new_points_per_unit')
                    ->label('السعر الجديد (نقطة / وحدة)')
                    ->numeric()
                    ->minValue(0.0001)
                    ->step(0.0001)
                    ->required()
                    ->suffix('نقطة')
                    ->live(debounce: 400)
                    ->afterStateUpdated(fn () => $this->refreshImpactPreview()),

                Forms\Components\Textarea::make('note')
                    ->label('ملاحظة داخلية (اختياري)')
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull(),
            ])
            ->columns(2)
            ->statePath('data');
    }

    public function refreshImpactPreview(): void
    {
        $productId = (int) ($this->data['product_id'] ?? 0);
        $new = (float) ($this->data['new_points_per_unit'] ?? 0);

        if ($productId <= 0 || $new <= 0) {
            $this->impactPreview = null;

            return;
        }

        $product = Product::query()->find($productId);
        if (! $product) {
            $this->impactPreview = null;

            return;
        }

        $this->impactPreview = app(ProductPointsRateService::class)->estimateImpact($product, $new);
        $this->impactPreview['product_name'] = localized_name($product, 'name', 'منتج #'.$product->id);
        $this->impactPreview['product_image'] = $product->main_image;
    }

    public function applyRateChange(): void
    {
        if (! in_array(auth()->user()?->role, ['super_admin', 'admin'], true)) {
            Notification::make()->title('غير مصرّح')->danger()->send();

            return;
        }

        $state = $this->form->getState();
        $product = Product::query()->findOrFail((int) $state['product_id']);

        try {
            $change = app(ProductPointsRateService::class)->applyChange(
                $product,
                (float) $state['new_points_per_unit'],
                auth()->user(),
                $state['note'] ?? null,
            );
        } catch (\DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title('تم تحديث سعر النقاط')
            ->body(sprintf(
                'من %s → %s · تقدير الفرق على المخزون المتبقي: %+d نقطة',
                number_format((float) $change->old_points_per_unit, 2),
                number_format((float) $change->new_points_per_unit, 2),
                (int) $change->estimated_points_delta,
            ))
            ->success()
            ->send();

        $this->form->fill([
            'product_id' => $product->id,
            'new_points_per_unit' => (float) $change->new_points_per_unit,
            'note' => null,
        ]);
        $this->refreshImpactPreview();
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ProductPointsRateChange::query()
                    ->with(['product.translations', 'author'])
                    ->latest()
            )
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('product_name')
                    ->label('المنتج')
                    ->state(fn (ProductPointsRateChange $r) => localized_name($r->product, 'name', 'منتج #'.$r->product_id))
                    ->wrap()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('product', fn (Builder $q) => $q->search($search));
                    }),

                Tables\Columns\TextColumn::make('old_points_per_unit')
                    ->label('قبل')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2)),

                Tables\Columns\TextColumn::make('new_points_per_unit')
                    ->label('بعد')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2))
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('change_percent')
                    ->label('الهامش %')
                    ->badge()
                    ->color(fn (ProductPointsRateChange $r) => match ($r->direction()) {
                        'up' => 'warning',
                        'down' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(function ($state, ProductPointsRateChange $r) {
                        if ($state === null) {
                            return '—';
                        }
                        $sign = (float) $r->delta_points_per_unit >= 0 ? '+' : '';

                        return $sign.number_format((float) $state, 1).'%';
                    }),

                Tables\Columns\TextColumn::make('wholesale_remaining_qty')
                    ->label('مخزون جملة')
                    ->formatStateUsing(fn ($state, ProductPointsRateChange $r) => number_format((int) $state).' ('.$r->wholesale_holders.')'),

                Tables\Columns\TextColumn::make('retail_remaining_qty')
                    ->label('مخزون قطاعي')
                    ->formatStateUsing(fn ($state, ProductPointsRateChange $r) => number_format((int) $state).' ('.$r->retail_holders.')'),

                Tables\Columns\TextColumn::make('estimated_points_delta')
                    ->label('تقدير فرق النقاط')
                    ->formatStateUsing(fn ($state) => sprintf('%+d', (int) $state))
                    ->color(fn ($state) => (int) $state > 0 ? 'warning' : ((int) $state < 0 ? 'success' : 'gray')),

                Tables\Columns\TextColumn::make('author.name')
                    ->label('بواسطة')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('note')
                    ->label('ملاحظة')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    public function canApply(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin'], true);
    }
}
