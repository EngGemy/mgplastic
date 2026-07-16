<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\EventResource\Pages;
use App\Models\City;
use App\Models\Event;
use App\Models\EventCategory;
use Filament\Forms;
use Filament\Forms\Components\ViewField;
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

    public static function getNavigationGroup(): ?string
    {
        return 'المحتوى';
    }

    public static function getNavigationLabel(): string
    {
        return 'الفعاليات';
    }

    public static function getModelLabel(): string
    {
        return 'فعالية';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الفعاليات';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['category:id,name_en,name_ar', 'city:id,name_en,name_ar']);
    }

    public static function form(Form $form): Form
    {
        $isAr = app()->getLocale() === 'ar';

        return $form->schema([
            Forms\Components\Section::make($isAr ? 'تفاصيل الفعالية' : 'Event details')
                ->description($isAr ? 'التصنيف، المدينة، الصورة والعنوان النصي' : 'Category, city, image and address')
                ->icon('heroicon-o-sparkles')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('category_id')
                        ->label($isAr ? 'التصنيف' : 'Category')
                        ->options(fn () => EventCategory::query()
                            ->orderBy('name_en')
                            ->get()
                            ->pluck($isAr ? 'name_ar' : 'name_en', 'id')
                            ->toArray())
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\Select::make('city_id')
                        ->label($isAr ? 'المدينة' : 'City')
                        ->options(fn () => City::query()
                            ->orderBy('name_en')
                            ->get()
                            ->pluck($isAr ? 'name_ar' : 'name_en', 'id')
                            ->toArray())
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\FileUpload::make('image')
                        ->label($isAr ? 'صورة الغلاف' : 'Cover image')
                        ->disk('public')
                        ->directory('events')
                        ->image()
                        ->imageEditor()
                        ->imageCropAspectRatio('16:9')
                        ->openable()
                        ->downloadable()
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('address')
                        ->label($isAr ? 'العنوان النصي' : 'Address')
                        ->placeholder($isAr ? 'مثال: قاعة المؤتمرات — طرابلس' : 'e.g. Conference hall — Tripoli')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make($isAr ? 'الموعد' : 'Schedule')
                ->icon('heroicon-o-clock')
                ->columns(2)
                ->schema([
                    Forms\Components\DatePicker::make('event_date')
                        ->label($isAr ? 'التاريخ' : 'Date')
                        ->required()
                        ->native(false)
                        ->minDate(now()->subYears(1)),

                    Forms\Components\TimePicker::make('event_time')
                        ->label($isAr ? 'الوقت' : 'Time')
                        ->withoutSeconds()
                        ->required()
                        ->native(false),
                ]),

            Forms\Components\Section::make($isAr ? 'الموقع على الخريطة' : 'Map location')
                ->description($isAr
                    ? 'استخدم OpenStreetMap لتحديد المكان بدقة. خط العرض بين −90 و 90، وخط الطول بين −180 و 180.'
                    : 'Use OpenStreetMap to pin the venue. Latitude −90…90, longitude −180…180.')
                ->icon('heroicon-o-map-pin')
                ->schema([
                    ViewField::make('_event_location_map')
                        ->view('filament.forms.event-osm-map-picker')
                        ->dehydrated(false)
                        ->columnSpanFull(),

                    Forms\Components\Hidden::make('latitude')
                        ->default(32.8872)
                        ->dehydrated()
                        ->required()
                        ->rules(['numeric', 'between:-90,90']),

                    Forms\Components\Hidden::make('longitude')
                        ->default(13.1913)
                        ->dehydrated()
                        ->required()
                        ->rules(['numeric', 'between:-180,180']),
                ]),

            Forms\Components\Section::make($isAr ? 'الترجمات' : 'Translations')
                ->icon('heroicon-o-language')
                ->schema([
                    Forms\Components\Tabs::make('translations')
                        ->columnSpanFull()
                        ->tabs([
                            Forms\Components\Tabs\Tab::make($isAr ? 'العربية' : 'Arabic')
                                ->schema([
                                    Forms\Components\TextInput::make('title_ar')
                                        ->label($isAr ? 'العنوان (عربي)' : 'Title (AR)')
                                        ->required()
                                        ->maxLength(255)
                                        ->extraAttributes(['dir' => 'rtl'])
                                        ->afterStateHydrated(function ($set, ?Event $record) {
                                            $set('title_ar', optional($record?->translate('ar'))->title);
                                        }),

                                    Forms\Components\RichEditor::make('description_ar')
                                        ->label($isAr ? 'الوصف (عربي)' : 'Description (AR)')
                                        ->extraAttributes(['dir' => 'rtl'])
                                        ->afterStateHydrated(function ($set, ?Event $record) {
                                            $set('description_ar', optional($record?->translate('ar'))->description);
                                        })
                                        ->columnSpanFull(),
                                ]),

                            Forms\Components\Tabs\Tab::make($isAr ? 'الإنجليزية' : 'English')
                                ->schema([
                                    Forms\Components\TextInput::make('title_en')
                                        ->label($isAr ? 'العنوان (إنجليزي)' : 'Title (EN)')
                                        ->required()
                                        ->maxLength(255)
                                        ->afterStateHydrated(function ($set, ?Event $record) {
                                            $set('title_en', optional($record?->translate('en'))->title);
                                        }),

                                    Forms\Components\RichEditor::make('description_en')
                                        ->label($isAr ? 'الوصف (إنجليزي)' : 'Description (EN)')
                                        ->afterStateHydrated(function ($set, ?Event $record) {
                                            $set('description_en', optional($record?->translate('en'))->description);
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
                        return $query
                            ->orWhere('title_en', 'like', "%{$search}%")
                            ->orWhere('title_ar', 'like', "%{$search}%");
                    }),

                Tables\Columns\TextColumn::make('category.name_en')
                    ->label(__('Category'))
                    ->formatStateUsing(fn ($state, Event $record) => app()->getLocale() === 'ar'
                        ? ($record->category->name_ar ?? $state)
                        : ($record->category->name_en ?? $state)
                    )
                    ->sortable(),

                Tables\Columns\TextColumn::make('city.name_en')
                    ->label(__('City'))
                    ->formatStateUsing(fn ($state, Event $record) => app()->getLocale() === 'ar'
                        ? ($record->city->name_ar ?? $state)
                        : ($record->city->name_en ?? $state)
                    )
                    ->sortable(),

                Tables\Columns\TextColumn::make('address')
                    ->label(__('Address'))
                    ->limit(50)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('latitude')
                    ->label(app()->getLocale() === 'ar' ? 'الموقع' : 'Coords')
                    ->state(fn (Event $record) => ($record->latitude !== null && $record->longitude !== null)
                        ? number_format((float) $record->latitude, 5).', '.number_format((float) $record->longitude, 5)
                        : '—')
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
            'index' => Pages\ListEvents::route('/'),
            'create' => Pages\CreateEvent::route('/create'),
            'edit' => Pages\EditEvent::route('/{record}/edit'),
        ];
    }
}
