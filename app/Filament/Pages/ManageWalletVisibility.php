<?php

namespace App\Filament\Pages;

use App\Http\Controllers\Api\Ios\IosWalletVisibilityController;
use App\Models\AppFlag;
use App\Support\AdminPermissions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ManageWalletVisibility extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?int $navigationSort = 45;

    protected static string $view = 'filament.pages.manage-wallet-visibility';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin()
            || $user?->canAdminPermission(AdminPermissions::USERS_MANAGE)
            || in_array($user?->role, ['super_admin', 'admin'], true);
    }

    public static function getNavigationGroup(): ?string
    {
        return 'الإعدادات';
    }

    public static function getNavigationLabel(): string
    {
        return 'إظهار المحفظة';
    }

    public function getTitle(): string
    {
        return 'تشغيل / إيقاف المحفظة في التطبيق';
    }

    public function getSubheading(): ?string
    {
        return 'يتحكم في endpoint ظهور المحفظة للتطبيقات (iOS / Android)';
    }

    public function mount(): void
    {
        $this->form->fill([
            'show_wallet' => AppFlag::getBool(IosWalletVisibilityController::FLAG_KEY, true),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Wallet Visibility')
                    ->description('عند الإيقاف: التطبيقات تخفي تبويب المحفظة بعد قراءة GET /api/ios/wallet-visibility')
                    ->icon('heroicon-o-device-phone-mobile')
                    ->schema([
                        Forms\Components\Toggle::make('show_wallet')
                            ->label('إظهار المحفظة في التطبيق')
                            ->helperText('On = ظاهرة · Off = مخفية')
                            ->onColor('success')
                            ->offColor('danger')
                            ->inline(false)
                            ->live()
                            ->afterStateUpdated(function (?bool $state): void {
                                $this->persist((bool) $state);
                            }),

                        Forms\Components\Placeholder::make('api_hint')
                            ->label('Endpoints')
                            ->content('قراءة عامة: GET /api/ios/wallet-visibility أو GET /api/v1/mobile/wallet-visibility — نفس القيمة المحفوظة هنا.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $this->persist((bool) ($state['show_wallet'] ?? false));
    }

    protected function persist(bool $enabled): void
    {
        AppFlag::setBool(
            IosWalletVisibilityController::FLAG_KEY,
            $enabled,
            auth()->id(),
        );

        Notification::make()
            ->title($enabled ? 'المحفظة ظاهرة في التطبيق ✓' : 'المحفظة مخفية من التطبيق')
            ->body($enabled
                ? 'show_wallet = true'
                : 'show_wallet = false')
            ->{$enabled ? 'success' : 'warning'}()
            ->send();
    }
}
