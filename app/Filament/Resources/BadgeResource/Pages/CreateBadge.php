<?php

namespace App\Filament\Resources\BadgeResource\Pages;

use App\Filament\Resources\BadgeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBadge extends CreateRecord
{
    protected static string $resource = BadgeResource::class;

    public function getTitle(): string
    {
        return 'إنشاء شارة جديدة';
    }

    public function getHeading(): string
    {
        return 'إنشاء شارة جديدة';
    }

    public function getSubheading(): ?string
    {
        return 'عرّف الشارة بالكامل (اسم، وصف، ندرة، معيار) ثم احفظها — بعدها تمنحها يدوياً أو تتركها تلقائية.';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['rule'] = $this->composeRule();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }

    /** @return array<string, mixed> */
    protected function composeRule(): array
    {
        $state = $this->form->getRawState();
        $type = (string) ($state['rule_type'] ?? 'manual');
        $rule = ['type' => $type];

        if ($type === 'level_min') {
            $rule['min'] = (string) ($state['rule_level'] ?? 'gold');
        } elseif (BadgeResource::ruleNeedsNumericMin($type)) {
            $rule['min'] = (int) ($state['rule_min'] ?? 1);
        }

        return $rule;
    }
}
