<?php

return [

    /*
    |--------------------------------------------------------------------------
    | System health thresholds (env-overridable)
    |--------------------------------------------------------------------------
    */

    'queue_heartbeat_key' => 'monitoring:queue_worker_heartbeat',

    'queue_heartbeat_throttle_seconds' => max(5, (int) env('MONITOR_QUEUE_HEARTBEAT_THROTTLE', 30)),

    'queue_heartbeat_stale_seconds' => max(60, (int) env('MONITOR_QUEUE_HEARTBEAT_STALE', 300)),

    /** After `queue:restart`, treat missing/stale heartbeat as OK for this many seconds. */
    'queue_restart_grace_seconds' => max(30, (int) env('MONITOR_QUEUE_RESTART_GRACE', 180)),

    'queue_oldest_pending_critical_seconds' => max(60, (int) env('MONITOR_QUEUE_OLDEST_PENDING', 600)),

    'failed_jobs_warning' => max(1, (int) env('MONITOR_FAILED_JOBS_WARNING', 1)),

    'failed_jobs_critical' => max(1, (int) env('MONITOR_FAILED_JOBS_CRITICAL', 5)),

    'whatsapp_failures_window_hours' => max(1, (int) env('MONITOR_WA_FAILURES_WINDOW', 24)),

    'whatsapp_no_sent_critical_hours' => max(1, (int) env('MONITOR_WA_NO_SENT_HOURS', 6)),

    'push_queued_stale_minutes' => max(5, (int) env('MONITOR_PUSH_QUEUED_STALE', 30)),

    'push_failed_window_hours' => max(1, (int) env('MONITOR_PUSH_FAILED_WINDOW', 24)),

    'waha_session_cache_seconds' => max(10, (int) env('MONITOR_WAHA_SESSION_CACHE', 60)),

    'waha_session_timeout' => max(2, (int) env('MONITOR_WAHA_SESSION_TIMEOUT', 5)),

    'waha_breaker_probe_seconds' => max(60, (int) env('MONITOR_WAHA_BREAKER_PROBE', 300)),

    /** Keep auth-failure breakers open long enough for half-open probes (seconds). */
    'waha_breaker_auth_ttl' => max(300, (int) env('MONITOR_WAHA_BREAKER_AUTH_TTL', 43200)),

    'whatsapp_job_retry_hours' => max(1, (int) env('MONITOR_WA_JOB_RETRY_HOURS', 12)),

    'whatsapp_job_max_exceptions' => max(1, (int) env('MONITOR_WA_JOB_MAX_EXCEPTIONS', 3)),

    /** Repeat unresolved alerts after this many hours. */
    'alert_repeat_hours' => max(1, (int) env('MONITOR_ALERT_REPEAT_HOURS', 6)),

    'alert_state_cache_ttl_days' => max(1, (int) env('MONITOR_ALERT_STATE_TTL_DAYS', 14)),

    'health_banner_cache_seconds' => max(5, (int) env('MONITOR_BANNER_CACHE', 30)),

    'health_snapshot_key' => 'monitoring:health_snapshot',

    'health_snapshot_ttl_minutes' => max(10, (int) env('MONITOR_SNAPSHOT_TTL', 120)),

    /*
    | Max age before a queued WhatsApp message is dropped as stale (seconds),
    | or the special value "end_of_day" (reminder_daily → 23:59 same calendar day).
    */
    'whatsapp_message_max_age' => [
        'otp' => max(60, (int) env('MONITOR_WA_MAX_AGE_OTP', 300)),
        'otp_card' => max(60, (int) env('MONITOR_WA_MAX_AGE_OTP', 300)),
        'invoice_created' => max(60, (int) env('MONITOR_WA_MAX_AGE_INVOICE', 43200)),
        'welcome' => max(60, (int) env('MONITOR_WA_MAX_AGE_WELCOME', 43200)),
        'reminder_daily' => env('MONITOR_WA_MAX_AGE_REMINDER_DAILY', 'end_of_day'),
        'reminder_weekly' => max(60, (int) env('MONITOR_WA_MAX_AGE_REMINDER_WEEKLY', 86400)),
        'default' => max(60, (int) env('MONITOR_WA_MAX_AGE_DEFAULT', 43200)),
    ],
];
