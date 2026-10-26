<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\InteractsWithMobileHomeLayout;
use App\Services\HomeMobileLayoutService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

class ManageMobileHomeShared extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithMobileHomeLayout;

    protected static ?string $navigationIcon = 'heroicon-o-squares-plus';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.manage-mobile-home-audience';

    public string $previewRole = 'guest';

    public static function getNavigationLabel(): string
    {
        return 'مشترك والزائر';
    }

    public function getTitle(): string
    {
        return 'محتوى مشترك والزائر';
    }

    public function getHeading(): string
    {
        return '';
    }

    public function mount(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();
        $this->form->fill($this->loadFormState());
    }

    /** @return array<string, mixed> */
    protected function loadFormState(): array
    {
        return array_merge($this->sharedSettingsState(), [
            'sections' => $this->sectionsForRole('guest'),
            'product_types' => $this->productTypesState(),
            'highlights' => $this->highlightsState(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('shared')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('الزائر')
                            ->icon('heroicon-o-user')
                            ->schema([
                                Forms\Components\Placeholder::make('guest_hint')
                                    ->label('')
                                    ->content('ترتيب رئيسية الزائر (بدون محفظة/سمعة). الأقسام المشتركة تظهر أيضاً للسباك والتجار حسب إعداداتهم.'),
                                $this->audienceSectionsRepeater('sections', 'أقسام الزائر', []),
                            ]),
                        Forms\Components\Tabs\Tab::make('البانر والعناوين')
                            ->icon('heroicon-o-sparkles')
                            ->schema([
                                Forms\Components\Toggle::make('banner_is_active')
                                    ->label('إظهار البانر الأخضر')
                                    ->inline(false)
                                    ->live(),
                                Forms\Components\TextInput::make('banner_title')->label('عنوان البانر')->required()->maxLength(120)->live(onBlur: true),
                                Forms\Components\TextInput::make('banner_subtitle')->label('نص فرعي')->maxLength(200)->live(onBlur: true),
                                Forms\Components\TextInput::make('banner_cta_text')->label('نص الزر')->maxLength(80)->live(onBlur: true),
                                Forms\Components\TextInput::make('banner_cta_link')->label('رابط الزر')->maxLength(255),
                                Forms\Components\FileUpload::make('banner_image')
                                    ->label('صورة البانر')
                                    ->disk('public')
                                    ->directory('home-mobile')
                                    ->image()
                                    ->imageEditor()
                                    ->maxSize(4096),
                                Forms\Components\TextInput::make('main_sections_title')->label('عنوان الأقسام الرئيسية')->required()->live(onBlur: true),
                                Forms\Components\TextInput::make('new_products_title')->label('عنوان جديد منتجات MG')->required()->live(onBlur: true),
                                Forms\Components\TextInput::make('stores_title')->label('عنوان المتاجر')->required()->live(onBlur: true),
                            ])
                            ->columns(2),
                        Forms\Components\Tabs\Tab::make('أنواع القطع')
                            ->icon('heroicon-o-squares-2x2')
                            ->schema([
                                Forms\Components\Repeater::make('product_types')
                                    ->label('بطاقات الأقسام الرئيسية')
                                    ->collapsible()
                                    ->cloneable()
                                    ->reorderable()
                                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? $state['key'] ?? null)
                                    ->schema([
                                        Forms\Components\Hidden::make('id'),
                                        Forms\Components\TextInput::make('key')->label('المفتاح')->required()->maxLength(64),
                                        Forms\Components\TextInput::make('name')->label('الاسم')->required()->live(onBlur: true),
                                        Forms\Components\TextInput::make('subtitle')->label('فرعي')->live(onBlur: true),
                                        Forms\Components\Select::make('icon')->label('أيقونة')->options([
                                            'pipe' => 'ماسورة', 'tee' => 'تيه', 'valve' => 'محبس', 'elbow' => 'كوع', 'cap' => 'سداد',
                                        ])->native(false),
                                        Forms\Components\FileUpload::make('image')->label('صورة')->disk('public')->directory('product-types')->image(),
                                        Forms\Components\Textarea::make('keywords')->label('كلمات مطابقة')->rows(2),
                                        Forms\Components\Textarea::make('exclude_keywords')->label('استبعاد')->rows(2),
                                        Forms\Components\TextInput::make('sort_order')->label('ترتيب')->numeric()->default(0),
                                        Forms\Components\Toggle::make('is_active')->label('ظاهر')->default(true),
                                    ])
                                    ->columns(2)
                                    ->columnSpanFull(),
                            ]),
                        Forms\Components\Tabs\Tab::make('أيقونات البانر')
                            ->icon('heroicon-o-bolt')
                            ->schema([
                                Forms\Components\Repeater::make('highlights')
                                    ->label('أيقونات تحت البانر الأخضر')
                                    ->collapsible()
                                    ->cloneable()
                                    ->reorderable()
                                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? $state['key'] ?? null)
                                    ->schema([
                                        Forms\Components\Hidden::make('id'),
                                        Forms\Components\TextInput::make('key')->label('المفتاح')->required(),
                                        Forms\Components\TextInput::make('label')->label('الاسم')->required()->live(onBlur: true),
                                        Forms\Components\Select::make('icon')->label('أيقونة')->options([
                                            'projects' => 'مشاريع', 'sewage' => 'صرف', 'water' => 'مياه',
                                        ])->native(false),
                                        Forms\Components\TextInput::make('deep_link')->label('Deep link'),
                                        Forms\Components\TextInput::make('link')->label('رابط'),
                                        Forms\Components\Textarea::make('category_match')->label('مطابقة تصنيف')->rows(2),
                                        Forms\Components\Textarea::make('category_prefer')->label('تفضيل')->rows(2),
                                        Forms\Components\Textarea::make('category_exclude')->label('استبعاد')->rows(2),
                                        Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
                                        Forms\Components\Toggle::make('is_active')->default(true),
                                    ])
                                    ->columns(2)
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->persistTabInQueryString('sharedTab')
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $this->saveSectionsForRole('guest', array_values($data['sections'] ?? []));
        $this->saveSharedSettings($data);
        $this->saveProductTypes(array_values($data['product_types'] ?? []));
        $this->saveHighlights(array_values($data['highlights'] ?? []));
        $this->bumpAndNotify('تم حفظ المحتوى المشترك');
        $this->form->fill($this->loadFormState());
    }

    /** @return array<string, mixed> */
    public function previewState(): array
    {
        return $this->previewStateFor('guest');
    }

    /** @return array<string, string> */
    public function audienceRoleLabels(): array
    {
        return ['guest' => 'زائر'];
    }

    public function studioTitle(): string
    {
        return 'مشترك والزائر';
    }

    public function studioLead(): string
    {
        return 'البانر الأخضر، أنواع القطع، أيقونات البانر، وترتيب شاشة الزائر.';
    }
}
