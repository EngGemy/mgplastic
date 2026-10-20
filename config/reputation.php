<?php

return [
    'enabled' => filter_var(env('REPUTATION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'weights' => [
        'volume' => 40,
        'consistency' => 25,
        'returns' => 15,
        'warranty' => 10,
        'tenure' => 10,
    ],

    'levels' => [
        'bronze' => ['min' => 0, 'max' => 39, 'label' => 'برونزي'],
        'silver' => ['min' => 40, 'max' => 64, 'label' => 'فضي'],
        'gold' => ['min' => 65, 'max' => 84, 'label' => 'ذهبي'],
        'diamond' => ['min' => 85, 'max' => 100, 'label' => 'ماسي'],
    ],

    'volume_lookback_days' => 90,
    'consistency_weeks' => 12,
    'daily_score_event_cap' => 20,
    'week_starts_on' => 'saturday', // Africa/Tripoli
    'timezone' => env('REMINDER_TIMEZONE', 'Africa/Tripoli'),
];
