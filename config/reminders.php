<?php

return [
    'enabled' => filter_var(env('REMINDERS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'admin_only' => filter_var(env('REMINDERS_ADMIN_ONLY', true), FILTER_VALIDATE_BOOLEAN),
    'per_hour' => max(1, (int) env('REMINDERS_PER_HOUR', 40)),
    'daily_limit' => max(1, (int) env('REMINDERS_DAILY_LIMIT', 120)),
    'timezone' => env('REMINDER_TIMEZONE', 'Africa/Tripoli'),
    'spread_minutes' => 75,
];
