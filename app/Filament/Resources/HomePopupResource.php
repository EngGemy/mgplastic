<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\HomePopupResource\Pages;
use App\Models\Event;
use App\Models\HomePopup;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\ProductPromotion;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class HomePopupResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = HomePopup::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return 'بوب أب الرئيسية';
    }

    public static function getNavigationLabel(): string
    {
        return 'إدارة البوب أب';
    }

    public static function getModelLabel(): string
    {
        return 'بوب أب';
    }

    public static function getPluralModelLabel(): string
    {
        return 'بوب أب الرئيسية';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('الإحصائيات المباشرة')
                    ->description('مشاهدات / ضغطات فريدة · تتحدّث مع أحداث التطبيق')
                    ->icon('heroicon-o-chart-bar')
                    ->visible(fn (?HomePopup $record) => filled($record?->id))
                    ->schema([
                        Forms\Components\Grid::make(4)->schema([
                            Forms\Components\Placeholder::make('stat_views')
                                ->label('مشاهدات')
                                ->content(fn (?HomePopup $record) => (string) ($record?->statsSummary()['views'] ?? 0)),
                            Forms\Components\Placeholder::make('stat_clicks')
                                ->label('ضغطات')
                                ->content(fn (?HomePopup $record) => (string) ($record?->statsSummary()['clicks'] ?? 0)),
                            Forms\Components\Placeholder::make('stat_ctr')
                                ->label('CTR')
                                ->content(function (?HomePopup $record) {
                                    $ctr = (float) ($record?->statsSummary()['ctr'] ?? 0);

                                    return round($ctr * 100, 1).'%';
                                }),
                            Forms\Components\Placeholder::make('stat_dismiss')
                                ->label('إغلاق')
                                ->content(fn (?HomePopup $record) => (string) ($record?->statsSummary()['dismisses'] ?? 0)),
                        ]),
                        Forms\Components\Placeholder::make('stat_buttons')
                            ->label('نتائج الأزرار / التصويت')
                            ->content(function (?HomePopup $record) {
                                $by = $record?->statsSummary()['by_button'] ?? [];
                                if ($by === []) {
                                    return 'لا بيانات بعد';
                                }
                                $total = max(1, array_sum($by));
                                $parts = [];
                                foreach ($by as $id => $count) {
                                    $parts[] = $id.': '.$count.' ('.round(($count / $total) * 100, 1).'%)';
                                }

                                return implode(' · ', $parts);
                            }),
                    ])
                    ->collapsed(false)
                    ->columnSpanFull(),

                Forms\Components\Tabs::make('popup_tabs')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('المحتوى')
                            ->icon('heroicon-o-photo')
                            ->schema([
                                Forms\Components\Section::make('النوع والصورة')
                                    ->description('الصورة تتحمّل قبل ظهور الكارت في التطبيق — اضغطها لحد ~300KB، عرض 800px، نسبة مقترحة 4:5.')
                                    ->schema([
                                        Forms\Components\ToggleButtons::make('kind')
                                            ->label('نوع البوب أب')
                                            ->options([
                                                'announcement' => 'إعلان',
                                                'poll' => 'تصويت',
                                            ])
                                            ->icons([
                                                'announcement' => 'heroicon-o-megaphone',
                                                'poll' => 'heroicon-o-chart-bar',
                                            ])
                                            ->colors([
                                                'announcement' => 'primary',
                                                'poll' => 'warning',
                                            ])
                                            ->inline()
                                            ->required()
                                            ->live()
                                            ->default('announcement')
                                            ->helperText('التصويت: أول إجابتين جنب بعض، وبعد الإجابة مش يظهر تاني لنفس version.')
                                            ->columnSpanFull(),

                                        Forms\Components\FileUpload::make('image_path')
                                            ->label('الصورة')
                                            ->disk('public')
                                            ->directory('home-popups')
                                            ->image()
                                            ->imageEditor()
                                            ->imageEditorAspectRatios(['4:5', '16:9', '1:1', null])
                                            ->imagePreviewHeight('280')
                                            ->openable()
                                            ->downloadable()
                                            ->maxSize(512)
                                            ->helperText('مستحسن 800×1000 (4:5) · أقصى رفع هنا 512KB — الأفضل ≤300KB WebP/JPG')
                                            ->columnSpanFull()
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function ($state, Set $set) {
                                                if (! is_string($state) || $state === '') {
                                                    return;
                                                }
                                                try {
                                                    $absolute = \Illuminate\Support\Facades\Storage::disk('public')->path($state);
                                                    if (! is_file($absolute)) {
                                                        return;
                                                    }
                                                    $size = @getimagesize($absolute);
                                                    if (is_array($size) && ($size[1] ?? 0) > 0) {
                                                        $ratio = round(((float) $size[0]) / ((float) $size[1]), 2);
                                                        if ($ratio >= 0.5 && $ratio <= 2.0) {
                                                            $set('image_ratio', $ratio);
                                                        }
                                                    }
                                                } catch (\Throwable) {
                                                    // ignore
                                                }
                                            }),

                                        Forms\Components\TextInput::make('image_ratio')
                                            ->label('نسبة الصورة (عرض ÷ طول)')
                                            ->numeric()
                                            ->step(0.01)
                                            ->minValue(0.5)
                                            ->maxValue(2)
                                            ->default(0.8)
                                            ->helperText('تتحسب تلقائياً من الصورة · 4:5 = 0.8 · 16:9 = 1.78'),
                                    ]),

                                Forms\Components\Section::make('النصوص (عربي / English)')
                                    ->schema([
                                        Forms\Components\Grid::make(2)->schema([
                                            Forms\Components\TextInput::make('title_ar')
                                                ->label('العنوان عربي')
                                                ->maxLength(60)
                                                ->placeholder('سباك الشهر'),
                                            Forms\Components\TextInput::make('title_en')
                                                ->label('Title English')
                                                ->maxLength(60)
                                                ->placeholder('Plumber of the month'),
                                            Forms\Components\Textarea::make('body_ar')
                                                ->label('النص عربي')
                                                ->rows(3)
                                                ->maxLength(200)
                                                ->placeholder('أحمد علي — أعلى تقييم…'),
                                            Forms\Components\Textarea::make('body_en')
                                                ->label('Body English')
                                                ->rows(3)
                                                ->maxLength(200),
                                        ]),
                                    ]),
                            ]),

                        Forms\Components\Tabs\Tab::make('الأزرار')
                            ->icon('heroicon-o-cursor-arrow-rays')
                            ->schema([
                                Forms\Components\Section::make('أزرار البوب أب')
                                    ->description(fn (Get $get) => $get('kind') === 'poll'
                                        ? 'كل زرار = إجابة تصويت. خلّي link_type = «بدون — إجابة» وlink فاضية. أول إجابتين يظهروا جنب بعض.'
                                        : 'رتّب الأزرار · أي ضغط بيقفل البوب أب ويبعت إحصائيات. الوجهة: سباك / محل / منتج / تسجيل / لينك خارجي…')
                                    ->schema([
                                        Forms\Components\Repeater::make('buttons')
                                            ->label('الأزرار')
                                            ->addActionLabel('إضافة زرار')
                                            ->reorderable()
                                            ->collapsible()
                                            ->cloneable()
                                            ->defaultItems(1)
                                            ->itemLabel(fn (array $state): ?string => ($state['text_ar'] ?? $state['id'] ?? null)
                                                ? trim(($state['id'] ?? '').' — '.($state['text_ar'] ?? ''))
                                                : null)
                                            ->schema([
                                                Forms\Components\Grid::make(3)->schema([
                                                    Forms\Components\TextInput::make('id')
                                                        ->label('مفتاح الزرار (للإحصائيات)')
                                                        ->required()
                                                        ->maxLength(40)
                                                        ->placeholder('visit · yes · facebook')
                                                        ->helperText('في التصويت: yes / no / a / b'),
                                                    Forms\Components\Select::make('style')
                                                        ->label('الشكل')
                                                        ->options([
                                                            'primary' => 'Primary',
                                                            'outline' => 'Outline',
                                                            'ghost' => 'Ghost',
                                                        ])
                                                        ->default('primary'),
                                                    Forms\Components\Select::make('link_type')
                                                        ->label('الوجهة')
                                                        ->options([
                                                            'none' => 'بدون — إجابة تصويت',
                                                            'plumber' => 'سباك معيّن',
                                                            'shop' => 'محل معيّن',
                                                            'product' => 'منتج',
                                                            'product_promotion' => 'عرض MG',
                                                            'product_collection' => 'مجموعة منتجات',
                                                            'event' => 'فعالية',
                                                            'register' => 'تسجيل حساب (زوار)',
                                                            'login' => 'تسجيل الدخول',
                                                            'catalog' => 'الكتالوج',
                                                            'list_plumbers' => 'كل السباكين',
                                                            'list_shops' => 'كل المحلات',
                                                            'list_events' => 'كل الفعاليات',
                                                            'support' => 'الدعم',
                                                            'home' => 'الرئيسية',
                                                            'wallet' => 'المحفظة',
                                                            'points' => 'النقاط',
                                                            'pending_receipts' => 'بضاعة بانتظار الاستلام',
                                                            'deep_link' => 'مسار deep link حر',
                                                            'url' => 'لينك خارجي (فيسبوك / واتساب / …)',
                                                        ])
                                                        ->searchable()
                                                        ->required()
                                                        ->live()
                                                        ->default('none'),
                                                ]),
                                                Forms\Components\Grid::make(2)->schema([
                                                    Forms\Components\TextInput::make('text_ar')
                                                        ->label('نص الزرار عربي')
                                                        ->required()
                                                        ->maxLength(25),
                                                    Forms\Components\TextInput::make('text_en')
                                                        ->label('Button text EN')
                                                        ->maxLength(25),
                                                ]),
                                                Forms\Components\Select::make('link_id')
                                                    ->label(fn (Get $get) => match ($get('link_type')) {
                                                        'plumber' => 'اختر السباك',
                                                        'shop' => 'اختر المحل',
                                                        'event' => 'اختر الفعالية',
                                                        'product' => 'اختر المنتج',
                                                        'product_collection' => 'اختر المجموعة',
                                                        'product_promotion' => 'اختر العرض',
                                                        default => 'الهدف',
                                                    })
                                                    ->searchable()
                                                    ->preload()
                                                    ->required(fn (Get $get) => in_array($get('link_type'), [
                                                        'plumber', 'shop', 'event', 'product',
                                                        'product_collection', 'product_promotion',
                                                    ], true))
                                                    ->options(fn (Get $get) => self::linkTargetOptions((string) $get('link_type')))
                                                    ->visible(fn (Get $get) => in_array($get('link_type'), [
                                                        'plumber', 'shop', 'event', 'product',
                                                        'product_collection', 'product_promotion',
                                                    ], true)),
                                                Forms\Components\TextInput::make('link_path')
                                                    ->label('مسار deep link')
                                                    ->placeholder('trader-orders أو plumber-orders/new')
                                                    ->visible(fn (Get $get) => $get('link_type') === 'deep_link'),
                                                Forms\Components\TextInput::make('link_url')
                                                    ->label('لينك خارجي')
                                                    ->placeholder('https://facebook.com/… · https://wa.me/2189… · tel:+2189…')
                                                    ->visible(fn (Get $get) => $get('link_type') === 'url'),
                                            ])
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        Forms\Components\Tabs\Tab::make('الجمهور والجدولة')
                            ->icon('heroicon-o-user-group')
                            ->schema([
                                Forms\Components\Section::make('مين يشوفه؟')
                                    ->schema([
                                        Forms\Components\CheckboxList::make('target_roles')
                                            ->label('الأدوار')
                                            ->options([
                                                'guest' => 'زائر / ضيف (بدون توكن)',
                                                'plumber' => 'سباك',
                                                'retail_trader' => 'تاجر قطاعي (و vendor)',
                                                'wholesale_distributor' => 'موزع جملة',
                                            ])
                                            ->columns(2)
                                            ->required()
                                            ->default(['guest'])
                                            ->helperText('اعمل بوب أب منفصل لكل جمهور لو المحتوى مختلف'),

                                        Forms\Components\Select::make('test_user_ids')
                                            ->label('حسابات تجربة فقط (اختياري)')
                                            ->multiple()
                                            ->searchable()
                                            ->preload()
                                            ->options(fn () => User::query()
                                                ->whereIn('role', ['plumber', 'retail_trader', 'wholesale_distributor', 'vendor'])
                                                ->where('is_active', true)
                                                ->orderBy('name')
                                                ->limit(300)
                                                ->get()
                                                ->mapWithKeys(fn (User $u) => [
                                                    $u->id => trim(($u->name ?: 'مستخدم').' · '.$u->role.' #'.$u->id),
                                                ]))
                                            ->helperText('لو اخترت حسابات: البوب أب يظهر لهم بس قبل النشر للكل'),
                                    ]),

                                Forms\Components\Section::make('الظهور والإغلاق')
                                    ->schema([
                                        Forms\Components\ToggleButtons::make('show')
                                            ->label('التكرار')
                                            ->options([
                                                'once' => 'مرة واحدة',
                                                'daily' => 'مرة يومياً',
                                                'always' => 'كل فتح',
                                            ])
                                            ->inline()
                                            ->required()
                                            ->default('once')
                                            ->helperText('التصويت يختفي بعد الإجابة بغض النظر عن التكرار'),

                                        Forms\Components\Toggle::make('dismissible')
                                            ->label('يمكن الإغلاق (× / لاحقاً / برة الكارت)')
                                            ->default(true)
                                            ->inline(false)
                                            ->helperText('عطّله في التصويت الإجباري'),

                                        Forms\Components\Grid::make(2)->schema([
                                            Forms\Components\TextInput::make('dismiss_text_ar')
                                                ->label('نص «لاحقاً» عربي')
                                                ->placeholder('لاحقاً')
                                                ->maxLength(40)
                                                ->helperText('اتركه فاضي = الافتراضي · اكتب فراغ مقصود بـ "" من الـ API لإخفاء الزرار'),
                                            Forms\Components\TextInput::make('dismiss_text_en')
                                                ->label('Dismiss text EN')
                                                ->placeholder('Later')
                                                ->maxLength(40),
                                        ]),

                                        Forms\Components\Grid::make(2)->schema([
                                            Forms\Components\DateTimePicker::make('starts_at')
                                                ->label('من (توقيت ليبيا UTC+2)')
                                                ->seconds(false)
                                                ->native(false),
                                            Forms\Components\DateTimePicker::make('ends_at')
                                                ->label('إلى')
                                                ->seconds(false)
                                                ->native(false),
                                        ]),

                                        Forms\Components\TextInput::make('sort_order')
                                            ->label('الأولوية')
                                            ->numeric()
                                            ->default(0)
                                            ->helperText('الأعلى يظهر لو فيه أكتر من بوب أب لنفس الدور'),

                                        Forms\Components\Toggle::make('is_active')
                                            ->label('مفعّل — ظاهر في التطبيق')
                                            ->default(true)
                                            ->inline(false)
                                            ->helperText('إيقافه → popup: null فوراً'),

                                        Forms\Components\Placeholder::make('version_info')
                                            ->label('رقم الإصدار الحالي')
                                            ->content(fn (?HomePopup $record) => 'version = '.((int) ($record?->version ?? 1))),

                                        Forms\Components\Checkbox::make('rebroadcast')
                                            ->label('اعرضه تاني للي شافوه (يزيد version)')
                                            ->helperText('تصليح إملائي عادي ما يزودش الإصدار — فعّل ده بس لو عايز يظهر من جديد')
                                            ->dehydrated(false)
                                            ->visible(fn (?HomePopup $record) => $record !== null),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull()
                    ->persistTabInQueryString(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')
                    ->label('صورة')
                    ->disk('public')
                    ->square()
                    ->size(64)
                    ->extraImgAttributes(['loading' => 'lazy', 'decoding' => 'async'])
                    ->getStateUsing(function (HomePopup $record): ?string {
                        $path = $record->image_path;
                        if (is_array($path)) {
                            $path = $path[0] ?? null;
                        }
                        if (! is_string($path) || $path === '' || str_starts_with($path, '[')) {
                            return null;
                        }

                        return $path;
                    }),
                Tables\Columns\TextColumn::make('title_ar')
                    ->label('العنوان')
                    ->placeholder('—')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (HomePopup $record): string => 'v'.((int) $record->version).' · '.((string) ($record->kind ?: 'announcement'))),
                Tables\Columns\TextColumn::make('kind')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ((string) $state) === 'poll' ? 'تصويت' : 'إعلان')
                    ->color(fn ($state): string => ((string) $state) === 'poll' ? 'warning' : 'primary'),
                // Virtual string column — avoid Filament exploding JSON array attrs (500 htmlspecialchars).
                Tables\Columns\TextColumn::make('audience_label')
                    ->label('الجمهور')
                    ->getStateUsing(function (HomePopup $record): string {
                        $labels = [
                            'guest' => 'زائر',
                            'plumber' => 'سباك',
                            'retail_trader' => 'قطاعي',
                            'wholesale_distributor' => 'جملة',
                        ];
                        $roles = is_array($record->target_roles) ? $record->target_roles : [];

                        return collect($roles)
                            ->map(fn ($r) => $labels[$r] ?? (string) $r)
                            ->filter()
                            ->implode(' · ') ?: '—';
                    }),
                Tables\Columns\TextColumn::make('show')
                    ->label('تكرار')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => match ((string) $state) {
                        'daily' => 'يومياً',
                        'always' => 'كل فتح',
                        default => 'مرة',
                    }),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('تفعيل')
                    ->onColor('success')
                    ->offColor('danger')
                    ->afterStateUpdated(function (HomePopup $record, bool $state): void {
                        // Pausing → mobile gets popup: null on next home/layout.
                    }),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('أولوية')
                    ->sortable(),
            ])
            ->defaultSort('sort_order', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('kind')
                    ->options(['announcement' => 'إعلان', 'poll' => 'تصويت']),
                Tables\Filters\TernaryFilter::make('is_active')->label('مفعّل'),
            ])
            ->actions([
                Tables\Actions\Action::make('preview_json')
                    ->label('JSON')
                    ->icon('heroicon-o-code-bracket')
                    ->color('gray')
                    ->modalHeading(fn (HomePopup $record): string => 'معاينة الـ popup · #'.$record->id.' · v'.$record->version)
                    ->modalDescription('نفس الشكل اللي هيوصّل للتطبيق من data.popup (عربي · أول دور مستهدف أو guest).')
                    ->modalContent(fn (HomePopup $record) => self::jsonPreviewHtml($record))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('إغلاق'),
                Tables\Actions\Action::make('toggle_active')
                    ->label(fn (HomePopup $record) => $record->is_active ? 'إيقاف' : 'تفعيل')
                    ->icon(fn (HomePopup $record) => $record->is_active ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
                    ->color(fn (HomePopup $record) => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (HomePopup $record) => $record->is_active
                        ? 'إيقاف البوب أب؟'
                        : 'تفعيل البوب أب؟')
                    ->modalDescription(fn (HomePopup $record) => $record->is_active
                        ? 'هيختفي من التطبيق فوراً (popup: null).'
                        : 'هيظهر للجمهور المستهدف حسب الجدولة.')
                    ->action(function (HomePopup $record): void {
                        $record->is_active = ! $record->is_active;
                        $record->save();
                    }),
                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\BulkAction::make('activate')
                        ->label('تفعيل المحدد')
                        ->icon('heroicon-o-play-circle')
                        ->color('success')
                        ->action(fn ($records) => $records->each->update(['is_active' => true])),
                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('إيقاف المحدد')
                        ->icon('heroicon-o-pause-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['is_active' => false])),
                ]),
            ])
            ->emptyStateHeading('مفيش بوب أب بعد')
            ->emptyStateDescription('أضف إعلاناً أو تصويتاً يظهر أول ما المستخدم يفتح الرئيسية.')
            ->emptyStateIcon('heroicon-o-sparkles');
    }

    public static function jsonPreviewHtml(HomePopup $record): \Illuminate\Support\HtmlString
    {
        $roles = is_array($record->target_roles) && $record->target_roles !== []
            ? $record->target_roles
            : ['guest'];
        $role = (string) ($roles[0] ?? 'guest');
        if ($role === 'vendor') {
            $role = 'retail_trader';
        }

        $payload = $record->toMobilePayload($role, 'ar');
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return new \Illuminate\Support\HtmlString(
            '<div style="margin-bottom:8px;font-size:13px;color:#64748b;">role = <b>'.e($role).'</b> · Accept-Language = ar</div>'
            .'<pre style="direction:ltr;text-align:left;max-height:480px;overflow:auto;font-size:12px;line-height:1.45;background:#0f172a;color:#e2e8f0;padding:14px;border-radius:10px;white-space:pre-wrap;word-break:break-word;">'
            .e((string) $json)
            .'</pre>'
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHomePopups::route('/'),
            'create' => Pages\CreateHomePopup::route('/create'),
            'edit' => Pages\EditHomePopup::route('/{record}/edit'),
        ];
    }

    /** @return array<int|string, string> */
    protected static function linkTargetOptions(string $linkType): array
    {
        return match ($linkType) {
            'plumber' => User::query()
                ->where('role', 'plumber')->where('is_active', true)
                ->orderBy('name')->limit(500)->get()
                ->mapWithKeys(fn (User $u) => [$u->id => trim(($u->name ?: 'سباك').' #'.$u->id)])
                ->all(),
            'shop' => User::query()
                ->whereIn('role', ['retail_trader', 'wholesale_distributor', 'vendor'])
                ->where('is_active', true)
                ->orderBy('brand_name')->orderBy('name')->limit(500)->get()
                ->mapWithKeys(fn (User $u) => [$u->id => trim(($u->brand_name ?: $u->name ?: 'محل').' #'.$u->id)])
                ->all(),
            'event' => Event::query()->with('translations')->orderByDesc('id')->limit(200)->get()
                ->mapWithKeys(fn (Event $e) => [$e->id => (string) localized_name($e, 'title', "فعالية #{$e->id}")])
                ->all(),
            'product_collection' => ProductCollection::query()->where('is_active', true)
                ->orderBy('sort_order')->orderBy('title')->pluck('title', 'id')->all(),
            'product_promotion' => ProductPromotion::query()->where('is_active', true)
                ->orderBy('sort_order')->orderBy('title')->pluck('title', 'id')->all(),
            'product' => Product::query()->with('translations')->orderBy('id')->limit(500)->get()
                ->mapWithKeys(fn (Product $p) => [$p->id => localized_name($p, 'name', "منتج #{$p->id}")])
                ->all(),
            default => [],
        };
    }
}
