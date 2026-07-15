<?php

namespace App\Filament\Concerns;

use App\Models\City;
use App\Models\Country;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Get;
use Filament\Forms\Set;

trait HasStoreLocationForm
{
    protected static function storeIdentityFields(
        bool $withPassword = true,
        string $nameLabel = 'الاسم',
        bool $compactPhoto = false,
    ): array {
        $fields = [];

        if ($compactPhoto) {
            $fields[] = Forms\Components\FileUpload::make('profile_photo')
                ->label('صورة (اختياري)')
                ->disk('public')
                ->directory('profile_photos')
                ->image()
                ->avatar()
                ->imageEditor()
                ->columnSpan(1);
        } else {
            $fields[] = Forms\Components\FileUpload::make('profile_photo')
                ->label('صورة المتجر / الشعار')
                ->disk('public')
                ->directory('profile_photos')
                ->image()
                ->imageEditor()
                ->columnSpanFull();
        }

        $fields[] = Forms\Components\TextInput::make('name')
            ->label($nameLabel)
            ->placeholder('مثال: متجر الأندلس')
            ->required()
            ->maxLength(255)
            ->columnSpan($compactPhoto ? 2 : 2);

        $fields[] = Forms\Components\TextInput::make('phone')
            ->label('رقم الهاتف')
            ->placeholder('09XXXXXXXX')
            ->tel()
            ->required()
            ->maxLength(50)
            ->columnSpan(1);

        $fields[] = Forms\Components\TextInput::make('email')
            ->label('البريد الإلكتروني')
            ->placeholder('اختياري')
            ->email()
            ->maxLength(255)
            ->columnSpan(1);

        if ($withPassword) {
            $fields[] = Forms\Components\TextInput::make('password')
                ->label('كلمة المرور')
                ->password()
                ->revealable()
                ->placeholder('6 أحرف على الأقل')
                ->dehydrated(fn ($state) => filled($state))
                ->required(fn ($record) => $record === null)
                ->minLength(6)
                ->maxLength(255)
                ->columnSpan($compactPhoto ? 3 : 1);
        }

        return $fields;
    }

    protected static function defaultLibyaCountryId(): ?int
    {
        return Country::query()
            ->where('name_ar', 'ليبيا')
            ->orWhere('name_en', 'Libya')
            ->value('id');
    }

    protected static function storeLocationFields(bool $hideCoordinates = false): array
    {
        $fields = [
            Forms\Components\Select::make('country_id')
                ->label('الدولة')
                ->options(fn () => Country::query()
                    ->orderBy('name_ar')
                    ->get()
                    ->pluck('name_ar', 'id')
                    ->toArray())
                ->default(fn () => self::defaultLibyaCountryId())
                ->searchable()
                ->preload()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('city_id', null))
                ->required()
                ->columnSpan(1),

            Forms\Components\Select::make('city_id')
                ->label('المدينة')
                ->options(fn (Get $get) => City::query()
                    ->when($get('country_id'), fn ($q, $cid) => $q->where('country_id', $cid))
                    ->orderBy('name_ar')
                    ->get()
                    ->pluck('name_ar', 'id')
                    ->toArray())
                ->searchable()
                ->preload()
                ->required()
                ->columnSpan(1),

            Forms\Components\Textarea::make('address')
                ->label('العنوان التفصيلي')
                ->placeholder('الحي — الشارع — معلم قريب')
                ->rows(2)
                ->columnSpanFull(),

            ViewField::make('_location_map')
                ->view('filament.forms.osm-map-picker')
                ->dehydrated(false)
                ->columnSpanFull(),
        ];

