<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\EventResource\Pages;
use App\Models\City;
use App\Models\Event;
use App\Models\EventCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EventResource extends Resource
{
    use AdminOnlyResource;
    protected static ?string $model = Event::class;
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string { return 'المحتوى'; }
    public static function getNavigationLabel(): string { return 'الفعاليات'; }
    public static function getModelLabel(): string { return 'فعالية'; }
    public static function getPluralModelLabel(): string { return 'الفعاليات'; }

    /** Safe base query */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['category:id,name_en,name_ar', 'city:id,name_en,name_ar']);
    }

    public static function form(Form $form): Form
    {
        $isAr = app()->getLocale() === 'ar';

        return $form->schema([
            Forms\Components\Section::make(__('Event'))
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('category_id')
                        ->label(__('Category'))
                        ->options(fn () => EventCategory::query()
                            ->orderBy('name_en')
                            ->get()
                            ->pluck($isAr ? 'name_ar' : 'name_en', 'id')
                            ->toArray()
                        )
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\Select::make('city_id')
                        ->label(__('City'))
                        ->options(fn () => City::query()
                            ->orderBy('name_en')
                            ->get()
                            ->pluck($isAr ? 'name_ar' : 'name_en', 'id')
                            ->toArray()
                        )
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\FileUpload::make('image')
                        ->label(__('Image'))
                        ->disk('public')
                        ->directory('events')
                        ->image()
                        ->imageEditor()
                        ->openable()
                        ->downloadable()
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('address')
                        ->label(__('Address'))
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Forms\Components\DatePicker::make('event_date')
                        ->label(__('Date'))
                        ->required()
                        ->native(false),

                    Forms\Components\TimePicker::make('event_time')
                        ->label(__('Time'))
                        ->withoutSeconds()
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('latitude')
                        ->label(__('Latitude'))
                        ->numeric()
                        ->step('0.000001'),

                    Forms\Components\TextInput::make('longitude')
                        ->label(__('Longitude'))
                        ->numeric()
                        ->step('0.000001'),
                ]),

            Forms\Components\Section::make(__('Translations'))
                ->columns(2)
                ->schema([
                    Forms\Components\Tabs::make(__('translations'))
                        ->columnSpanFull()
                        ->tabs([
                            Forms\Components\Tabs\Tab::make(__('English'))
                                ->schema([
                                    Forms\Components\TextInput::make('title_en')
                                        ->label(__('Title (EN)'))
                                        ->required()
                                        ->maxLength(255)
                                        ->afterStateHydrated(function ($set, ?Event $record) {
                                            $set('title_en', optional($record?->translate('en'))->title);
                                        }),

                                    Forms\Components\RichEditor::make('description_en')
                                        ->label(__('Description (EN)'))
                                        ->afterStateHydrated(function ($set, ?Event $record) {
                                            $set('description_en', optional($record?->translate('en'))->description);
                                        })
                                        ->columnSpanFull(),
                                ]),

                            Forms\Components\Tabs\Tab::make(__('Arabic'))
                                ->schema([
                                    Forms\Components\TextInput::make('title_ar')
                                        ->label(__('Title (AR)'))
                                        ->required()
                                        ->maxLength(255)
                                        ->extraAttributes(['dir' => 'rtl'])
                                        ->afterStateHydrated(function ($set, ?Event $record) {
                                            $set('title_ar', optional($record?->translate('ar'))->title);
                                        }),

                                    Forms\Components\RichEditor::make('description_ar')
                                        ->label(__('Description (AR)'))
                                        ->extraAttributes(['dir' => 'rtl'])
                                        ->afterStateHydrated(function ($set, ?Event $record) {
                                            $set('description_ar', optional($record?->translate('ar'))->description);
                                        })
                                        ->columnSpanFull(),
                                ]),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->disk('public')
                    ->label(__('Image'))
                    ->square()
                    ->size(56),

                Tables\Columns\TextColumn::make('title_en')
                    ->label(__('Title'))
                    ->state(function (Event $record) {
                        $locale = app()->getLocale();
                        return optional($record->translate($locale) ?: $record->translate('en'))->title;
                    })
                    ->wrap()
                    ->limit(60)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        // Simple search on EN/AR title columns if you keep column-per-lang copies
                        return $query
                            ->orWhere('title_en', 'like', "%{$search}%")
                            ->orWhere('title_ar', 'like', "%{$search}%");
                    }),

                Tables\Columns\TextColumn::make('category.name_en')
                    ->label(__('Category'))
                    ->formatStateUsing(fn ($state, Event $record) =>
                    app()->getLocale() === 'ar'
                        ? ($record->category->name_ar ?? $state)
                        : ($record->category->name_en ?? $state)
                    )
                    ->sortable(),

                Tables\Columns\TextColumn::make('city.name_en')
                    ->label(__('City'))
                    ->formatStateUsing(fn ($state, Event $record) =>
                    app()->getLocale() === 'ar'
                        ? ($record->city->name_ar ?? $state)
                        : ($record->city->name_en ?? $state)
                    )
                    ->sortable(),

                Tables\Columns\TextColumn::make('address')
                    ->label(__('Address'))
                    ->limit(50)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('event_date')
                    ->label(__('Date'))
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('event_time')
                    ->label(__('Time'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Created at'))
                    ->dateTime()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->label(__('Category'))
                    ->options(EventCategory::query()
                        ->orderBy('name_en')
                        ->pluck(app()->getLocale() === 'ar' ? 'name_ar' : 'name_en', 'id')),
                Tables\Filters\SelectFilter::make('city_id')
                    ->label(__('City'))
                    ->options(City::query()
                        ->orderBy('name_en')
                        ->pluck(app()->getLocale() === 'ar' ? 'name_ar' : 'name_en', 'id')),
                Tables\Filters\TrashedFilter::make()->label(__('Trashed'))->hidden(), // if using SoftDeletes
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ])
            ->defaultSort('event_date', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListEvents::route('/'),
            'create' => Pages\CreateEvent::route('/create'),
            'edit'   => Pages\EditEvent::route('/{record}/edit'),
        ];
    }
}
