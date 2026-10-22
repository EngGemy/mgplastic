<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\BadgeResource\Pages;
use App\Filament\Resources\BadgeResource\RelationManagers;
use App\Models\Badge;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class BadgeResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = Badge::class;

    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?int $navigationSort = 42;

    protected static ?string $recordTitleAttribute = 'name_ar';

    public static function getNavigationGroup(): ?string
    {
        return 'نظام النقاط';
    }

    public static function getNavigationLabel(): string
    {
        return 'الشارات';
    }

    public static function getModelLabel(): string
    {
        return 'شارة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الشارات';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('الهوية')
                            ->description('الاسم الظاهر في التطبيق والمفتاح البرمجي الفريد.')
                            ->icon('heroicon-o-identification')
                            ->columns(12)
                            ->schema([
                                Forms\Components\TextInput::make('name_ar')
                                    ->label('الاسم بالعربية')
                                    ->required()
                                    ->maxLength(120)
                                    ->live(debounce: 300)
                                    ->afterStateUpdated(function (?string $state, Set $set, Get $get) {
                                        if (filled($get('key'))) {
                                            return;
                                        }
                                        $set('key', Str::slug((string) $state, '_'));
                                    })
                                    ->extraAttributes(['dir' => 'rtl'])
                                    ->columnSpan(6),

                                Forms\Components\TextInput::make('name_en')
                                    ->label('الاسم بالإنجليزية')
                                    ->maxLength(120)
                                    ->placeholder('First order')
                                    ->columnSpan(6),

                                Forms\Components\TextInput::make('key')
                                    ->label('المفتاح البرمجي')
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->maxLength(64)
                                    ->live(debounce: 300)
                                    ->helperText('مثل: first_order — يُستخدم في الكود ولا يُغيَّر بعد الإطلاق.')
                                    ->extraInputAttributes(['dir' => 'ltr', 'class' => 'font-mono'])
                                    ->columnSpan(6),

                                Forms\Components\Textarea::make('description_ar')
                                    ->label('الوصف للسباك')
                                    ->rows(3)
                                    ->maxLength(500)
                                    ->live(debounce: 400)
                                    ->placeholder('جملة قصيرة توضح كيف يفوز السباك بهذه الشارة.')
                                    ->extraAttributes(['dir' => 'rtl'])
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Section::make('المظهر والندرة')
                            ->description('الأيقونة والندرة تحددان شكل البطاقة في التطبيق والإشعارات.')
                            ->icon('heroicon-o-sparkles')
                            ->columns(12)
                            ->schema([
                                Forms\Components\Select::make('icon')
                                    ->label('الأيقونة')
                                    ->required()
                                    ->native(false)
                                    ->live()
                                    ->options(self::iconOptions())
                                    ->default('star')
                                    ->columnSpan(4),

                                Forms\Components\Select::make('tier')
                                    ->label('الندرة')
                                    ->required()
                                    ->native(false)
                                    ->live()
                                    ->options([
                                        'common' => 'شائعة',
                                        'rare' => 'نادرة',
                                        'epic' => 'ملحمية',
                                        'legendary' => 'أسطورية',
                                    ])
                                    ->default('common')
                                    ->columnSpan(4),

                                Forms\Components\TextInput::make('sort_order')
                                    ->label('ترتيب العرض')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->helperText('الأصغر يظهر أولًا في القائمة.')
                                    ->columnSpan(4),

                                Forms\Components\Toggle::make('is_active')
                                    ->label('مفعّلة في الكتالوج')
                                    ->helperText('إن أوقفتها لن تظهر للسباك ولن تُمنح تلقائياً — المنح اليدوي يبقى متاحاً للإدارة.')
                                    ->default(true)
                                    ->live()
                                    ->inline(false)
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Section::make('معيار الفوز / المنح')
                            ->description('اختر: منح يدوي من اللوحة فقط، أو معيار تلقائي عند تحقق الشرط. يمكنك دائماً منح أي شارة يدوياً من تبويب المستلمين.')
                            ->icon('heroicon-o-adjustments-horizontal')
                            ->columns(12)
                            ->schema([
                                Forms\Components\Select::make('rule_type')
                                    ->label('كيف تُمنح؟')
                                    ->required()
                                    ->native(false)
                                    ->live()
                                    ->options(self::ruleTypeOptions())
                                    ->dehydrated(false)
                                    ->helperText('«منح يدوي فقط» = أنت تطبّقها بنفسك من اللوحة. باقي الأنواع تُقيَّم تلقائياً ويمكنك أيضاً منحها يدوياً.')
                                    ->afterStateHydrated(function (Forms\Components\Select $component, ?Badge $record) {
                                        $component->state((string) data_get($record?->rule, 'type', 'manual'));
                                    })
                                    ->columnSpan(6),

                                Forms\Components\TextInput::make('rule_min')
                                    ->label(fn (Get $get) => self::ruleMinLabel((string) $get('rule_type')))
                                    ->numeric()
                                    ->minValue(0)
                                    ->visible(fn (Get $get) => self::ruleNeedsNumericMin((string) $get('rule_type')))
                                    ->dehydrated(false)
                                    ->afterStateHydrated(function (Forms\Components\TextInput $component, ?Badge $record) {
                                        $min = data_get($record?->rule, 'min');
                                        if (is_numeric($min)) {
                                            $component->state((int) $min);
                                        }
                                    })
                                    ->columnSpan(6),

                                Forms\Components\Select::make('rule_level')
                                    ->label('المستوى الأدنى')
                                    ->native(false)
                                    ->options([
                                        'bronze' => 'برونزي',
                                        'silver' => 'فضي',
                                        'gold' => 'ذهبي',
                                        'diamond' => 'ماسي',
                                    ])
                                    ->visible(fn (Get $get) => (string) $get('rule_type') === 'level_min')
                                    ->dehydrated(false)
                                    ->afterStateHydrated(function (Forms\Components\Select $component, ?Badge $record) {
                                        $min = data_get($record?->rule, 'min');
                                        if (is_string($min) && $min !== '') {
                                            $component->state($min);
                                        }
                                    })
                                    ->columnSpan(6),

                                Forms\Components\Placeholder::make('rule_hint')
                                    ->label('ملاحظة')
                                    ->content(fn (Get $get) => self::ruleHint((string) $get('rule_type')))
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->columnSpan(['lg' => 8]),

                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make()
                            ->schema([
                                Forms\Components\ViewField::make('badge_preview')
                                    ->view('filament.forms.badge-preview')
                                    ->viewData(fn (Get $get): array => ['get' => $get])
                                    ->dehydrated(false),
                            ])
                            ->extraAttributes(['class' => 'mg-badge-studio-wrap']),
                    ])
                    ->columnSpan(['lg' => 4]),
            ])
            ->columns(12);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->color('gray'),

                Tables\Columns\TextColumn::make('icon')
                    ->label('')
                    ->formatStateUsing(fn (?string $state) => self::iconGlyph($state))
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('name_ar')
                    ->label('الشارة')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Badge $record) => $record->key)
                    ->weight(FontWeight::SemiBold),

                Tables\Columns\TextColumn::make('tier')
                    ->label('الندرة')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'legendary' => 'أسطورية',
                        'epic' => 'ملحمية',
                        'rare' => 'نادرة',
                        default => 'شائعة',
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'legendary' => 'warning',
                        'epic' => 'primary',
                        'rare' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('rule')
                    ->label('المعيار')
                    ->formatStateUsing(function ($state) {
                        $type = (string) data_get($state, 'type', '—');
                        $min = data_get($state, 'min');
                        $label = self::ruleTypeOptions()[$type] ?? $type;

                        if ($type === 'manual') {
                            return 'يدوي فقط';
                        }

                        return $min !== null && $min !== '' ? "{$label} · {$min}" : $label;
                    })
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('مفعّلة')
                    ->boolean(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->emptyStateHeading('لا شارات بعد')
            ->emptyStateDescription('أنشئ أول شارة بمعيار فوز واضح — تُمنح تلقائياً عند تحقق الشرط.')
            ->emptyStateIcon('heroicon-o-trophy')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()
                    ->label('شارة جديدة')
                    ->icon('heroicon-o-plus'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tier')
                    ->label('الندرة')
                    ->options([
                        'common' => 'شائعة',
                        'rare' => 'نادرة',
                        'epic' => 'ملحمية',
                        'legendary' => 'أسطورية',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')->label('مفعّلة'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('حذف المحدد'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBadges::route('/'),
            'create' => Pages\CreateBadge::route('/create'),
            'edit' => Pages\EditBadge::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\RecipientsRelationManager::class,
        ];
    }

    /** @return array<string, string> */
    public static function iconOptions(): array
    {
        return [
            'star' => '⭐ نجمة',
            'medal' => '🏅 ميدالية',
            'trophy' => '🏆 كأس',
            'coin' => '🪙 عملة',
            'fire' => '🔥 نار',
            'shield' => '🛡️ درع',
            'sun' => '☀️ شمس',
            'heart' => '💙 قلب',
            'basket' => '🧺 سلة',
            'badge' => '🎖️ شارة',
            'diamond' => '💎 ماسة',
        ];
    }

    public static function iconGlyph(?string $icon): string
    {
        return match ($icon) {
            'medal' => '🏅',
            'trophy' => '🏆',
            'coin' => '🪙',
            'fire' => '🔥',
            'shield' => '🛡️',
            'sun' => '☀️',
            'heart' => '💙',
            'basket' => '🧺',
            'badge' => '🎖️',
            'diamond' => '💎',
            default => '⭐',
        };
    }

    /** @return array<string, string> */
    public static function ruleTypeOptions(): array
    {
        return [
            'manual' => 'منح يدوي فقط (من لوحة الإدارة)',
            'first_order' => 'أول طلب / توزيع',
            'orders_min' => 'حد أدنى لعدد الطلبات',
            'points_min' => 'حد أدنى للنقاط',
            'streak_min' => 'سلسلة أسابيع نشاط',
            'level_min' => 'مستوى السمعة الأدنى',
            'warranty_min' => 'حد أدنى للضمانات',
            'no_returns_30d' => 'بدون مرتجعات (30 يومًا)',
            'early_bird' => 'الطائر المبكر (قريبًا)',
            'mg_loyal' => 'ولاء MG (قريبًا)',
            'big_basket' => 'سلة كبيرة (قريبًا)',
        ];
    }

    public static function ruleNeedsNumericMin(string $type): bool
    {
        return in_array($type, [
            'first_order', 'orders_min', 'points_min', 'streak_min', 'warranty_min',
            'early_bird', 'mg_loyal', 'big_basket',
        ], true);
    }

    public static function ruleMinLabel(string $type): string
    {
        return match ($type) {
            'points_min' => 'الحد الأدنى للنقاط',
            'streak_min' => 'عدد الأسابيع',
            'warranty_min' => 'عدد الضمانات',
            'mg_loyal' => 'نسبة الولاء %',
            'big_basket' => 'حد السلة',
            default => 'الحد الأدنى',
        };
    }

    public static function ruleHint(string $type): string
    {
        return match ($type) {
            'manual' => 'لن تُمنح تلقائياً أبداً. أنت تضبطها وتطبّقها يدوياً من تبويب «السباكون الحاصلون» أو من لوحة السمعة.',
            'first_order' => 'تُمنح عند أول توزيع مؤكد للسباك (tier 3). يمكنك أيضاً منحها يدوياً.',
            'orders_min' => 'تُحسب التوزيعات المؤكدة للسباك فقط.',
            'points_min' => 'يُقارن برصيد نقاط محفظة السباك الحالي.',
            'streak_min' => 'يعتمد على سلسلة الأسابيع النشطة في السمعة.',
            'level_min' => 'برونزي → فضي → ذهبي → ماسي.',
            'warranty_min' => 'عدد الضمانات الصادرة باسم السباك.',
            'no_returns_30d' => 'يتطلب طلبًا واحدًا على الأقل وغياب مرتجعات مقبولة خلال 30 يومًا.',
            'early_bird', 'mg_loyal', 'big_basket' => 'المعيار معرّف في الكتالوج وسيُفعَّل لاحقًا ببيانات أغنى — يمكن حفظ الشارة الآن أو منحها يدوياً.',
            default => 'اختر معيارًا مناسبًا لسلوك السباك المراد مكافأته.',
        };
    }
}
