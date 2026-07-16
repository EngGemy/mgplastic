<?php

namespace App\Filament\Widgets;

use App\Http\Controllers\Api\Ios\IosWalletVisibilityController;
use App\Models\AppFlag;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;

class GeneralControlsWidget extends Widget
{
    protected static string $view = 'filament.widgets.general-controls';

    protected static bool $isDiscovered = false;

    protected static ?int $sort = -150;

    protected int|string|array $columnSpan = 'full';

    public bool $showWallet = true;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role, ['super_admin', 'admin'], true)
            && filament()->getCurrentPanel()?->getId() === 'admin';
    }

    public function mount(): void
    {
        $this->showWallet = AppFlag::getBool(IosWalletVisibilityController::FLAG_KEY, true);
    }

    public function toggleWallet(): void
    {
        $this->showWallet = ! $this->showWallet;

        AppFlag::setBool(
            IosWalletVisibilityController::FLAG_KEY,
            $this->showWallet,
            auth()->id(),
        );

        Notification::make()
            ->title($this->showWallet ? 'المحفظة ظاهرة في التطبيق ✓' : 'المحفظة مخفية من التطبيق')
            ->body($this->showWallet ? 'show_wallet = true' : 'show_wallet = false')
            ->{$this->showWallet ? 'success' : 'warning'}()
            ->send();
    }

    public function getViewData(): array
    {
        return [
            'showWallet' => $this->showWallet,
            'flagKey' => IosWalletVisibilityController::FLAG_KEY,
        ];
    }
}
