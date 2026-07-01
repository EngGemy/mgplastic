<?php

namespace App\Filament\Widgets;

use App\Models\ProductCategory;
use Filament\Widgets\ChartWidget;

class ProductsByCategoryChart extends ChartWidget
{
    protected static bool $isDiscovered = false;
    protected static ?string $maxHeight = '240px';

    /** Must be public to match parent */
    public function getHeading(): string
    {
        return __('Products by Category');
    }

    protected function getData(): array
    {
        $locale = app()->getLocale();

        $rows = ProductCategory::with('translations')
            ->withCount('products')
            ->orderByDesc('products_count')
            ->take(10)
            ->get();

        $labels = $rows->map(fn ($cat) =>
            optional($cat->translateOrDefault($locale))->name
            ?? optional($cat->translate('en'))->name
            ?? "Category #{$cat->id}"
        )->all();

        $data = $rows->pluck('products_count')->all();

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => __('Products'),
                    'data'  => $data,
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
