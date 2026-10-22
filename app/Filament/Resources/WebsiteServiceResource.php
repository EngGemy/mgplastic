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

    public static function getNavigationGroup(): ?string
    {
        return 'المحتوى';
    }

    public static function getNavigationLabel(): string
    {
        return 'خدمات الموقع';
    }

    public static function getModelLabel(): string
    {
        return 'خدمة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'خدمات الموقع';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('الخدمة')
                ->description('بطاقة خدمة في قسم «خدماتنا» بالموقع.')
                ->icon('heroicon-o-wrench-screwdriver')
                ->columns(12)
                ->schema([
                    Forms\Components\TextInput::make('icon')
                        ->label('أيقونة Tabler')
                        ->default('ti-package')
                        ->required()
                        ->helperText('مثال: ti-package أو ti-truck')
                        ->columnSpan(4),
                    Forms\Components\TextInput::make('sort_order')
                        ->label('الترتيب')
                        ->numeric()
                        ->default(0)
                        ->columnSpan(4),
                    Forms\Components\Toggle::make('is_active')
                        ->label('نشط')
                        ->default(true)
                        ->inline(false)
                        ->columnSpan(4),
                    Forms\Components\TextInput::make('title_ar')
                        ->label('العنوان بالعربية')
                        ->required()
                        ->maxLength(255)
                        ->extraAttributes(['dir' => 'rtl'])
                        ->columnSpan(6),
                    Forms\Components\TextInput::make('subtitle_en')
                        ->label('عنوان فرعي (إنجليزي)')
                        ->maxLength(255)
                        ->columnSpan(6),
                    Forms\Components\Textarea::make('description_ar')
                        ->label('الوصف')
                        ->rows(4)
                        ->extraAttributes(['dir' => 'rtl'])
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title_ar')
                    ->label('الخدمة')
                    ->searchable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('subtitle_en')
                    ->label('EN')
                    ->toggleable(),
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
            'index' => Pages\ListWebsiteServices::route('/'),
            'create' => Pages\CreateWebsiteService::route('/create'),
            'edit' => Pages\EditWebsiteService::route('/{record}/edit'),
        ];
    }
}
