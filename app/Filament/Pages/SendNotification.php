<?php

namespace App\Filament\Pages;

use App\Models\City;
use App\Models\NotificationBroadcast;
use App\Models\PushToken;
use App\Models\User;
use App\Services\AdminNotificationService;
use App\Services\FcmPushService;
use App\Support\AdminPermissions;
use App\Support\UserRoles;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Schema;

class SendNotification extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?int $navigationSort = 1;

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
        return 'الإشعارات';
    }

    public static function getNavigationLabel(): string
    {
        return 'إرسال إشعار';
    }

    public function getTitle(): string
    {
        return 'إرسال إشعار للمستخدمين';
    }

    public function getHeading(): string
    {
        return 'إرسال إشعار';
    }

    public function getSubheading(): ?string
    {
        $diag = $this->pushDiagnostics();

        return 'صندوق الوارد + Push FCM. '.$diag['summary'];
    }

    public function mount(): void
    {
        $this->form->fill([
            'audience' => 'plumbers',
            'status' => 'info',
            'send_push' => true,
            'push_type' => 'home',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('المستلمون')
                    ->description('اختر مجموعة جاهزة أو مستخدمين محددين. يظهر العدد المتوقع أسفل النموذج.')
                    ->schema([
                        Forms\Components\Select::make('audience')
                            ->label('إرسال إلى')
                            ->options([
                                'plumbers' => 'كل السباكين النشطين',
                                'retailers' => 'كل تجار القطاعي النشطين',
                                'wholesalers' => 'كل موزعي الجملة النشطين',
                                'role' => 'دور محدد',
                                'users' => 'مجموعة مستخدمين (اختيار يدوي)',
                                'user' => 'مستخدم واحد',
                                'all' => 'جميع المستخدمين النشطين',
                            ])
                            ->default('plumbers')
                            ->live()
                            ->native(false)
                            ->required()
                            ->columnSpanFull(),

                        Forms\Components\Select::make('role')
                            ->label('الدور')
                            ->options(UserRoles::selectOptions())
                            ->visible(fn (Get $get) => $get('audience') === 'role')
                            ->required(fn (Get $get) => $get('audience') === 'role')
                            ->native(false)
                            ->live(),

                        Forms\Components\Select::make('user_id')
                            ->label('المستخدم')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => $this->searchUsers($search))
                            ->getOptionLabelUsing(fn ($value) => $this->userLabel((int) $value))
                            ->visible(fn (Get $get) => $get('audience') === 'user')
                            ->required(fn (Get $get) => $get('audience') === 'user'),

                        Forms\Components\Select::make('user_ids')
                            ->label('المستخدمون')
                            ->multiple()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => $this->searchUsers($search))
                            ->getOptionLabelsUsing(function (array $values): array {
                                return collect($values)
                                    ->mapWithKeys(fn ($id) => [(int) $id => $this->userLabel((int) $id)])
                                    ->all();
                            })
                            ->visible(fn (Get $get) => $get('audience') === 'users')
                            ->required(fn (Get $get) => $get('audience') === 'users')
                            ->columnSpanFull(),

                        Forms\Components\Select::make('city_id')
                            ->label('تصفية حسب المدينة (اختياري)')
                            ->options(fn () => City::query()->orderBy('name_ar')->pluck('name_ar', 'id'))
                            ->searchable()
                            ->native(false)
                            ->visible(fn (Get $get) => in_array($get('audience'), ['plumbers', 'retailers', 'wholesalers', 'role', 'all'], true))
                            ->live(),

                        Forms\Components\Placeholder::make('preview_count')
                            ->label('عدد المستلمين المتوقع')
                            ->content(fn (Get $get) => number_format($this->previewCount($get)).' مستخدم'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('محتوى الإشعار')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('العنوان')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('body')
                            ->label('المحتوى')
                            ->rows(5)
                            ->maxLength(2000)
                            ->columnSpanFull(),

                        Forms\Components\Select::make('status')
                            ->label('نوع التنبيه')
                            ->options([
                                'info' => 'معلومة',
                                'success' => 'نجاح',
                                'warning' => 'تحذير',
                                'danger' => 'تنبيه هام',
                            ])
                            ->default('info')
                            ->native(false),

                        Forms\Components\Toggle::make('send_push')
                            ->label('إرسال Push للجوال (FCM)')
                            ->default(true)
                            ->helperText('يصل أيضًا لصندوق إشعارات التطبيق داخل الموبايل.'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('فتح شاشة في التطبيق (اختياري)')
                    ->collapsed()
                    ->schema([
                        Forms\Components\Select::make('push_type')
                            ->label('نوع الصفحة')
                            ->options([
                                'home' => 'الرئيسية',
                                'order' => 'طلب',
                                'invoice' => 'فاتورة',
                                'points' => 'النقاط / المحفظة',
                                'return' => 'مرتجعات',
                                'withdrawal' => 'سحب',
                                'promotion' => 'عرض ترويجي',
                                'collection' => 'مجموعة منتجات',
                                'product' => 'منتج',
                                'shop' => 'متجر',
                                'plumber' => 'سباك',
                            ])
                            ->native(false)
                            ->default('home'),

                        Forms\Components\TextInput::make('push_id')
                            ->label('معرّف العنصر (id)')
                            ->helperText('مثال: رقم الطلب أو المنتج — يُترك فارغًا للرئيسية/النقاط.')
                            ->maxLength(80),

                        Forms\Components\TextInput::make('url')
                            ->label('رابط ويب (اختياري للوحة)')
                            ->url()
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function send(): void
    {
        $data = $this->form->getState();
        $audience = (string) $data['audience'];
        $userIds = match ($audience) {
            'user' => [(int) $data['user_id']],
            'users' => array_map('intval', $data['user_ids'] ?? []),
            default => [],
        };

        $count = AdminNotificationService::previewCount(
            $audience,
            $data['role'] ?? null,
            $userIds,
            isset($data['city_id']) ? (int) $data['city_id'] : null,
        );

        if ($count < 1) {
            Notification::make()
                ->title('لا يوجد مستلمون')
                ->body('عدّل المجموعة أو أزل فلتر المدينة.')
                ->warning()
                ->send();

            return;
        }

        $sendPush = (bool) ($data['send_push'] ?? true);
        $diag = $this->pushDiagnostics();

        $broadcast = AdminNotificationService::broadcast([
            'audience' => $audience,
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'status' => $data['status'] ?? 'info',
            'url' => $data['url'] ?? null,
            'role' => $data['role'] ?? null,
            'user_ids' => $userIds,
            'city_id' => isset($data['city_id']) ? (int) $data['city_id'] : null,
            'push_type' => $data['push_type'] ?? null,
            'push_id' => $data['push_id'] ?? null,
            'send_push' => $sendPush,
            'sent_by' => auth()->id(),
        ]);

        $body = "وصل لصندوق الوارد: {$broadcast->recipients_count} مستخدم — مكتبة #{$broadcast->id}.";
        if ($sendPush) {
            if (! $diag['fcm_ready']) {
                $body .= ' ⚠️ FCM غير جاهز على السيرفر (ملف الاعتمادات) — لن يصل Push.';
            } elseif ($diag['tokens'] < 1) {
                $body .= ' ⚠️ لا يوجد أي FCM token محفوظ — التطبيق لازم يستدعي POST /mobile/devices/push-token بعد الدخول.';
            } else {
                $body .= " Push في الطابور ({$diag['queue']}) — شغّل: php artisan queue:work --queue={$diag['queue']}";
            }
        }

        $warn = $sendPush && (! $diag['fcm_ready'] || $diag['tokens'] < 1);
        $toast = Notification::make()
            ->title($warn ? 'الإشعار وصل للوارد فقط' : 'تم إرسال الإشعار')
            ->body($body)
            ->persistent();
        ($warn ? $toast->warning() : $toast->success())->send();

        $this->form->fill([
            'audience' => $audience,
            'role' => $data['role'] ?? null,
            'city_id' => $data['city_id'] ?? null,
            'user_id' => null,
            'user_ids' => [],
            'title' => '',
            'body' => '',
            'status' => $data['status'] ?? 'info',
            'send_push' => (bool) ($data['send_push'] ?? true),
            'push_type' => $data['push_type'] ?? 'home',
            'push_id' => null,
            'url' => null,
        ]);
    }

    protected function previewCount(Get $get): int
    {
        $audience = (string) ($get('audience') ?: 'plumbers');
        $userIds = match ($audience) {
            'user' => $get('user_id') ? [(int) $get('user_id')] : [],
            'users' => array_map('intval', $get('user_ids') ?? []),
            default => [],
        };

        return AdminNotificationService::previewCount(
            $audience,
            $get('role'),
            $userIds,
            $get('city_id') ? (int) $get('city_id') : null,
        );
    }

    /** @return array<int, string> */
    protected function searchUsers(string $search): array
    {
        return User::query()
            ->where('is_active', true)
            ->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('brand_name', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->limit(40)
            ->get()
            ->mapWithKeys(fn (User $u) => [$u->id => $this->userLabel($u->id, $u)])
            ->all();
    }

    protected function userLabel(int $id, ?User $user = null): string
    {
        $user ??= User::query()->find($id);
        if (! $user) {
            return '#'.$id;
        }

        $role = UserRoles::label($user->role);

        return trim(($user->brand_name ?: $user->name).' · '.($user->phone ?: '')." ({$role})");
    }

    /**
     * @return array{fcm_ready: bool, tokens: int, queue: string, table: bool, summary: string}
     */
    public function pushDiagnostics(): array
    {
        $table = Schema::hasTable('push_tokens');
        $tokens = $table ? (int) PushToken::query()->count() : 0;
        $fcmReady = app(FcmPushService::class)->enabled();
        $queue = (string) config('notifications.queue', 'default');

        $parts = [];
        $parts[] = $fcmReady ? 'FCM جاهز' : 'FCM غير مُعدّ (FIREBASE_CREDENTIALS)';
        $parts[] = $table ? "توكنات محفوظة: {$tokens}" : 'جدول push_tokens مفقود — php artisan fcm:ensure-table';
        $parts[] = "طابور: {$queue}";

        return [
            'fcm_ready' => $fcmReady,
            'tokens' => $tokens,
            'queue' => $queue,
            'table' => $table,
            'summary' => implode(' · ', $parts),
        ];
    }
}
