<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\TestimonialResource\Pages;
use App\Models\Testimonial;
use App\Services\Testimonials\TestimonialGeneratorService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TestimonialResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = Testimonial::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 42;

    public static function getNavigationGroup(): ?string
    {
        return 'المحتوى';
    }

    public static function getNavigationLabel(): string
    {
        return 'آراء العملاء';
    }

    public static function getModelLabel(): string
    {
        return 'شهادة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'آراء العملاء';
    }

    public static function form(Form $form): Form
    {
        $roles = (array) config('testimonials.roles', []);

        return $form
            ->schema([
                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('صاحب الشهادة')
                            ->icon('heroicon-o-user')
                            ->columns(12)
                            ->schema([
                                Forms\Components\TextInput::make('name_ar')
                                    ->label('الاسم')
                                    ->required()
                                    ->maxLength(120)
                                    ->extraAttributes(['dir' => 'rtl'])
                                    ->columnSpan(6),

                                Forms\Components\TextInput::make('city_ar')
                                    ->label('المدينة')
                                    ->maxLength(80)
                                    ->columnSpan(6),

                                Forms\Components\Select::make('role_key')
                                    ->label('الصفة')
                                    ->options($roles)
                                    ->required()
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('role_label_ar', $roles[$state] ?? ''))
                                    ->columnSpan(6),

                                Forms\Components\TextInput::make('role_label_ar')
                                    ->label('وصف الصفة الظاهر')
                                    ->required()
                                    ->columnSpan(6),
                            ]),

                        Forms\Components\Section::make('نص الشهادة')
                            ->icon('heroicon-o-chat-bubble-bottom-center-text')
                            ->schema([
                                Forms\Components\Textarea::make('body_ar')
                                    ->label('النص')
                                    ->required()
                                    ->rows(5)
                                    ->extraAttributes(['dir' => 'rtl'])
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('product_hint')
                                    ->label('المنتج المذكور (اختياري)')
                                    ->maxLength(160)
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->columnSpan(['lg' => 8]),

                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('النشر')
                            ->icon('heroicon-o-rocket-launch')
                            ->schema([
                                Forms\Components\TextInput::make('rating')
                                    ->label('التقييم')
                                    ->numeric()
                                    ->minValue(5)
                                    ->maxValue(5)
                                    ->default(5)
                                    ->disabled()
                                    ->dehydrated()
                                    ->helperText('ثابت 5★ حسب سياسة الموقع'),

                                Forms\Components\Select::make('source')
                                    ->label('المصدر')
                                    ->native(false)
                                    ->options([
                                        'manual' => 'يدوي',
                                        'gemini' => 'Gemini',
                                        'seed' => 'بذرة',
                                    ])
                                    ->default('manual'),

                                Forms\Components\Toggle::make('is_active')
                                    ->label('نشط')
                                    ->default(true)
                                    ->inline(false),

                                Forms\Components\Toggle::make('is_featured')
                                    ->label('مميّز في الواجهة')
                                    ->default(false)
                                    ->inline(false),

                                Forms\Components\TextInput::make('sort_order')
                                    ->label('ترتيب')
                                    ->numeric()
                                    ->default(0),

                                Forms\Components\DateTimePicker::make('published_at')
                                    ->label('تاريخ النشر')
                                    ->default(now())
                                    ->seconds(false),
                            ]),
                    ])
                    ->columnSpan(['lg' => 4]),
            ])
            ->columns(12);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name_ar')->label('الاسم')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('role_label_ar')->label('الصفة'),
                Tables\Columns\TextColumn::make('city_ar')->label('المدينة')->toggleable(),
                Tables\Columns\TextColumn::make('product_hint')->label('المنتج')->limit(24)->toggleable(),
                Tables\Columns\TextColumn::make('rating')->label('★')->alignCenter(),
                Tables\Columns\TextColumn::make('source')->label('المصدر')->badge(),
                Tables\Columns\IconColumn::make('is_featured')->label('مميّز')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('نشط')->boolean(),
                Tables\Columns\TextColumn::make('published_at')->label('النشر')->since()->sortable(),
            ])
            ->defaultSort('published_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('نشط'),
                Tables\Filters\SelectFilter::make('source')->options([
                    'manual' => 'يدوي', 'gemini' => 'Gemini', 'seed' => 'بذرة',
                ]),
            ])
            ->headerActions([
                Tables\Actions\Action::make('generate')
                    ->label('توليد بالذكاء الاصطناعي')
                    ->icon('heroicon-o-sparkles')
                    ->requiresConfirmation()
                    ->modalHeading('توليد شهادات جديدة')
                    ->modalDescription('يُرسل حقائق منتجات MG إلى Gemini ويُنشئ شهادات بأسماء ليبية وتقييم 5 نجوم.')
                    ->action(function () {
                        $rows = app(TestimonialGeneratorService::class)->generateDaily(2);
                        Notification::make()
                            ->success()
                            ->title('تم التوليد')
                            ->body(count($rows).' شهادة جديدة')
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTestimonials::route('/'),
            'create' => Pages\CreateTestimonial::route('/create'),
            'edit' => Pages\EditTestimonial::route('/{record}/edit'),
        ];
    }
}
