<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\BadgeResource\Pages;
use App\Models\Badge;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BadgeResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = Badge::class;

    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?int $navigationSort = 42;

    public static function getNavigationGroup(): ?string
    {
        return 'نظام النقاط';
    }

    public static function getNavigationLabel(): string
    {
        return 'الشارات';
    }

    public static function getModelLabel(): string
    {
        return 'شارة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الشارات';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('key')->label('المفتاح')->required()->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('name_ar')->label('الاسم عربي')->required(),
            Forms\Components\TextInput::make('name_en')->label('الاسم إنجليزي'),
            Forms\Components\Textarea::make('description_ar')->label('الوصف')->columnSpanFull(),
            Forms\Components\TextInput::make('icon')->label('أيقونة')->default('star'),
            Forms\Components\Select::make('tier')->label('الندرة')->options([
                'common' => 'شائعة',
                'rare' => 'نادرة',
                'epic' => 'ملحمية',
                'legendary' => 'أسطورية',
            ])->required(),
            Forms\Components\KeyValue::make('rule')->label('المعيار (rule)')->keyLabel('مفتاح')->valueLabel('قيمة'),
            Forms\Components\Toggle::make('is_active')->label('مفعّلة')->default(true),
            Forms\Components\TextInput::make('sort_order')->label('ترتيب')->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('key')->label('المفتاح')->searchable(),
                Tables\Columns\TextColumn::make('name_ar')->label('الاسم')->searchable(),
                Tables\Columns\BadgeColumn::make('tier')->label('الندرة'),
                Tables\Columns\IconColumn::make('is_active')->label('مفعّلة')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBadges::route('/'),
            'create' => Pages\CreateBadge::route('/create'),
            'edit' => Pages\EditBadge::route('/{record}/edit'),
        ];
    }
}
