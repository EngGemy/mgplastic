<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\WebsiteStatResource\Pages;
use App\Models\WebsiteStat;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WebsiteStatResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = WebsiteStat::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 41;

    public static function getNavigationGroup(): ?string
    {
        return 'المحتوى';
    }

    public static function getNavigationLabel(): string
    {
        return 'إحصائيات الموقع';
    }

    public static function getModelLabel(): string
    {
        return 'إحصائية';
    }

    public static function getPluralModelLabel(): string
    {
        return 'إحصائيات الموقع';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('الإحصائية')
                ->description('أرقام تُعرض في الصفحة الرئيسية للموقع.')
                ->icon('heroicon-o-chart-bar')
                ->columns(12)
                ->schema([
                    Forms\Components\TextInput::make('value')
                        ->label('القيمة')
                        ->numeric()
                        ->required()
                        ->columnSpan(4),
                    Forms\Components\TextInput::make('suffix')
                        ->label('لاحقة')
                        ->placeholder('%')
                        ->maxLength(10)
                        ->columnSpan(2),
                    Forms\Components\TextInput::make('sort_order')
                        ->label('الترتيب')
                        ->numeric()
                        ->default(0)
                        ->columnSpan(3),
                    Forms\Components\Toggle::make('is_active')
                        ->label('نشط')
                        ->default(true)
                        ->inline(false)
                        ->columnSpan(3),
                    Forms\Components\TextInput::make('label_ar')
                        ->label('العنوان بالعربية')
                        ->required()
                        ->maxLength(255)
                        ->extraAttributes(['dir' => 'rtl'])
                        ->columnSpan(6),
                    Forms\Components\TextInput::make('label_en')
                        ->label('العنوان بالإنجليزية')
                        ->maxLength(255)
                        ->columnSpan(6),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('value')
                    ->label('القيمة')
                    ->sortable()
                    ->weight('bold')
                    ->formatStateUsing(fn ($state, WebsiteStat $r) => $state.($r->suffix ?? '')),
                Tables\Columns\TextColumn::make('label_ar')
                    ->label('العنوان')
                    ->searchable(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('الترتيب')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('حذف المحدد'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWebsiteStats::route('/'),
            'create' => Pages\CreateWebsiteStat::route('/create'),
            'edit' => Pages\EditWebsiteStat::route('/{record}/edit'),
        ];
    }
}
