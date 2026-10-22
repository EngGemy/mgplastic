<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\FactoryCustomerResource\Pages;
use App\Models\FactoryCustomer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FactoryCustomerResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = FactoryCustomer::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'قوائم الأسعار';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'عميل مصنع';

    protected static ?string $pluralModelLabel = 'عملاء المصنع';

    public static function getNavigationLabel(): string
    {
        return 'عملاء المصنع';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('بيانات العميل')
                ->description('عملاء المصنع المرتبطون بعروض الأسعار.')
                ->icon('heroicon-o-building-office-2')
                ->columns(12)
                ->schema([
                    Forms\Components\TextInput::make('company_name')
                        ->label('اسم الشركة أو العميل')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(6),
                    Forms\Components\TextInput::make('contact_name')
                        ->label('مسؤول التواصل')
                        ->maxLength(255)
                        ->columnSpan(6),
                    Forms\Components\TextInput::make('phone')
                        ->label('الهاتف')
                        ->tel()
                        ->maxLength(40)
                        ->columnSpan(6),
                    Forms\Components\TextInput::make('address')
                        ->label('العنوان')
                        ->maxLength(255)
                        ->columnSpan(6),
                    Forms\Components\Textarea::make('notes')
                        ->label('ملاحظة داخلية')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('company_name')
                    ->label('العميل')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('contact_name')
                    ->label('التواصل')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('phone')
                    ->label('الهاتف')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('price_lists_count')
                    ->counts('priceLists')
                    ->label('عروض')
                    ->badge()
                    ->color('info'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFactoryCustomers::route('/'),
            'create' => Pages\CreateFactoryCustomer::route('/create'),
            'edit' => Pages\EditFactoryCustomer::route('/{record}/edit'),
        ];
    }
}
