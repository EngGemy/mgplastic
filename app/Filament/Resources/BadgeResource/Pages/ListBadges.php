<?php

namespace App\Filament\Resources\BadgeResource\Pages;

use App\Filament\Resources\BadgeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBadges extends ListRecords
{
    protected static string $resource = BadgeResource::class;

    protected static string $view = 'filament.resources.badges.list';

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
        return 'كل شارة لها معيار فوز تلقائي — عرّف الخطة هنا.';
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
