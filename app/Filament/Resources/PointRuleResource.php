<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\PointRuleResource\Pages;
use App\Models\PlumberStore;
use App\Models\PointRule;
use App\Models\SystemLabel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PointRuleResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = PointRule::class;
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';
    protected static ?int $navigationSort = 60;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string { return 'نظام النقاط'; }
    public static function getNavigationLabel(): string { return SystemLabel::get('point_rules', 'قواعد النقاط'); }
    public static function getPluralModelLabel(): string { return SystemLabel::get('point_rules', 'قواعد النقاط'); }
    public static function getModelLabel(): string { return SystemLabel::get('point_rules', 'قاعدة نقاط'); }

    public static function form(Form $form): Form
    {
        // Locale-aware vendor store select (translatable name)
        $optionsFn = function () {
            $locale = app()->getLocale();
            return PlumberStore::query()
                ->join('plumber_store_translations as pst', 'pst.plumber_store_id', '=', 'plumber_stores.id')
                ->where('pst.locale', $locale)
                ->orderBy('pst.name')
                ->limit(50)
                ->pluck('pst.name', 'plumber_stores.id')
                ->toArray();
        };

        $searchFn = function (string $search) {
            $locale = app()->getLocale();
            return PlumberStore::query()
                ->join('plumber_store_translations as pst', 'pst.plumber_store_id', '=', 'plumber_stores.id')
                ->where('pst.locale', $locale)
                ->where('pst.name', 'like', "%{$search}%")
                ->orderBy('pst.name')
                ->limit(50)
                ->pluck('pst.name', 'plumber_stores.id')
                ->toArray();
        };

        $labelFn = function ($value) {
            if (!$value) return null;
            $locale = app()->getLocale();
            return PlumberStore::query()
                ->join('plumber_store_translations as pst', 'pst.plumber_store_id', '=', 'plumber_stores.id')
                ->where('plumber_stores.id', $value)
                ->where('pst.locale', $locale)
                ->value('pst.name');
        };

        return $form->schema([
            Forms\Components\Section::make('نطاق القاعدة')
                ->description('قاعدة عامة لكل المتاجر، أو مخصّصة لمتجر معيّن.')
                ->icon('heroicon-o-adjustments-horizontal')
                ->columns(12)
                ->schema([
                    Forms\Components\Select::make('vendor_store_id')
                        ->label('متجر البائع (اختياري)')
                        ->searchable()
                        ->options($optionsFn)
                        ->getSearchResultsUsing($searchFn)
                        ->getOptionLabelUsing($labelFn)
                        ->helperText('اتركه فارغًا لقاعدة عامة.')
                        ->columnSpan(5),
                    Forms\Components\TextInput::make('name')
                        ->label('اسم القاعدة')
                        ->maxLength(100)
                        ->placeholder('عرض خريف 1.5%')
                        ->columnSpan(5),
                    Forms\Components\Toggle::make('is_active')
                        ->label('مفعّلة')
                        ->default(true)
                        ->inline(false)
                        ->columnSpan(2),
                ]),

            Forms\Components\Section::make('حساب النقاط')
                ->icon('heroicon-o-sparkles')
                ->columns(12)
                ->schema([
                    Forms\Components\Select::make('type')
                        ->label('النوع')
                        ->options(['percent' => 'نسبة مئوية', 'fixed' => 'نقاط ثابتة'])
                        ->required()
                        ->native(false)
                        ->live()
                        ->columnSpan(4),
                    Forms\Components\TextInput::make('percent_rate')
                        ->label('النسبة %')
                        ->numeric()->step('0.0001')
                        ->visible(fn ($get) => $get('type') === 'percent')
                        ->helperText('مثال: 1.50 = 1.5٪ من مبلغ الفاتورة')
                        ->columnSpan(4),
                    Forms\Components\TextInput::make('fixed_points')
                        ->label('نقاط ثابتة')
                        ->numeric()
                        ->visible(fn ($get) => $get('type') === 'fixed')
                        ->helperText('مثال: 200 نقطة لكل فاتورة')
                        ->columnSpan(4),
                ]),

            Forms\Components\Section::make('قيود التطبيق')
                ->icon('heroicon-o-funnel')
                ->columns(4)
                ->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('min_total_cents')->label('حد أدنى للمبلغ (سنت)')->numeric(),
                    Forms\Components\TextInput::make('max_total_cents')->label('حد أقصى للمبلغ (سنت)')->numeric(),
                    Forms\Components\DateTimePicker::make('starts_at')->label('تبدأ في')->seconds(false),
                    Forms\Components\DateTimePicker::make('ends_at')->label('تنتهي في')->seconds(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('الاسم')->weight('semibold')->searchable(),
                Tables\Columns\TextColumn::make('vendor_store_id')
                    ->label('المتجر')
                    ->formatStateUsing(fn ($state, $record) => optional($record->vendorStore)->name ?: 'عام'),
                Tables\Columns\TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state === 'fixed' ? 'ثابت' : 'نسبة'),
                Tables\Columns\TextColumn::make('percent_rate')->label('%')->toggleable(),
                Tables\Columns\TextColumn::make('fixed_points')->label('نقاط')->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label('مفعّلة')->boolean(),
                Tables\Columns\TextColumn::make('starts_at')->label('تبدأ')->dateTime()->since()->toggleable(),
                Tables\Columns\TextColumn::make('ends_at')->label('تنتهي')->dateTime()->since()->toggleable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل')->icon('heroicon-o-pencil-square')->color('info'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('قاعدة جديدة')->icon('heroicon-o-plus'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPointRules::route('/'),
            'create' => Pages\CreatePointRule::route('/create'),
            'edit'   => Pages\EditPointRule::route('/{record}/edit'),
        ];
    }
}
