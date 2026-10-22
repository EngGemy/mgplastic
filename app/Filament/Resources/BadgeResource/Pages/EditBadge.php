<?php

namespace App\Filament\Resources\BadgeResource\Pages;

use App\Filament\Resources\BadgeResource;
use App\Models\User;
use App\Services\Reputation\BadgeService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Builder;

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
        return 'اضبط الهوية والمظهر والمعيار بالتفصيل — ثم امنحها يدوياً من التبويب أو الزر أعلاه.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('grant')
                ->label('منح لسباك الآن')
                ->icon('heroicon-o-gift')
                ->color('success')
                ->form([
                    Forms\Components\Select::make('user_id')
                        ->label('السباك')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->getSearchResultsUsing(function (string $search) {
                            return User::query()
                                ->where('role', User::ROLE_PLUMBER)
                                ->where(function (Builder $q) use ($search) {
                                    $q->where('name', 'like', "%{$search}%")
                                        ->orWhere('phone', 'like', "%{$search}%");
                                })
                                ->orderBy('name')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (User $u) => [
                                    $u->id => trim($u->name.' · '.($u->phone ?: '#'.$u->id)),
                                ])
                                ->all();
                        })
                        ->getOptionLabelUsing(fn ($value) => optional(User::find($value))->name),
                    Forms\Components\Toggle::make('notify')
                        ->label('إشعار واتساب')
                        ->default(true),
                ])
                ->action(function (array $data) {
                    $plumber = User::query()->findOrFail((int) $data['user_id']);
                    $granted = app(BadgeService::class)->grant(
                        $plumber,
                        $this->record,
                        notify: (bool) ($data['notify'] ?? true),
                    );

                    if (! $granted) {
                        Notification::make()
                            ->warning()
                            ->title('لم تُمنح')
                            ->body('السباك يملك الشارة مسبقاً أو ليس سباكاً.')
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('تم منح الشارة')
                        ->body($this->record->name_ar.' ← '.$plumber->name)
                        ->send();
                }),
            Actions\DeleteAction::make()->label('حذف الشارة'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $state = $this->form->getRawState();
        $type = (string) ($state['rule_type'] ?? 'manual');
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
