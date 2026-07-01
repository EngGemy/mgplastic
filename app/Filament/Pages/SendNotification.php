<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\AdminNotificationService;
use App\Support\AdminPermissions;
use App\Support\UserRoles;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SendNotification extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?int $navigationSort = 12;

    protected static string $view = 'filament.pages.send-notification';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin()
            || $user?->canAdminPermission(AdminPermissions::USERS_MANAGE);
    }

    public static function getNavigationGroup(): ?string
    {
        return 'المستخدمون';
    }

    public static function getNavigationLabel(): string
    {
        return 'إرسال إشعار';
    }

    public function getTitle(): string
    {
        return 'إرسال إشعار للمستخدمين';
    }

    public function mount(): void
    {
        $this->form->fill([
            'recipient_type' => 'user',
            'status' => 'info',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('المستلمون')
                    ->schema([
                        Forms\Components\Select::make('recipient_type')
                            ->label('إرسال إلى')
                            ->options([
                                'user' => 'مستخدم محدد',
                                'role' => 'دور محدد',
                                'all' => 'جميع المستخدمين',
                            ])
                            ->default('user')
                            ->live()
                            ->native(false)
                            ->required(),

                        Forms\Components\Select::make('user_id')
                            ->label('المستخدم')
                            ->options(fn () => User::query()
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->visible(fn (Forms\Get $get) => $get('recipient_type') === 'user')
                            ->required(fn (Forms\Get $get) => $get('recipient_type') === 'user'),

                        Forms\Components\Select::make('role')
                            ->label('الدور')
                            ->options(UserRoles::selectOptions())
                            ->visible(fn (Forms\Get $get) => $get('recipient_type') === 'role')
                            ->required(fn (Forms\Get $get) => $get('recipient_type') === 'role')
                            ->native(false),
                    ]),

                Forms\Components\Section::make('محتوى الإشعار')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('العنوان')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Textarea::make('body')
                            ->label('المحتوى')
                            ->rows(5)
                            ->maxLength(2000),

                        Forms\Components\Select::make('status')
                            ->label('النوع')
                            ->options([
                                'info' => 'معلومة',
                                'success' => 'نجاح',
                                'warning' => 'تحذير',
                                'danger' => 'تنبيه هام',
                            ])
                            ->default('info')
                            ->native(false),

                        Forms\Components\TextInput::make('url')
                            ->label('رابط (اختياري)')
                            ->url()
                            ->maxLength(500),
                    ]),
            ])
            ->statePath('data');
    }

    public function send(): void
    {
        $data = $this->form->getState();

        $count = match ($data['recipient_type']) {
            'all' => AdminNotificationService::sendToAll(
                $data['title'],
                $data['body'] ?? null,
                $data['status'] ?? 'info',
                $data['url'] ?? null,
            ),
            'role' => AdminNotificationService::sendToRole(
                $data['role'],
                $data['title'],
                $data['body'] ?? null,
                $data['status'] ?? 'info',
                $data['url'] ?? null,
            ),
            default => AdminNotificationService::sendToMany(
                User::query()->whereKey($data['user_id'])->get(),
                $data['title'],
                $data['body'] ?? null,
                $data['status'] ?? 'info',
                $data['url'] ?? null,
            ),
        };

        Notification::make()
            ->title('تم إرسال الإشعار')
            ->body($count === 1 ? 'وصل الإشعار إلى مستخدم واحد' : "وصل الإشعار إلى {$count} مستخدم")
            ->success()
            ->send();

        $this->form->fill([
            'recipient_type' => $data['recipient_type'],
            'user_id' => $data['recipient_type'] === 'user' ? $data['user_id'] : null,
            'role' => $data['recipient_type'] === 'role' ? $data['role'] : null,
            'title' => '',
            'body' => '',
            'status' => $data['status'] ?? 'info',
            'url' => null,
        ]);
    }

}
