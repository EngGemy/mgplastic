<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\TermsConditionResource\Pages;
use App\Models\TermsCondition;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TermsConditionResource extends Resource
{
    use AdminOnlyResource;
    protected static ?string $model = TermsCondition::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string { return 'الإعدادات'; }
    public static function getNavigationLabel(): string { return 'الشروط والأحكام'; }
    public static function getModelLabel(): string { return 'الشروط والأحكام'; }
    public static function getPluralModelLabel(): string { return 'الشروط والأحكام'; }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Terms & Conditions'))
                ->columns(1)
                ->schema([
                    Forms\Components\TextInput::make('slug')
                        ->label(__('Slug'))
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(190),

                    Forms\Components\Tabs::make(__('Translations'))
                        ->columnSpanFull()
                        ->tabs([
                            Forms\Components\Tabs\Tab::make(__('English'))
                                ->schema([
                                    Forms\Components\TextInput::make('title_en')
                                        ->label(__('Title (EN)'))
                                        ->required()
                                        ->maxLength(255),

                                    Forms\Components\RichEditor::make('content_en')
                                        ->label(__('Content (EN)'))
                                        ->columnSpanFull(),
                                ]),

                            Forms\Components\Tabs\Tab::make(__('Arabic'))
                                ->schema([
                                    Forms\Components\TextInput::make('title_ar')
                                        ->label(__('Title (AR)'))
                                        ->required()
                                        ->maxLength(255)
                                        ->extraAttributes(['dir' => 'rtl']),

                                    Forms\Components\RichEditor::make('content_ar')
                                        ->label(__('Content (AR)'))
                                        ->columnSpanFull()
                                        ->extraAttributes(['dir' => 'rtl']),
                                ]),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('slug')
                    ->label(__('Slug'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('title_en')
                    ->label(__('Title (EN)'))
                    ->state(fn ($record) => optional($record?->translate('en'))->title)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('title_ar')
                    ->label(__('Title (AR)'))
                    ->state(fn ($record) => optional($record?->translate('ar'))->title)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Updated at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            'index'  => Pages\ListTermsConditions::route('/'),
            'create' => Pages\CreateTermsCondition::route('/create'),
            'edit'   => Pages\EditTermsCondition::route('/{record}/edit'),
        ];
    }
}
