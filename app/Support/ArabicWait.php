<?php

namespace App\Support;

/** Human-readable Arabic wait copy for rate limits. */
class ArabicWait
{
    public static function message(int $seconds, string $action = 'المحاولة'): string
    {
        $seconds = max(1, $seconds);

        if ($seconds < 60) {
            return "طلبات كثيرة — انتظر {$seconds} ثانية قبل {$action}";
        }

        $minutes = (int) ceil($seconds / 60);
        if ($minutes < 60) {
            $label = $minutes === 1 ? 'دقيقة' : ($minutes === 2 ? 'دقيقتين' : "{$minutes} دقائق");

            return "طلبات كثيرة — انتظر {$label} قبل {$action}";
        }

        $hours = (int) ceil($minutes / 60);
        $label = $hours === 1 ? 'ساعة' : ($hours === 2 ? 'ساعتين' : "{$hours} ساعات");

        return "طلبات كثيرة — انتظر {$label} قبل {$action}";
    }
}
