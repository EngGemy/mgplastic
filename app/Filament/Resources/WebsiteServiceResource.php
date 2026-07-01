<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\WebsiteServiceResource\Pages;
use App\Models\WebsiteService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WebsiteServiceResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = WebsiteService::class;
    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';
    protected static ?int $navigationSort = 42;

    public static function getNavigationGroup(): ?string { return 'المحتوى'; }
    public static function getNavigationLabel(): string { return 'خدمات الموقع'; }
    public static function getModelLabel(): string { return 'خدمة'; }
    public static function getPluralModelLabel(): string { return 'خدمات الموقع'; }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('icon')->label('أيقونة Tabler')->default('ti-package')->required(),
            Forms\Components\TextInput::make('title_ar')->label('العنوان (عربي)')->required()->maxLength(255),
            Forms\Components\TextInput::make('subtitle_en')->label('العنوان الفرعي (إنجليزي)')->maxLength(255),
            Forms\Components\Textarea::make('description_ar')->label('الوصف')->rows(4)->columnSpanFull(),
            Forms\Components\TextInput::make('sort_order')->label('الترتيب')->numeric()->default(0),
            Forms\Components\Toggle::make('is_active')->label('نشط')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title_ar')->label('الخدمة')->searchable(),
                Tables\Columns\TextColumn::make('subtitle_en')->label('EN'),
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
            'index' => Pages\ListWebsiteServices::route('/'),
            'create' => Pages\CreateWebsiteService::route('/create'),
            'edit' => Pages\EditWebsiteService::route('/{record}/edit'),
        ];
    }
}
