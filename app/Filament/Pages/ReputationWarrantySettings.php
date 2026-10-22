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

    public function getHeading(): string
    {
        return 'إعدادات السمعة والضمان';
    }

    public function getSubheading(): ?string
    {
        return 'عتبات إصدار الضمان ونصوص الشروط — مفاتيح التفعيل العامة من ملف البيئة.';
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
                Forms\Components\Section::make('حالة الأنظمة')
                    ->description('التفعيل العام يُضبط من السيرفر (.env) — هنا للعرض فقط.')
                    ->icon('heroicon-o-power')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('reputation_enabled')
                            ->label('السمعة مفعّلة')
                            ->disabled()
                            ->helperText('REPUTATION_ENABLED'),
                        Forms\Components\Toggle::make('warranty_enabled')
                            ->label('الضمان مفعّل')
                            ->disabled()
                            ->helperText('WARRANTY_ENABLED'),
                    ]),

                Forms\Components\Section::make('شروط إصدار الضمان')
                    ->icon('heroicon-o-shield-check')
                    ->columns(12)
                    ->schema([
                        Forms\Components\Select::make('min_level')
                            ->label('أدنى مستوى سمعة')
                            ->native(false)
                            ->options([
                                'bronze' => 'برونزي',
                                'silver' => 'فضي',
                                'gold' => 'ذهبي',
                                'diamond' => 'ماسي',
                            ])
                            ->columnSpan(3),
                        Forms\Components\TextInput::make('min_score')
                            ->label('أدنى درجة')
                            ->numeric()
                            ->columnSpan(3),
                        Forms\Components\TextInput::make('min_distributions')
                            ->label('أدنى توزيعات مؤكدة')
                            ->numeric()
                            ->columnSpan(3),
                        Forms\Components\TextInput::make('default_months')
                            ->label('مدة الضمان (أشهر)')
                            ->numeric()
                            ->columnSpan(3),
                        Forms\Components\Textarea::make('terms_ar')
                            ->label('نص الشروط بالعربية')
                            ->rows(6)
                            ->extraAttributes(['dir' => 'rtl'])
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('أوزان درجة السمعة')
                    ->description('للمرجع فقط — التعديل من config/reputation.php')
                    ->icon('heroicon-o-scale')
                    ->collapsed()
                    ->schema([
                        Forms\Components\KeyValue::make('weights')
                            ->label('الأوزان')
                            ->disabled(),
                    ]),
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
