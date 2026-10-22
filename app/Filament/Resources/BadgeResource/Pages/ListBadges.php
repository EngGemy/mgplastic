<?php

namespace App\Filament\Resources\BadgeResource\Pages;

use App\Filament\Resources\BadgeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBadges extends ListRecords
{
    protected static string $resource = BadgeResource::class;

    public function getTitle(): string
    {
        return 'شارات السباكين';
    }

    public function getHeading(): string
    {
        return 'شارات السباكين';
    }

    public function getSubheading(): ?string
    {
        return 'كتالوج الشارات التلقائية لنظام السمعة والنقاط.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('شارة جديدة')
                ->icon('heroicon-o-plus'),
        ];
    }
}
