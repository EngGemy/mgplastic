<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\InteractsWithMobileHomeLayout;
use App\Services\HomeMobileLayoutService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

class ManageMobileHomeTraders extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithMobileHomeLayout;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.manage-mobile-home-audience';

    public string $previewRole = 'retail_trader';

    public static function getNavigationLabel(): string
    {
        return 'رئيسية التجار';
    }

    public function getTitle(): string
    {
        return 'رئيسية التجار';
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
        return [
            'sections_retail' => $this->sectionsForRole('retail_trader'),
            'sections_wholesale' => $this->sectionsForRole('wholesale_distributor'),
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('traders')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('تاجر قطاعي')
                            ->icon('heroicon-o-shopping-bag')
                            ->schema([
                                Forms\Components\Placeholder::make('retail_hint')
                                    ->label('')
                                    ->content('ترتيب رئيسية القطاعي + لوحة التحكم (سباكيني، طلب جملة، …).'),
                                $this->audienceSectionsRepeater('sections_retail', 'أقسام القطاعي', ['shop_quick_links']),
                            ]),
                        Forms\Components\Tabs\Tab::make('موزع جملة')
                            ->icon('heroicon-o-truck')
                            ->schema([
                                Forms\Components\Placeholder::make('wholesale_hint')
                                    ->label('')
                                    ->content('ترتيب رئيسية الجملة + لوحة التحكم (القطاعيين، طلبات المصنع، …).'),
                                $this->audienceSectionsRepeater('sections_wholesale', 'أقسام الجملة', ['shop_quick_links']),
                            ]),
                    ])
                    ->persistTabInQueryString('traderTab')
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function setPreviewRole(string $role): void
    {
        if (in_array($role, ['retail_trader', 'wholesale_distributor'], true)) {
            $this->previewRole = $role;
        }
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $this->saveSectionsForRole('retail_trader', array_values($data['sections_retail'] ?? []));
        $this->saveSectionsForRole('wholesale_distributor', array_values($data['sections_wholesale'] ?? []));
        $this->bumpAndNotify('تم حفظ رئيسية التجار');
        $this->form->fill($this->loadFormState());
    }

    /** @return array<string, mixed> */
    public function previewState(): array
    {
        $path = $this->previewRole === 'wholesale_distributor'
            ? 'sections_wholesale'
            : 'sections_retail';

        return $this->previewStateFor($this->previewRole, $path);
    }

    /** @return array<string, string> */
    public function audienceRoleLabels(): array
    {
        return [
            'retail_trader' => 'قطاعي',
            'wholesale_distributor' => 'جملة',
        ];
    }

    public function studioTitle(): string
    {
        return 'رئيسية التجار';
    }

    public function studioLead(): string
    {
        return 'قطاعي وجملة منفصلان بتبويبات — كل واحد بترتيبه ولوحة تحكمه.';
    }
}
