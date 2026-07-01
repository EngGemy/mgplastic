<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\PrivacyResource\Pages;
use App\Models\Privacy;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PrivacyResource extends Resource
{
    use AdminOnlyResource;
    protected static ?string $model = Privacy::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?int $navigationSort = 50;

    public static function getNavigationGroup(): ?string
    {
        return 'الإعدادات';
    }

    public static function getNavigationLabel(): string
    {
        return 'السياسات';
    }

    public static function getModelLabel(): string
    {
        return 'سياسة الخصوصية';
    }

    public static function getPluralModelLabel(): string
    {
        return 'السياسات';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
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
                    ->state(fn (Privacy $record) => optional($record->translate('en'))->title)
                    ->toggleable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('title_ar')
                    ->label(__('Title (AR)'))
                    ->state(fn (Privacy $record) => optional($record->translate('ar'))->title)
                    ->toggleable()
                    ->wrap(),

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
            'index'  => Pages\ListPrivacies::route('/'),
            'create' => Pages\CreatePrivacy::route('/create'),
            'edit'   => Pages\EditPrivacy::route('/{record}/edit'),
        ];
    }
}
