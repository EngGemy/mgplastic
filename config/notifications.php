<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Push rate limit (normal priority only)
    |--------------------------------------------------------------------------
    */
    'max_normal_push_per_user_per_day' => (int) env('NOTIFICATIONS_MAX_NORMAL_PUSH_PER_DAY', 10),

    /*
    |--------------------------------------------------------------------------
    | Quiet hours — delay normal priority instead of dropping
    |--------------------------------------------------------------------------
    */
    'quiet_hours' => [
        'enabled' => (bool) env('NOTIFICATIONS_QUIET_HOURS', true),
        'default_timezone' => env('NOTIFICATIONS_DEFAULT_TZ', 'Africa/Tripoli'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Android notification channel
    |--------------------------------------------------------------------------
    */
    'android_channel_id' => env('NOTIFICATIONS_ANDROID_CHANNEL', 'mg_default'),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */
    // Default to "default" so a single `queue:work` worker delivers admin pushes.
    // Override with NOTIFICATIONS_QUEUE=notifications only if you run that worker.
    'queue' => env('NOTIFICATIONS_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Dual-write Laravel Filament database notifications (BC)
    |--------------------------------------------------------------------------
    */
    'dual_write_filament' => (bool) env('NOTIFICATIONS_DUAL_WRITE_FILAMENT', true),
];
