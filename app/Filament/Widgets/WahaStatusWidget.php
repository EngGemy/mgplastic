<?php

namespace App\Filament\Widgets;

use App\Services\WhatsAppNotifier;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

class WahaStatusWidget extends Widget
{
    protected static string $view = 'filament.widgets.waha-status';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role, ['super_admin', 'admin', 'monitor'], true);
    }

    /** @return array{ok: ?bool, label: string, reason: string, since: ?string} */
    public function getStatus(): array
    {
        return Cache::remember('filament:waha_status_badge', 30, function () {
            $snap = app(WhatsAppNotifier::class)->cachedHealthSnapshot();
            $ok = $snap['ok'] ?? null;
            $reason = (string) ($snap['reason'] ?? 'unknown');
            $since = $snap['breaker_opened_at'] ?? $snap['checked_at'] ?? null;

            if ($ok === true) {
                return ['ok' => true, 'label' => 'WAHA OK', 'reason' => $reason, 'since' => $since];
            }
            if ($ok === false) {
                return ['ok' => false, 'label' => 'WAHA DOWN', 'reason' => $reason, 'since' => $since];
            }

            return ['ok' => null, 'label' => 'WAHA ?', 'reason' => 'no health check yet — run php artisan whatsapp:health', 'since' => null];
        });
    }
}
