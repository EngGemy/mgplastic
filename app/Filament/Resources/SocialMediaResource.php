<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\SocialMediaResource\Pages;
use App\Models\SocialMedia;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SocialMediaResource extends Resource
{
    use AdminOnlyResource;
    protected static ?string $model = SocialMedia::class;
    protected static ?string $navigationIcon = 'heroicon-o-share';
    protected static ?int $navigationSort = 60;

    public static function getNavigationGroup(): ?string { return 'الإعدادات'; }
    public static function getNavigationLabel(): string { return 'وسائل التواصل'; }
    public static function getModelLabel(): string { return 'وسائل التواصل'; }
    public static function getPluralModelLabel(): string { return 'وسائل التواصل'; }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Link'))
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('platform')
                        ->label(__('Platform'))
                        ->options([
                            'facebook'  => __('Facebook'),
                            'instagram' => __('Instagram'),
                            'x'         => __('X (Twitter)'),
                            'tiktok'    => __('TikTok'),
                            'youtube'   => __('YouTube'),
                            'linkedin'  => __('LinkedIn'),
                            'whatsapp'  => __('WhatsApp'),
                            'website'   => __('Website'),
                            'other'     => __('Other'),
                        ])
                        ->native(false)
                        ->searchable()
                        ->required(),

                    Forms\Components\TextInput::make('url')
                        ->label(__('URL'))
                        ->url()
                        ->required()
                        ->maxLength(2048)
                        ->columnSpanFull(),

                    Forms\Components\Tabs::make(__('Translations'))
                        ->columnSpanFull()
                        ->tabs([
                            Forms\Components\Tabs\Tab::make(__('English'))
                                ->schema([
                                    Forms\Components\TextInput::make('name_en')
                                        ->label(__('Name (EN)'))
                                        ->required()
                                        ->maxLength(255),
                                ]),
                            Forms\Components\Tabs\Tab::make(__('Arabic'))
                                ->schema([
                                    Forms\Components\TextInput::make('name_ar')
                                        ->label(__('Name (AR)'))
                                        ->required()
                                        ->maxLength(255)
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
                Tables\Columns\TextColumn::make('platform')
                    ->label(__('Platform'))
                    ->badge()
                    ->formatStateUsing(function ($state) {
                        $map = [
                            'facebook'  => __('Facebook'),
                            'instagram' => __('Instagram'),
                            'x'         => __('X (Twitter)'),
                            'tiktok'    => __('TikTok'),
                            'youtube'   => __('YouTube'),
                            'linkedin'  => __('LinkedIn'),
                            'whatsapp'  => __('WhatsApp'),
                            'website'   => __('Website'),
                            'other'     => __('Other'),
                        ];
                        return $map[$state] ?? ucfirst((string) $state);
                    })
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('name_en')
                    ->label(__('Name (EN)'))
                    ->state(fn ($record) => optional($record?->translate('en'))->name)
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('name_ar')
                    ->label(__('Name (AR)'))
                    ->state(fn ($record) => optional($record?->translate('ar'))->name)
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('url')
                    ->label(__('URL'))
                    ->url(fn ($state) => $state, true)
                    ->limit(50)
                    ->searchable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Updated at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
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
            'index'  => Pages\ListSocialMedia::route('/'),
            'create' => Pages\CreateSocialMedia::route('/create'),
            'edit'   => Pages\EditSocialMedia::route('/{record}/edit'),
        ];
    }
}