        if (! $hideCoordinates) {
            array_splice($fields, 3, 0, [
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('latitude')
                        ->label('خط العرض')
                        ->numeric()
                        ->live(debounce: 400)
                        ->default(32.8872),
                    Forms\Components\TextInput::make('longitude')
                        ->label('خط الطول')
                        ->numeric()
                        ->live(debounce: 400)
                        ->default(13.1913),
                ]),
            ]);
        } else {
            $fields[] = Forms\Components\Section::make('إحداثيات GPS (اختياري — تُحدَّث من الخريطة)')
                ->schema([
                    Forms\Components\TextInput::make('latitude')
                        ->label('خط العرض')
                        ->numeric()
                        ->live(debounce: 400)
                        ->default(32.8872),
                    Forms\Components\TextInput::make('longitude')
                        ->label('خط الطول')
                        ->numeric()
                        ->live(debounce: 400)
                        ->default(13.1913),
                ])
                ->columns(2)
                ->columnSpanFull()
                ->collapsed()
                ->compact();
        }

        return $fields;
    }

    protected static function storeDetailsFields(): array
    {
        return [
            Forms\Components\Textarea::make('store_description')
                ->label('ملاحظات')
                ->placeholder('أي تفاصيل إضافية عن المتجر...')
                ->rows(2)
                ->columnSpanFull(),
        ];
    }

    protected static function storeCatalogFields(): array
    {
        return [
            Forms\Components\TextInput::make('website')
                ->label('الموقع الإلكتروني')
                ->placeholder('https://example.com')
                ->url()
                ->maxLength(500)
                ->prefixIcon('heroicon-o-globe-alt')
                ->columnSpanFull(),

            Forms\Components\Textarea::make('short_description')
                ->label('وصف مختصر')
                ->placeholder('سطر أو سطرين يظهران في قائمة المتاجر...')
                ->rows(2)
                ->maxLength(500)
                ->columnSpanFull(),

            Forms\Components\Textarea::make('long_description')
                ->label('نبذة تفصيلية')
                ->placeholder('عن المتجر، الخدمات، منتجاتكم...')
                ->rows(5)
                ->columnSpanFull(),

            Forms\Components\Textarea::make('store_description')
                ->label('ملاحظات داخلية (اختياري)')
                ->rows(2)
                ->columnSpanFull(),
        ];
    }

    protected static function storeStatusFields(bool $collapsed = false): array
    {
        $fields = [
            Forms\Components\Toggle::make('is_approved')
                ->label('معتمد')
                ->default(true)
                ->inline(false),

            Forms\Components\Toggle::make('is_active')
                ->label('نشط')
                ->default(true)
                ->inline(false),

            Forms\Components\Toggle::make('is_phone_verified')
                ->label('الهاتف موثّق')
                ->default(true)
                ->inline(false),

            Forms\Components\Toggle::make('show_social_links')
                ->label('إظهار روابط التواصل الاجتماعي')
                ->helperText('عند الإيقاف تُخفى حسابات السوشيال ميديا عن التطبيق والموقع.')
                ->default(true)
                ->inline(false),
        ];

        if ($collapsed) {
            return [
                Forms\Components\Section::make('إعدادات الحساب')
                    ->schema($fields)
                    ->columns(3)
                    ->collapsed()
                    ->compact(),
            ];
        }

        return $fields;
    }

    protected static function retailAffiliationFields(): array
    {
        return [
            Forms\Components\Radio::make('affiliation_type')
                ->label('نوع الموزع')
                ->options([
                    'linked' => 'تابع لمتجر جملة',
                    'independent' => 'موزع قطاعي منفرد',
                ])
                ->default('linked')
                ->inline()
                ->live()
                ->afterStateUpdated(function ($state, Set $set) {
                    if ($state === 'independent') {
                        $set('parent_distributor_id', null);
                        $set('is_independent', true);
                    } else {
                        $set('is_independent', false);
                    }
                })
                ->dehydrated(false)
                ->columnSpanFull(),

            Forms\Components\Select::make('parent_distributor_id')
                ->label('المتجر (موزع الجملة)')
                ->placeholder('اختر المتجر...')
                ->options(fn () => User::where('role', 'wholesale_distributor')
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id'))
                ->searchable()
                ->preload()
                ->required(fn (Get $get) => ($get('affiliation_type') ?? 'linked') === 'linked')
                ->visible(fn (Get $get) => ($get('affiliation_type') ?? 'linked') === 'linked')
                ->columnSpanFull(),

            Forms\Components\Hidden::make('is_independent')
                ->default(false)
                ->dehydrated(true),
        ];
    }

    protected static function retailAffiliationLockedToWholesaler(int $wholesalerId): array
    {
        return [
            Forms\Components\Placeholder::make('linked_to_store')
                ->label('')
                ->content('🏪 سيُربط هذا التاجر القطاعي بمتجرك تلقائياً — لا يمكنك إضافة موزع جملة آخر.')
                ->columnSpanFull(),

            Forms\Components\Hidden::make('parent_distributor_id')
                ->default($wholesalerId)
                ->dehydrated(true),

            Forms\Components\Hidden::make('is_independent')
                ->default(false)
                ->dehydrated(true),
        ];
    }

    protected static function brandCompanyFields(): array
    {
        return [
            Forms\Components\Section::make('هوية الشركة / المتجر')
                ->icon('heroicon-o-building-office')
                ->collapsible()
                ->schema([
                    Forms\Components\TextInput::make('brand_name')
                        ->label('اسم الشركة / المتجر الرسمي')
                        ->maxLength(150)
                        ->placeholder('مثال: مؤسسة الخليج للمواد الصحية'),
                    Forms\Components\FileUpload::make('brand_logo')
                        ->label('شعار الشركة')
                        ->disk('public')
                        ->directory('brand_logos')
                        ->image()
                        ->imageEditor()
                        ->imageCropAspectRatio('1:1')
                        ->imageResizeTargetWidth('400')
                        ->imageResizeTargetHeight('400')
                        ->helperText('مربع 400×400 — PNG مفضل'),
                ]),
        ];
    }
}
