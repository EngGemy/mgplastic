<?php

namespace App\Filament\Concerns;

use App\Models\Product;
use App\Models\ProductCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

trait HasProductWizardForm
{
    use HasProductPointsForm;

    protected static function isAccessoriesCategory(?int $categoryId): bool
    {
        if (! $categoryId) {
            return false;
        }

        $cat = ProductCategory::with('translations')->find($categoryId);
        if (! $cat) {
            return false;
        }

        $nameEn = mb_strtolower((string) optional($cat->translate('en'))->name);
        $nameAr = (string) optional($cat->translate('ar'))->name;
        $slug = mb_strtolower((string) $cat->slug);

        return str_contains($nameEn, 'accessor')
            || str_contains($nameAr, 'ملحق')
            || str_contains($slug, 'accessor')
            || str_contains($slug, 'ملحق');
    }

    protected static function parentCategoryOptions(): array
    {
        $locale = app()->getLocale();

        return ProductCategory::query()
            ->whereNull('parent_id')
            ->with('translations')
            ->get()
            ->mapWithKeys(function ($c) use ($locale) {
                $name = $c->translateOrDefault($locale)?->name
                    ?? $c->translate('en')?->name
                    ?? "فئة #{$c->id}";

                return [$c->id => $name];
            })
            ->sortBy(fn ($name) => mb_strtolower($name))
            ->toArray();
    }

    protected static function childCategoryOptions(?int $parentId): array
    {
        $locale = app()->getLocale();

        $query = ProductCategory::query()->with('translations');

        if ($parentId) {
            $query->where('parent_id', $parentId);
        } else {
            $query->whereNull('parent_id');
        }

        return $query->get()
            ->mapWithKeys(function ($c) use ($locale) {
                $name = $c->translateOrDefault($locale)?->name
                    ?? $c->translate('en')?->name
                    ?? "فئة #{$c->id}";

                return [$c->id => $name];
            })
            ->sortBy(fn ($name) => mb_strtolower($name))
            ->toArray();
    }

