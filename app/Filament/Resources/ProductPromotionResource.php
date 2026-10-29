<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\ProductPromotionResource\Pages;
use App\Models\Product;
use App\Models\ProductPromotion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ProductPromotionResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = ProductPromotion::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?int $navigationSort = 45;

    public static function getNavigationGroup(): ?string
    {
        return 'المحتوى';
    }

    public static function getNavigationLabel(): string
    {
        return 'دعاية منتجات MG';
    }

    public static function getModelLabel(): string
    {
        return 'إعلان منتج';
    }

    public static function getPluralModelLabel(): string
    {
        return 'دعاية منتجات MG';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('نص الإعلان')
                            ->description('العنوان والوصف اللي يظهروا للمستخدم في التطبيق.')
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->label('العنوان')
                                    ->placeholder('مثال: مقارنة أنابيب MG مع الأنواع التقليدية')
                                    ->required()
                                    ->maxLength(200)
                                    ->autofocus()
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('subtitle')
                                    ->label('عنوان فرعي')
                                    ->placeholder('سطر قصير تحت العنوان')
                                    ->maxLength(255)
                                    ->columnSpanFull(),

                                Forms\Components\Textarea::make('body')
                                    ->label('الوصف / نص المقارنة')
                                    ->placeholder('اكتب مزايا المنتج، نقاط المقارنة، أو رسالة الدعاية…')
                                    ->rows(5)
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Section::make('الوسائط')
                            ->description('اختر نوع المحتوى ثم ارفع الملفات المناسبة.')
                            ->icon('heroicon-o-photo')
                            ->schema([
                                Forms\Components\ToggleButtons::make('media_type')
                                    ->label('نوع المحتوى')
                                    ->options([
                                        'image' => 'صورة',
                                        'video' => 'فيديو',
                                        'comparison' => 'مقارنة',
                                    ])
                                    ->icons([
                                        'image' => 'heroicon-o-photo',
                                        'video' => 'heroicon-o-play-circle',
                                        'comparison' => 'heroicon-o-arrows-right-left',
                                    ])
                                    ->colors([
                                        'image' => 'primary',
                                        'video' => 'danger',
                                        'comparison' => 'warning',
                                    ])
                                    ->inline()
                                    ->required()
                                    ->live()
                                    ->default('image')
                                    ->columnSpanFull(),

                                Forms\Components\Placeholder::make('media_hint')
                                    ->label('')
                                    ->content(fn (Get $get) => match ($get('media_type')) {
                                        'video' => 'اختر: رفع ملف فيديو على السيرفر أو لصق رابط خارجي (يوتيوب / فيميو / Drive / رابط مباشر) — واحد منهم يكفي.',
                                        'comparison' => 'ارفع صورتين: منتج MG وصورة المنافس أو قبل/بعد.',
                                        default => 'ارفع صورة واضحة أفقية (يفضّل عرض عريض للتطبيق).',
                                    })
                                    ->columnSpanFull(),

                                Forms\Components\FileUpload::make('image_path')
                                    ->label(fn (Get $get) => match ($get('media_type')) {
                                        'comparison' => 'صورة منتج MG',
                                        'video' => 'صورة غلاف الفيديو (اختياري)',
                                        default => 'صورة الإعلان',
                                    })
                                    ->disk('public')
                                    ->directory('product-promotions')
                                    ->image()
                                    ->imageEditor()
                                    ->imageEditorAspectRatios(['16:9', '4:3', '1:1', null])
                                    ->openable()
                                    ->downloadable()
                                    ->maxSize(5120)
                                    ->helperText('حتى 5MB — JPG / PNG / WebP')
                                    ->required(fn (Get $get) => in_array($get('media_type'), ['image', 'comparison'], true))
                                    ->columnSpanFull(),

                                Forms\Components\FileUpload::make('comparison_image_path')
                                    ->label('صورة المقارنة (منافس / قبل)')
                                    ->disk('public')
                                    ->directory('product-promotions')
                                    ->image()
                                    ->imageEditor()
                                    ->imageEditorAspectRatios(['16:9', '4:3', '1:1', null])
                                    ->openable()
                                    ->downloadable()
                                    ->maxSize(5120)
                                    ->visible(fn (Get $get) => $get('media_type') === 'comparison')
                                    ->required(fn (Get $get) => $get('media_type') === 'comparison')
                                    ->columnSpanFull(),

                                Forms\Components\ToggleButtons::make('video_source')
                                    ->label('مصدر الفيديو')
                                    ->options([
                                        'upload' => 'رفع على السيرفر',
                                        'link' => 'رابط خارجي',
                                    ])
                                    ->icons([
                                        'upload' => 'heroicon-o-arrow-up-tray',
                                        'link' => 'heroicon-o-link',
                                    ])
                                    ->colors([
                                        'upload' => 'success',
                                        'link' => 'info',
                                    ])
                                    ->inline()
                                    ->live()
                                    ->dehydrated(false)
                                    ->default('link')
                                    ->afterStateHydrated(function (Forms\Components\ToggleButtons $component, mixed $state, ?Model $record): void {
                                        if ($record && filled($record->video_path)) {
                                            $component->state('upload');

                                            return;
                                        }
                                        $component->state('link');
                                    })
                                    ->afterStateUpdated(function (?string $state, Set $set): void {
                                        if ($state === 'upload') {
                                            $set('video_url', null);
                                        }
                                        if ($state === 'link') {
                                            $set('video_path', null);
                                        }
                                    })
                                    ->visible(fn (Get $get) => $get('media_type') === 'video')
                                    ->columnSpanFull(),

                                Forms\Components\FileUpload::make('video_path')
                                    ->label('ملف الفيديو')
                                    ->disk('public')
                                    ->directory('product-promotions/videos')
                                    ->acceptedFileTypes([
                                        'video/mp4',
                                        'video/webm',
                                        'video/quicktime',
                                        'video/x-m4v',
                                    ])
                                    ->maxSize(102400)
                                    ->openable()
                                    ->downloadable()
                                    ->helperText('MP4 / WebM / MOV — حتى 100MB (تأكد من حد رفع PHP على السيرفر).')
                                    ->visible(fn (Get $get) => $get('media_type') === 'video' && $get('video_source') === 'upload')
                                    ->required(fn (Get $get) => $get('media_type') === 'video' && $get('video_source') === 'upload')
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('video_url')
                                    ->label('رابط الفيديو الخارجي')
                                    ->placeholder('https://www.youtube.com/watch?v=… أو Drive / رابط مباشر')
                                    ->url()
                                    ->prefixIcon('heroicon-o-link')
                                    ->visible(fn (Get $get) => $get('media_type') === 'video' && ($get('video_source') ?? 'link') === 'link')
                                    ->required(fn (Get $get) => $get('media_type') === 'video' && ($get('video_source') ?? 'link') === 'link')
                                    ->helperText('يوتيوب أو فيميو أو Google Drive أو رابط مباشر لملف فيديو')
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Section::make('ربط بمنتج MG')
                            ->description('اختياري — عند الضغط في التطبيق يفتح المنتج أو الرابط الخارجي.')
                            ->icon('heroicon-o-link')
                            ->collapsed()
                            ->schema([
                                Forms\Components\Select::make('product_id')
                                    ->label('منتج من الكتالوج')
                                    ->relationship(
                                        name: 'product',
                                        titleAttribute: 'id',
                                        modifyQueryUsing: fn ($query) => $query->with('translations')->orderBy('id'),
                                    )
                                    ->getOptionLabelFromRecordUsing(
                                        fn (Product $p) => localized_name($p, 'name', "منتج #{$p->id}")
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->nullable()
                                    ->helperText('ابحث باسم المنتج')
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('product_url')
                                    ->label('أو رابط خارجي')
                                    ->placeholder('https://mg-plastic.com/…')
                                    ->url()
                                    ->prefixIcon('heroicon-o-globe-alt')
                                    ->helperText('إن وُجد يُفضَّل على رابط المنتج الداخلي')
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->columnSpan(['lg' => 2]),

                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('النشر')
                            ->icon('heroicon-o-rocket-launch')
                            ->schema([
                                Forms\Components\Toggle::make('is_active')
                                    ->label('ظاهر في التطبيق')
                                    ->helperText('عطّله لإخفاء الإعلان دون حذفه.')
                                    ->default(true)
                                    ->inline(false),

                                Forms\Components\TextInput::make('sort_order')
                                    ->label('ترتيب الظهور')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->helperText('الأقل يظهر أولاً'),
                            ]),

                        Forms\Components\Section::make('تذكير للموبايل')
                            ->icon('heroicon-o-device-phone-mobile')
                            ->schema([
                                Forms\Components\Placeholder::make('api_hint')
                                    ->label('')
                                    ->content('يظهر عبر: GET /api/v1/mobile/product-promotions'),
                            ]),
                    ])
                    ->columnSpan(['lg' => 1]),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')
                    ->label('صورة')
                    ->disk('public')
                    ->square()
                    ->size(64),

                Tables\Columns\TextColumn::make('title')
                    ->label('العنوان')
                    ->searchable()
                    ->weight('bold')
                    ->wrap()
                    ->limit(50),

                Tables\Columns\TextColumn::make('media_type')
                    ->label('النوع')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'video' => 'danger',
                        'comparison' => 'warning',
                        default => 'primary',
                    })
                    ->formatStateUsing(fn (string $state, ProductPromotion $record) => match ($state) {
                        'video' => $record->videoSource() === 'file' ? 'فيديو (ملف)' : 'فيديو (رابط)',
                        'comparison' => 'مقارنة',
                        default => 'صورة',
                    }),

                Tables\Columns\TextColumn::make('product_id')
                    ->label('المنتج')
                    ->formatStateUsing(fn ($state, ProductPromotion $record) => $record->product
                        ? localized_name($record->product, 'name')
                        : '—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('ترتيب')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('ظاهر')
                    ->boolean(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('media_type')
                    ->label('النوع')
                    ->options([
                        'image' => 'صورة',
                        'video' => 'فيديو',
                        'comparison' => 'مقارنة',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('ظاهر في التطبيق'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('لا توجد إعلانات بعد')
            ->emptyStateDescription('أضف أول إعلان صورة أو فيديو أو مقارنة ليظهر في التطبيق.')
            ->emptyStateIcon('heroicon-o-megaphone');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductPromotions::route('/'),
            'create' => Pages\CreateProductPromotion::route('/create'),
            'edit' => Pages\EditProductPromotion::route('/{record}/edit'),
        ];
    }

    /**
     * Keep uploaded file XOR external URL — never both, and require one for video.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeVideoPayload(array $data): array
    {
        if (($data['media_type'] ?? '') !== 'video') {
            $data['video_url'] = null;
            $data['video_path'] = null;

            return $data;
        }

        $hasFile = filled($data['video_path'] ?? null);
        $hasLink = filled($data['video_url'] ?? null);

        if ($hasFile) {
            $data['video_url'] = null;
        } elseif ($hasLink) {
            $data['video_path'] = null;
        } else {
            throw ValidationException::withMessages([
                'video_url' => ['ارفع ملف فيديو على السيرفر أو الصق رابطًا خارجيًا — واحد منهم مطلوب.'],
                'video_path' => ['ارفع ملف فيديو على السيرفر أو الصق رابطًا خارجيًا — واحد منهم مطلوب.'],
            ]);
        }

        return $data;
    }
}
