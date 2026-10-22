<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\SliderResource\Pages;
use App\Models\Slider;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SliderResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = Slider::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?int $navigationSort = 50;

    public static function getNavigationGroup(): ?string
    {
        return 'المحتوى';
    }

    public static function getNavigationLabel(): string
    {
        return 'الشرائح (Sliders)';
    }

    public static function getModelLabel(): string
    {
        return 'شريحة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الشرائح';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('المحتوى الظاهر')
                            ->description('النصوص فوق صورة السلايدر في التطبيق (اختيارية).')
                            ->icon('heroicon-o-document-text')
                            ->columns(12)
                            ->schema([
                                Forms\Components\Select::make('type')
                                    ->label('مكان الظهور')
                                    ->options([
                                        'home' => 'الرئيسية',
                                        'store' => 'صفحة المتجر',
                                    ])
                                    ->native(false)
                                    ->required()
                                    ->default('home')
                                    ->columnSpan(4),

                                Forms\Components\TextInput::make('tag')
                                    ->label('وسم صغير')
                                    ->placeholder('عرض محدود')
                                    ->maxLength(255)
                                    ->columnSpan(4),

                                Forms\Components\TextInput::make('sort_order')
                                    ->label('الترتيب')
                                    ->numeric()
                                    ->default(0)
                                    ->columnSpan(4),

                                Forms\Components\TextInput::make('title')
                                    ->label('العنوان')
                                    ->maxLength(255)
                                    ->live(debounce: 400)
                                    ->columnSpanFull(),

                                Forms\Components\Textarea::make('description')
                                    ->label('الوصف / العنوان الفرعي')
                                    ->rows(3)
                                    ->helperText('يُرسل للتطبيق كـ subtitle')
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Section::make('أزرار الدعوة')
                            ->icon('heroicon-o-cursor-arrow-rays')
                            ->columns(2)
                            ->collapsed()
                            ->schema([
                                Forms\Components\TextInput::make('cta_primary_text')->label('زر أساسي — نص'),
                                Forms\Components\TextInput::make('cta_primary_url')->label('زر أساسي — رابط')->url(),
                                Forms\Components\TextInput::make('cta_secondary_text')->label('زر ثانوي — نص'),
                                Forms\Components\TextInput::make('cta_secondary_url')->label('زر ثانوي — رابط')->url(),
                            ]),

                        Forms\Components\Section::make('الوسائط')
                            ->icon('heroicon-o-photo')
                            ->schema([
                                Forms\Components\FileUpload::make('image')
                                    ->label('صورة الشريحة')
                                    ->disk('public')
                                    ->directory('sliders')
                                    ->image()
                                    ->imageEditor()
                                    ->imageEditorAspectRatios(['16:9', '21:9', null])
                                    ->imagePreviewHeight('200')
                                    ->openable()
                                    ->downloadable()
                                    ->helperText('يفضّل أفقية عريضة. بدون صورة يُستخدم تدرج CSS إن وُجد.')
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('background_style')
                                    ->label('خلفية CSS (بدون صورة)')
                                    ->placeholder('background:linear-gradient(135deg,#0d2d6e,#1a56db)')
                                    ->helperText('يُستخدم فقط إذا لم تُرفع صورة.')
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->columnSpan(['lg' => 8]),

                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('النشر')
                            ->icon('heroicon-o-rocket-launch')
                            ->schema([
                                Forms\Components\Toggle::make('is_active')
                                    ->label('نشط في التطبيق')
                                    ->default(true)
                                    ->inline(false),

                                Forms\Components\Placeholder::make('api_hint')
                                    ->label('للتطبيق')
                                    ->content('GET /api/v1/sliders/home  ·  GET /api/v1/sliders/store'),
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
                Tables\Columns\ImageColumn::make('image')
                    ->label('صورة')
                    ->disk('public')
                    ->square()
                    ->size(72),
                Tables\Columns\TextColumn::make('type')
                    ->label('المكان')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'store' ? 'المتجر' : 'الرئيسية')
                    ->color(fn (string $state) => $state === 'store' ? 'warning' : 'success')
                    ->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->label('العنوان')
                    ->limit(40)
                    ->searchable()
                    ->placeholder('— بدون عنوان'),
                Tables\Columns\TextColumn::make('sort_order')->label('ترتيب')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label('نشط')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                SelectFilter::make('type')->label('المكان')->options([
                    'home' => 'الرئيسية',
                    'store' => 'المتجر',
                ]),
                Tables\Filters\TernaryFilter::make('is_active')->label('نشط'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
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
            'index' => Pages\ListSliders::route('/'),
            'create' => Pages\CreateSlider::route('/create'),
            'edit' => Pages\EditSlider::route('/{record}/edit'),
        ];
    }
}