    protected static function productCategoryFields(): array
    {
        return [
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\Select::make('parent_category_id')
                    ->label('الفئة الرئيسية')
                    ->helperText('اختر القسم العام أولاً')
                    ->native(false)
                    ->searchable()
                    ->preload()
                    ->options(fn () => static::parentCategoryOptions())
                    ->afterStateHydrated(function ($set, ?Product $record) {
                        if ($record?->category) {
                            $set('parent_category_id', $record->category->parent_id);
                        }
                    })
                    ->live()
                    ->afterStateUpdated(fn ($state, callable $set) => $set('product_category_id', null)),

                Forms\Components\Select::make('product_category_id')
                    ->label('الفئة الفرعية')
                    ->helperText('التصنيف الدقيق للمنتج')
                    ->native(false)
                    ->searchable()
                    ->preload()
                    ->required()
                    ->options(fn (Get $get) => static::childCategoryOptions($get('parent_category_id')))
                    ->rules([
                        function (Get $get) {
                            $parentId = $get('parent_category_id');
                            if ($parentId) {
                                return Rule::exists('product_categories', 'id')->where('parent_id', $parentId);
                            }

                            return Rule::exists('product_categories', 'id');
                        },
                    ])
                    ->live(),

                Forms\Components\FileUpload::make('main_image')
                    ->label('الصورة الرئيسية')
                    ->helperText('صورة واضحة تظهر في القوائم والفواتير')
                    ->disk('public')
                    ->directory('products/main')
                    ->visibility('public')
                    ->image()
                    ->imageEditor()
                    ->imagePreviewHeight('200')
                    ->openable()
                    ->downloadable(),
            ]),
        ];
    }

    protected static function productBilingualContentFields(): array
    {
        return [
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\Section::make('🇸🇦 العربية')
                    ->schema([
                        Forms\Components\TextInput::make('name_ar')
                            ->label('اسم المنتج')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('مثال: ماسورة صرف خارجي')
                            ->extraAttributes(['dir' => 'rtl']),

                        Forms\Components\TextInput::make('usage_ar')
                            ->label('الاستخدام')
                            ->maxLength(255)
                            ->placeholder('مثال: شبكات الصرف الصحي')
                            ->extraAttributes(['dir' => 'rtl']),

                        Forms\Components\RichEditor::make('description_ar')
                            ->label('الوصف')
                            ->extraAttributes(['dir' => 'rtl'])
                            ->columnSpanFull(),
                    ])
                    ->columnSpan(1),

                Forms\Components\Section::make('🇬🇧 English')
                    ->schema([
                        Forms\Components\TextInput::make('name_en')
                            ->label('Product name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. External drainage pipe'),

                        Forms\Components\TextInput::make('usage_en')
                            ->label('Usage')
                            ->maxLength(255)
                            ->placeholder('e.g. Sanitary drainage networks'),

                        Forms\Components\RichEditor::make('description_en')
                            ->label('Description')
                            ->columnSpanFull(),
                    ])
                    ->columnSpan(1),
            ]),
        ];
    }

    protected static function productSpecsFields(): array
    {
        return [
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\TextInput::make('length_m')
                    ->label('الطول (م)')
                    ->numeric()
                    ->minValue(0)
                    ->step('0.001'),

                Forms\Components\TextInput::make('thickness_mm')
                    ->label('السُمك (مم)')
                    ->numeric()
                    ->minValue(0)
                    ->step('0.001'),

                Forms\Components\TextInput::make('volume_ml')
                    ->label('الحجم (مل)')
                    ->numeric()
                    ->minValue(0)
                    ->step('0.001'),
            ])
                ->hidden(fn (Get $get) => static::isAccessoriesCategory($get('product_category_id'))),

            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('classification')
                    ->label('التصنيف')
                    ->maxLength(50)
                    ->datalist(['standard', 'premium', 'economy'])
                    ->placeholder('standard / premium / economy')
                    ->hidden(fn (Get $get) => static::isAccessoriesCategory($get('product_category_id'))),

                Forms\Components\Textarea::make('notes')
                    ->label('ملاحظات داخلية')
                    ->rows(3)
                    ->placeholder('أي ملاحظات للفريق الإداري...')
                    ->hidden(fn (Get $get) => static::isAccessoriesCategory($get('product_category_id'))),
            ]),
        ];
    }

    protected static function productMediaFields(): array
    {
        return [
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\FileUpload::make('catalog_image_path')
                    ->label('صورة دليل المنتج')
                    ->disk('public')
                    ->directory('products/catalog/images')
                    ->visibility('public')
                    ->image()
                    ->imageEditor()
                    ->imagePreviewHeight('180')
                    ->openable()
                    ->downloadable(),

                Forms\Components\FileUpload::make('catalog_pdf_path')
                    ->label('ملف PDF')
                    ->disk('public')
                    ->directory('products/catalog/pdfs')
                    ->visibility('public')
                    ->acceptedFileTypes(['application/pdf'])
                    ->openable()
                    ->downloadable()
                    ->helperText('ورقة بيانات أو كتالوج المنتج'),
            ]),

            Forms\Components\Repeater::make('images')
                ->relationship()
                ->label('معرض الصور')
                ->helperText('اسحب لإعادة الترتيب — تظهر في صفحة المنتج')
                ->orderable('sort')
                ->reorderableWithButtons()
                ->collapsible()
                ->defaultItems(0)
                ->grid(2)
                ->schema([
                    Forms\Components\FileUpload::make('image')
                        ->label('صورة')
                        ->disk('public')
                        ->directory('products/gallery')
                        ->image()
                        ->imageEditor()
                        ->required(),
                    Forms\Components\TextInput::make('sort')
                        ->label('الترتيب')
                        ->numeric()
                        ->default(0),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ];
    }

    protected static function productPreviewFields(): array
    {
        return [
            Forms\Components\Section::make('معاينة سريعة')
                ->icon('heroicon-o-eye')
                ->compact()
                ->schema([
                    Forms\Components\Placeholder::make('preview_image')
                        ->content(function (?Product $record, callable $get) {
                            $raw = $get('main_image') ?? ($record?->main_image);
                            $path = is_array($raw) ? ($raw['path'] ?? $raw[0] ?? null) : $raw;
                            if (! is_string($path) || $path === '') {
                                return '—';
                            }
                            $url = Storage::disk('public')->url(ltrim($path, '/'));

                            return "<img src=\"{$url}\" alt=\"preview\" style=\"border-radius:12px;max-width:100%;height:auto;display:block;\">";
                        })
                        ->disableLabel()
                        ->columnSpanFull(),

                    Forms\Components\Placeholder::make('preview_name')
                        ->label('الاسم')
                        ->content(fn (callable $get) => $get('name_ar') ?: $get('name_en') ?: '—'),

                    Forms\Components\Placeholder::make('preview_category')
                        ->label('الفئة')
                        ->content(function (?Product $record, callable $get) {
                            $id = $get('product_category_id');
                            $cat = $id
                                ? ProductCategory::with('translations', 'parent.translations')->find($id)
                                : $record?->category?->loadMissing('translations', 'parent.translations');

                            if (! $cat) {
                                return '—';
                            }

                            $parent = $cat->parent ? localized_name($cat->parent, 'name').' ← ' : '';

                            return $parent.localized_name($cat, 'name');
                        }),

                    Forms\Components\Placeholder::make('preview_points')
                        ->label('النقاط/وحدة')
                        ->content(fn (callable $get) => number_format((float) ($get('points_per_unit') ?? 0), 2).' نقطة'),
                ]),
        ];
    }

    public static function editTabbedForm(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(12)->schema([
                Forms\Components\Tabs::make('product_edit_tabs')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('التصنيف')
                            ->icon('heroicon-o-tag')
                            ->schema(static::productCategoryFields()),

                        Forms\Components\Tabs\Tab::make('المحتوى')
                            ->icon('heroicon-o-language')
                            ->schema(static::productBilingualContentFields()),

                        Forms\Components\Tabs\Tab::make('المواصفات')
                            ->icon('heroicon-o-cube')
                            ->schema(static::productSpecsFields()),

                        Forms\Components\Tabs\Tab::make('النقاط')
                            ->icon('heroicon-o-star')
                            ->schema([static::productPointsSection()]),

                        Forms\Components\Tabs\Tab::make('الوسائط')
                            ->icon('heroicon-o-photo')
                            ->schema(static::productMediaFields()),
                    ])
                    ->persistTabInQueryString()
                    ->columnSpan(8),

                Forms\Components\Group::make(static::productPreviewFields())
                    ->columnSpan(4),
            ]),
        ]);
    }

    public static function createWizardForm(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Wizard::make([
                Forms\Components\Wizard\Step::make('التصنيف')
                    ->description('الفئة والصورة الرئيسية')
                    ->icon('heroicon-o-tag')
                    ->schema([
                        Forms\Components\Placeholder::make('step1_help')
                            ->content('① اختر الفئة الرئيسية ثم الفرعية — ② ارفع صورة واضحة للمنتج.')
                            ->columnSpanFull(),
                        ...static::productCategoryFields(),
                    ]),

                Forms\Components\Wizard\Step::make('المحتوى')
                    ->description('الاسم والوصف — عربي وإنجليزي')
                    ->icon('heroicon-o-language')
                    ->schema(static::productBilingualContentFields()),

                Forms\Components\Wizard\Step::make('المواصفات')
                    ->description('الأبعاد والتصنيف (للمواسير والأنابيب)')
                    ->icon('heroicon-o-cube')
                    ->schema(static::productSpecsFields()),

                Forms\Components\Wizard\Step::make('النقاط')
                    ->description('قيمة النقاط وتحويلها المالي')
                    ->icon('heroicon-o-star')
                    ->schema(static::productPointsSchema()),

                Forms\Components\Wizard\Step::make('الوسائط')
                    ->description('دليل PDF ومعرض الصور')
                    ->icon('heroicon-o-photo')
                    ->schema(static::productMediaFields()),

                Forms\Components\Wizard\Step::make('تأكيد')
                    ->description('مراجعة قبل الحفظ')
                    ->icon('heroicon-o-check-circle')
                    ->schema([
                        Forms\Components\ViewField::make('create_summary')
                            ->view('filament.forms.product-create-summary'),
                    ]),
            ])
                ->skippable(false)
                ->nextAction(fn (Forms\Components\Actions\Action $action) => $action->label('التالي ←'))
                ->previousAction(fn (Forms\Components\Actions\Action $action) => $action->label('→ السابق'))
                ->columnSpanFull(),
        ]);
    }
}
