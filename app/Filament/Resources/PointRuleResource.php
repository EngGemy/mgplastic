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
            Forms\Components\Section::make(__('Scope'))
                ->schema([
                    Forms\Components\Select::make('vendor_store_id')
                        ->label(__('Vendor Store (optional)'))
                        ->searchable()
                        ->options($optionsFn)
                        ->getSearchResultsUsing($searchFn)
                        ->getOptionLabelUsing($labelFn)
                        ->helperText(__('Leave empty for a global rule.')),
                    Forms\Components\TextInput::make('name')
                        ->label(__('Name'))
                        ->maxLength(100)
                        ->placeholder(__('Autumn promo 1.5%')),
                    Forms\Components\Toggle::make('is_active')->label(__('Active'))->default(true),
                ])->columns(3)->icon('heroicon-o-adjustments-horizontal'),

            Forms\Components\Section::make(__('Rule'))
                ->schema([
                    Forms\Components\Select::make('type')
                        ->label(__('Type'))
                        ->options(['percent' => __('Percent'), 'fixed' => __('Fixed')])
                        ->required()
                        ->reactive(),
                    Forms\Components\TextInput::make('percent_rate')
                        ->label(__('Percent rate (%)'))
                        ->numeric()->step('0.0001')
                        ->visible(fn ($get) => $get('type') === 'percent')
                        ->helperText(__('e.g., 1.50 = 1.5% × (amount in currency units) points')),
                    Forms\Components\TextInput::make('fixed_points')
                        ->label(__('Fixed points'))
                        ->numeric()
                        ->visible(fn ($get) => $get('type') === 'fixed')
                        ->helperText(__('e.g., 200 points per invoice')),
                ])->columns(3)->icon('heroicon-o-sparkles'),

            Forms\Components\Section::make(__('Constraints'))
                ->schema([
                    Forms\Components\TextInput::make('min_total_cents')->label(__('Min amount (¢)'))->numeric(),
                    Forms\Components\TextInput::make('max_total_cents')->label(__('Max amount (¢)'))->numeric(),
                    Forms\Components\DateTimePicker::make('starts_at')->label(__('Starts at')),
                    Forms\Components\DateTimePicker::make('ends_at')->label(__('Ends at')),
                ])->columns(4)->icon('heroicon-o-funnel'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('Name'))->weight('semibold')->searchable(),
                Tables\Columns\TextColumn::make('vendor_store_id')
                    ->label(__('Vendor'))
                    ->formatStateUsing(fn ($state, $record) => optional($record->vendorStore)->name ?: '-'),
                Tables\Columns\TextColumn::make('type')->label(__('Type'))->badge(),
                Tables\Columns\TextColumn::make('percent_rate')->label(__('%'))->toggleable(),
                Tables\Columns\TextColumn::make('fixed_points')->label(__('Fixed'))->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label(__('Active'))->boolean(),
                Tables\Columns\TextColumn::make('starts_at')->label(__('Starts at'))->dateTime()->since()->toggleable(),
                Tables\Columns\TextColumn::make('ends_at')->label(__('Ends at'))->dateTime()->since()->toggleable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label(__('Edit'))->icon('heroicon-o-pencil-square')->color('info'),
                Tables\Actions\DeleteAction::make()->label(__('Delete')),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label(__('New Rule'))->icon('heroicon-o-plus'),
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
