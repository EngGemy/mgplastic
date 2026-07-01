<?php

namespace App\Filament\Concerns;

use Filament\Forms;
use Filament\Forms\Get;

trait HasProductPointsForm
{
    /** حقول النقاط وتحويلها — على مستوى كل منتج */
    protected static function productPointsSchema(): array
    {
        return [
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('points_per_unit')
                    ->label('النقاط لكل وحدة')
                    ->helperText('⚠️ مطلوب — المنتجات بدون نقاط لا تظهر في نظام التوزيع والـ POS')
                    ->numeric()
                    ->minValue(0.0001)
                    ->step(0.0001)
                    ->default(0)
                    ->suffix('نقطة/وحدة')
                    ->required()
                    ->rules(['required', 'numeric', 'min:0.0001'])
                    ->validationMessages([
                        'min' => 'النقاط يجب أن تكون أكبر من صفر',
                        'required' => 'يجب تحديد قيمة النقاط لهذا المنتج',
                    ])
                    ->live(debounce: 300),

                Forms\Components\Select::make('point_value_type')
                    ->label('نوع تحويل النقاط')
                    ->options([
                        'percent' => 'نسبة من سعر/تكلفة الوحدة',
                        'fixed' => 'قيمة ثابتة لكل نقطة',
                    ])
                    ->placeholder('اختر نوع التحويل')
                    ->required()
                    ->live(),
            ]),

            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('reference_unit_price_dinars')
                    ->label('سعر/تكلفة الوحدة المرجعية')
                    ->helperText('السعر المرجعي الذي تُحسب منه النسبة (د.ل)')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->suffix('د.ل')
                    ->visible(fn (Get $get) => $get('point_value_type') === 'percent')
                    ->required(fn (Get $get) => $get('point_value_type') === 'percent')
                    ->dehydrated(false)
                    ->afterStateHydrated(function ($component, $state, ?\App\Models\Product $record) {
                        if ($state !== null && $state !== '') {
                            return;
                        }
                        $cents = $record?->reference_unit_price_cents;
                        if ($cents) {
                            $component->state(number_format($cents / 100, 2, '.', ''));
                        }
                    }),

                Forms\Components\TextInput::make('point_value_percent')
                    ->label('نسبة قيمة النقاط')
                    ->helperText('مثال: 1.5 يعني 1.5% من سعر الوحدة = إجمالي قيمة نقاط الوحدة')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.0001)
                    ->suffix('%')
                    ->visible(fn (Get $get) => $get('point_value_type') === 'percent')
                    ->required(fn (Get $get) => $get('point_value_type') === 'percent')
                    ->live(debounce: 300),

                Forms\Components\TextInput::make('point_value_fixed')
                    ->label('قيمة النقطة الواحدة')
                    ->helperText('مثال: 0.10 يعني كل نقطة = 0.10 د.ل')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001)
                    ->suffix('د.ل / نقطة')
                    ->visible(fn (Get $get) => $get('point_value_type') === 'fixed')
                    ->required(fn (Get $get) => $get('point_value_type') === 'fixed')
                    ->live(debounce: 300),
            ]),

            Forms\Components\ViewField::make('points_conversion_preview')
                ->view('filament.forms.product-points-preview')
                ->columnSpanFull(),
        ];
    }

    protected static function productPointsSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('النقاط وقيمة التحويل')
            ->description('حدّد نقاط/وحدة ثم طريقة تحويلها لقيمة مالية (نسبة من سعر الوحدة أو قيمة ثابتة لكل نقطة)')
            ->icon('heroicon-o-star')
            ->schema(static::productPointsSchema());
    }

    /** تحضير حقول النقاط قبل الحفظ */
    public static function mergeProductPointsIntoData(array $data): array
    {
        if (($data['point_value_type'] ?? null) === 'percent') {
            $dinars = (float) ($data['reference_unit_price_dinars'] ?? 0);
            $data['reference_unit_price_cents'] = (int) round($dinars * 100);
            $data['point_value_fixed'] = null;
        } elseif (($data['point_value_type'] ?? null) === 'fixed') {
            $data['reference_unit_price_cents'] = null;
            $data['point_value_percent'] = null;
        }

        unset($data['reference_unit_price_dinars']);

        return $data;
    }
}
