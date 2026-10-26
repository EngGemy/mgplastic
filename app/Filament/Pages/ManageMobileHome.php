<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * Legacy entry — redirects to the split audience pages.
 */
class ManageMobileHome extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.manage-mobile-home-redirect';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return 'رئيسية التطبيق';
    }

    public static function getNavigationLabel(): string
    {
        return 'رئيسية التطبيق';
    }

    public function mount(): void
    {
        $this->redirect(ManageMobileHomePlumbers::getUrl(), navigate: true);
    }

    public function getTitle(): string
    {
        return 'رئيسية التطبيق';
    }
}
