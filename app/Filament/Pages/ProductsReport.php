<?php

namespace App\Filament\Pages;

use App\Models\InvoiceDistributionItem;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductCategory;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductsReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-bar';

    protected static ?int $navigationSort = 50;

    protected static string $view = 'filament.pages.products-report';

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin'], true);
    }

    public static function getNavigationGroup(): ?string
    {
        return 'نظام النقاط';
    }

    public static function getNavigationLabel(): string
    {
        return 'تقرير المنتجات والنقاط';
    }

    public function getTitle(): string
    {
        return 'تقرير المنتجات وتوزيع النقاط';
    }

    public function getSummaryStats(): array
    {
        $totalProducts = Product::count();
        $withPoints = Product::where('points_per_unit', '>', 0)->count();
        $avgPoints = (float) Product::where('points_per_unit', '>', 0)->avg('points_per_unit');
        $totalSold = (int) InvoiceItem::sum('quantity');
        $totalAwarded = (int) InvoiceDistributionItem::query()
            ->whereHas('distribution', fn ($q) => $q->where('tier', 3)->where('status', 'points_awarded'))
            ->sum('points_value');

        return compact('totalProducts', 'withPoints', 'avgPoints', 'totalSold', 'totalAwarded');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->productsQuery())
            ->columns([
                Tables\Columns\ImageColumn::make('main_image')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->size(40),

                Tables\Columns\TextColumn::make('name')
                    ->label('المنتج')
                    ->state(fn (Product $p) => localized_name($p, 'name', "منتج #{$p->id}"))
                    ->searchable(query: fn (Builder $q, string $s) => $q->whereTranslationLike('name', "%{$s}%"))
                    ->wrap()
                    ->weight('bold')
                    ->limit(45),

                Tables\Columns\TextColumn::make('category_display')
                    ->label('الفئة')
                    ->state(function (Product $p) {
                        $cat = $p->category?->loadMissing('parent.translations', 'translations');
                        if (! $cat) {
                            return '—';
                        }
                        $parent = $cat->parent ? localized_name($cat->parent, 'name').' ← ' : '';

                        return $parent.localized_name($cat, 'name');
                    })
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('points_per_unit')
                    ->label('نقطة/وحدة')
                    ->badge()
                    ->color(fn ($state) => (float) $state > 0 ? 'success' : 'gray')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2))
                    ->sortable(),

                Tables\Columns\TextColumn::make('point_conversion')
                    ->label('تحويل النقاط')
                    ->state(fn (Product $p) => $p->pointConversionSummary())
                    ->badge()
                    ->color('warning')
                    ->wrap(),

                Tables\Columns\TextColumn::make('unit_point_value')
                    ->label('قيمة الوحدة')
                    ->state(fn (Product $p) => number_format($p->pointMonetaryValuePerUnit(), 2).' د.ل')
                    ->sortable(query: function (Builder $q, string $direction) {
                        // sort by points_per_unit as proxy
                        $q->orderBy('points_per_unit', $direction);
                    }),

                Tables\Columns\TextColumn::make('points_example_10')
                    ->label('10 وحدات =')
                    ->state(fn (Product $p) => (int) floor(10 * (float) $p->points_per_unit).' نقطة')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('sold_qty')
                    ->label('مباع (فواتير)')
                    ->badge()
                    ->color('primary')
                    ->sortable(),

                Tables\Columns\TextColumn::make('awarded_points')
                    ->label('نقاط ممنوحة')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn ($state) => number_format((int) $state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('distribution_status')
                    ->label('حالة التوزيع')
                    ->state(function (Product $p) {
                        $sold = (int) ($p->sold_qty ?? 0);
                        $awarded = (int) ($p->awarded_points ?? 0);
                        $expected = (int) floor($sold * (float) $p->points_per_unit);

                        if ($sold === 0) {
                            return 'لم يُبَع بعد';
                        }
                        if ($awarded >= $expected && $expected > 0) {
                            return '✓ مكتمل';
                        }
                        if ($awarded > 0) {
                            return 'جزئي';
                        }

                        return 'قيد التوزيع';
                    })
                    ->badge()
                    ->color(fn (string $state) => match (true) {
                        str_contains($state, 'مكتمل') => 'success',
                        $state === 'جزئي' => 'warning',
                        $state === 'قيد التوزيع' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('classification')
                    ->label('التصنيف')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('product_category_id')
                    ->label('الفئة')
                    ->options(function () {
                        return ProductCategory::with('translations')->get()
                            ->sortBy(fn ($c) => localized_name($c, 'name'))
                            ->mapWithKeys(fn ($c) => [$c->id => localized_name($c, 'name')])
                            ->toArray();
                    })
                    ->searchable(),

                Tables\Filters\TernaryFilter::make('has_points')
                    ->label('له نقاط')
                    ->queries(
                        true: fn ($q) => $q->where('points_per_unit', '>', 0),
                        false: fn ($q) => $q->where(fn ($qq) => $qq->whereNull('points_per_unit')->orWhere('points_per_unit', '<=', 0)),
                    ),

                Tables\Filters\Filter::make('sold')
                    ->label('مباع في فواتير')
                    ->query(fn (Builder $q) => $q->whereHas('invoiceItems')),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('عرض')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Product $record) => \App\Filament\Resources\ProductResource::getUrl('view', ['record' => $record])),
            ])
            ->headerActions([
                Tables\Actions\Action::make('add_product')
                    ->label('إضافة منتج')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->url(fn () => \App\Filament\Resources\ProductResource::getUrl('create')),

                Tables\Actions\Action::make('exportCsv')
                    ->label('تصدير CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn () => $this->exportCsv()),
            ])
            ->emptyStateHeading('لا توجد منتجات')
            ->emptyStateDescription('أضف منتجات لعرض تقرير النقاط وتوزيعها')
            ->defaultSort('points_per_unit', 'desc');
    }

    protected function productsQuery(): Builder
    {
        return Product::query()
            ->select('products.*')
            ->with(['category.parent.translations', 'category.translations', 'translations'])
            ->withSum('invoiceItems as sold_qty', 'quantity')
            ->selectSub(
                InvoiceDistributionItem::query()
                    ->selectRaw('COALESCE(SUM(invoice_distribution_items.points_value), 0)')
                    ->join('invoice_items', 'invoice_items.id', '=', 'invoice_distribution_items.invoice_item_id')
                    ->join('invoice_distributions', 'invoice_distributions.id', '=', 'invoice_distribution_items.distribution_id')
                    ->whereColumn('invoice_items.product_id', 'products.id')
                    ->where('invoice_distributions.tier', 3)
                    ->where('invoice_distributions.status', 'points_awarded'),
                'awarded_points'
            );
    }

    protected function exportCsv(): StreamedResponse
    {
        $filename = 'products_points_report_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, [
                'ID', 'المنتج', 'الفئة', 'نقطة/وحدة', 'تحويل', 'قيمة الوحدة (د.ل)', 'مباع', 'نقاط ممنوحة',
            ]);

            $this->productsQuery()->orderBy('id')->chunk(200, function ($chunk) use ($out) {
                foreach ($chunk as $p) {
                    $cat = $p->category;
                    $catLabel = $cat ? localized_name($cat, 'name') : '';

                    fputcsv($out, [
                        $p->id,
                        localized_name($p, 'name'),
                        $catLabel,
                        number_format((float) $p->points_per_unit, 2),
                        $p->pointConversionSummary(),
                        number_format($p->pointMonetaryValuePerUnit(), 2),
                        (int) ($p->sold_qty ?? 0),
                        (int) ($p->awarded_points ?? 0),
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
