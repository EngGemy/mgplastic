<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\InteractsWithMobileHomeLayout;
use App\Services\HomeMobileLayoutService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

class ManageMobileHomePlumbers extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithMobileHomeLayout;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.manage-mobile-home-audience';

    public string $previewRole = 'plumber';

    public static function getNavigationLabel(): string
    {
        return 'رئيسية السباكين';
    }

    public function getTitle(): string
    {
        return 'رئيسية السباكين';
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
            'sections' => $this->sectionsForRole('plumber'),
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Placeholder::make('hint')
                    ->label('')
                    ->content('هذه الشاشة للسباك فقط. اسحب لترتيب أقسامه، وعدّل أزراره السريعة من داخل قسم «أزرار سريعة».'),
                $this->audienceSectionsRepeater('sections', 'أقسام رئيسية السباك', ['quick_actions']),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $this->saveSectionsForRole('plumber', array_values($data['sections'] ?? []));
        $this->bumpAndNotify('تم حفظ رئيسية السباكين');
        $this->form->fill($this->loadFormState());
    }

    /** @return array<string, mixed> */
    public function previewState(): array
    {
        return $this->previewStateFor('plumber');
    }

    /** @return array<string, string> */
    public function audienceRoleLabels(): array
    {
        return ['plumber' => 'سباك'];
    }

    public function studioTitle(): string
    {
        return 'رئيسية السباكين';
    }

    public function studioLead(): string
    {
        return 'ترتيب وإظهار أقسام السباك وأزراره السريعة فقط — منفصل عن التجار.';
    }
}
