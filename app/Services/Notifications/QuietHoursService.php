<?php

namespace App\Services\Notifications;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

final class QuietHoursService
{
    /**
     * If the user is inside quiet hours, return the next quiet_to datetime (send-after).
     * Returns null when quiet hours do not apply (disabled, incomplete, or not currently quiet).
     */
    public function delayUntil(User $user, CarbonInterface $now = null): ?Carbon
    {
        if (! (bool) config('notifications.quiet_hours.enabled', true)) {
            return null;
        }

        $from = $user->quiet_from;
        $to = $user->quiet_to;

        if (! $from || ! $to) {
            return null;
        }

        $tz = $user->timezone
            ?: (string) config('notifications.quiet_hours.default_timezone', 'Africa/Tripoli');

        try {
            $localNow = Carbon::parse($now ?? now())->timezone($tz);
        } catch (\Throwable) {
            $localNow = Carbon::parse($now ?? now());
            $tz = config('app.timezone', 'UTC');
        }

        $fromTime = $this->parseTime((string) $from);
        $toTime = $this->parseTime((string) $to);

        if ($fromTime === null || $toTime === null) {
            return null;
        }

        $fromToday = $localNow->copy()->setTime($fromTime[0], $fromTime[1], $fromTime[2] ?? 0);
        $toToday = $localNow->copy()->setTime($toTime[0], $toTime[1], $toTime[2] ?? 0);

        // Overnight window (e.g. 22:00 → 07:00)
        if ($fromToday->greaterThanOrEqualTo($toToday)) {
            $inQuiet = $localNow->greaterThanOrEqualTo($fromToday) || $localNow->lessThan($toToday);
            if (! $inQuiet) {
                return null;
            }

            $until = $localNow->lessThan($toToday)
                ? $toToday
                : $toToday->copy()->addDay();

            return $until->timezone(config('app.timezone', 'UTC'));
        }

        // Same-day window (e.g. 13:00 → 15:00)
        if ($localNow->betweenIncluded($fromToday, $toToday->copy()->subSecond())) {
            return $toToday->timezone(config('app.timezone', 'UTC'));
        }

        return null;
    }

    /**
     * @return array{0:int,1:int,2?:int}|null
     */
    private function parseTime(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Carbon/Eloquent may cast time as "H:i:s" or DateTime
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', $value, $m)) {
            return [(int) $m[1], (int) $m[2], isset($m[3]) ? (int) $m[3] : 0];
        }

        try {
            $c = Carbon::parse($value);

            return [(int) $c->hour, (int) $c->minute, (int) $c->second];
        } catch (\Throwable) {
            return null;
        }
    }
}
