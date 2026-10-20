<?php

namespace App\Filament\Pages;

use App\Models\WarrantySetting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ReputationWarrantySettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $view = 'filament.pages.reputation-warranty-settings';

    protected static ?int $navigationSort = 41;

    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return 'نظام النقاط';
    }

    public static function getNavigationLabel(): string
    {
        return 'إعدادات السمعة والضمان';
    }

    public function getTitle(): string
    {
        return 'إعدادات السمعة والضمان';
    }

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin'], true);
    }

    public function mount(): void
    {
        $this->form->fill([
            'reputation_enabled' => config('reputation.enabled'),
            'warranty_enabled' => config('warranty.enabled'),
            'min_level' => WarrantySetting::get('min_level', config('warranty.min_level')),
            'min_score' => WarrantySetting::get('min_score', config('warranty.min_score')),
            'min_distributions' => WarrantySetting::get('min_distributions', config('warranty.min_distributions')),
            'default_months' => WarrantySetting::get('default_months', config('warranty.default_months')),
            'terms_ar' => WarrantySetting::get('terms_ar', config('warranty.terms_ar')),
            'weights' => config('reputation.weights'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Toggle::make('reputation_enabled')->label('تفعيل السمعة')->disabled()->helperText('يُضبط من .env: REPUTATION_ENABLED'),
                Forms\Components\Toggle::make('warranty_enabled')->label('تفعيل الضمان')->disabled()->helperText('يُضبط من .env: WARRANTY_ENABLED'),
                Forms\Components\Select::make('min_level')->label('أدنى مستوى للضمان')->options([
                    'bronze' => 'برونزي', 'silver' => 'فضي', 'gold' => 'ذهبي', 'diamond' => 'ماسي',
                ]),
                Forms\Components\TextInput::make('min_score')->label('أدنى درجة')->numeric(),
                Forms\Components\TextInput::make('min_distributions')->label('أدنى توزيعات مؤكدة')->numeric(),
                Forms\Components\TextInput::make('default_months')->label('مدة الضمان (أشهر)')->numeric(),
                Forms\Components\Textarea::make('terms_ar')->label('نص الشروط')->rows(5)->columnSpanFull(),
                Forms\Components\KeyValue::make('weights')->label('أوزان الدرجة (عرض فقط)')->disabled(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $d = $this->form->getState();
        foreach (['min_level', 'min_score', 'min_distributions', 'default_months', 'terms_ar'] as $key) {
            WarrantySetting::put($key, $d[$key] ?? null);
        }
        Notification::make()->success()->title('تم حفظ الإعدادات')->send();
    }
}
