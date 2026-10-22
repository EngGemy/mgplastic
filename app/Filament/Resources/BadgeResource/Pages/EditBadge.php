<?php

namespace App\Filament\Resources\BadgeResource\Pages;

use App\Filament\Resources\BadgeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBadge extends EditRecord
{
    protected static string $resource = BadgeResource::class;

    public function getTitle(): string
    {
        return 'تعديل الشارة';
    }

    public function getHeading(): string
    {
        return 'تعديل: '.$this->record->name_ar;
    }

    public function getSubheading(): ?string
    {
        return 'المفتاح: '.$this->record->key.' — غيّر المظهر أو معيار الفوز دون كسر الشارات الممنوحة سابقًا.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('حذف الشارة'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $state = $this->form->getRawState();
        $type = (string) ($state['rule_type'] ?? 'orders_min');
        $rule = ['type' => $type];

        if ($type === 'level_min') {
            $rule['min'] = (string) ($state['rule_level'] ?? 'gold');
        } elseif (BadgeResource::ruleNeedsNumericMin($type)) {
            $rule['min'] = (int) ($state['rule_min'] ?? 1);
        }

        $data['rule'] = $rule;

        return $data;
    }
}
