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

    public static function getNavigationGroup(): ?string { return 'المحتوى'; }
    public static function getNavigationLabel(): string { return 'إحصائيات الموقع'; }
    public static function getModelLabel(): string { return 'إحصائية'; }
    public static function getPluralModelLabel(): string { return 'إحصائيات الموقع'; }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('value')->label('القيمة')->numeric()->required(),
            Forms\Components\TextInput::make('suffix')->label('لاحقة (مثل %)')->maxLength(10),
            Forms\Components\TextInput::make('label_ar')->label('العنوان (عربي)')->required()->maxLength(255),
            Forms\Components\TextInput::make('label_en')->label('العنوان (إنجليزي)')->maxLength(255),
            Forms\Components\TextInput::make('sort_order')->label('الترتيب')->numeric()->default(0),
            Forms\Components\Toggle::make('is_active')->label('نشط')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('value')->label('القيمة')->sortable(),
                Tables\Columns\TextColumn::make('suffix')->label('لاحقة'),
                Tables\Columns\TextColumn::make('label_ar')->label('العنوان')->searchable(),
                Tables\Columns\TextColumn::make('sort_order')->label('الترتيب')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label('نشط')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
