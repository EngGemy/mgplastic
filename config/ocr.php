<?php

/**
 * Mobile OCR hardening — quotas, stock exposure, retention, contract.
 * All env reads stay here (never env() in app code).
 */
return [

    'contract_version' => '2026-11-09.harden-v1',

    'min_app_version' => env('OCR_MIN_APP_VERSION'),

    /** Expose exact available_qty of another party's stock on supply-order OCR. */
    'expose_supplier_qty' => (bool) env('OCR_EXPOSE_SUPPLIER_QTY', false),

    'max_images_per_batch' => (int) env('OCR_BATCH_MAX_IMAGES', 10),

    'max_upload_bytes' => (int) env('OCR_MAX_UPLOAD_BYTES', 8 * 1024 * 1024),

    'max_image_side' => (int) env('OCR_MAX_IMAGE_SIDE', 2000),

    'jpeg_quality' => (int) env('OCR_JPEG_QUALITY', 85),

    'accept_heic' => (bool) env('OCR_ACCEPT_HEIC', true),

    /** Per-user daily OCR image uploads by role. */
    'daily_image_quota' => [
        'plumber' => (int) env('OCR_DAILY_QUOTA_PLUMBER', 20),
        'retail_trader' => (int) env('OCR_DAILY_QUOTA_TRADER', 50),
        'wholesale_distributor' => (int) env('OCR_DAILY_QUOTA_DISTRIBUTOR', 100),
    ],

    /** Global Gemini OCR calls per calendar day (shared hosting). */
    'global_gemini_daily_cap' => (int) env('OCR_GLOBAL_GEMINI_DAILY_CAP', 800),

    'global_gemini_alert_at' => (float) env('OCR_GLOBAL_GEMINI_ALERT_AT', 0.80),

    'job_timeout_seconds' => (int) env('OCR_JOB_TIMEOUT', 150),

    'reading_stuck_minutes' => (int) env('OCR_READING_STUCK_MINUTES', 5),

    'auto_abandon_days' => (int) env('OCR_AUTO_ABANDON_DAYS', 7),

    'retention_linked_days' => (int) env('OCR_RETENTION_LINKED_DAYS', 90),

    'retention_unapplied_days' => (int) env('OCR_RETENTION_UNAPPLIED_DAYS', 7),

    'total_mismatch_pct' => (float) env('OCR_TOTAL_MISMATCH_PCT', 5.0),

    'qty_unusual_history_multiplier' => (float) env('OCR_QTY_UNUSUAL_HISTORY_MULT', 3.0),

    'qty_unusual_typical_multiplier' => (float) env('OCR_QTY_UNUSUAL_TYPICAL_MULT', 5.0),

    'alias_promote_min_users' => (int) env('OCR_ALIAS_PROMOTE_MIN_USERS', 3),

    'duplicate_batch_window_hours' => (int) env('OCR_DUP_BATCH_WINDOW_HOURS', 48),

    'poll_interval_ms' => (int) env('OCR_POLL_INTERVAL_MS', 2000),

    'poll_backoff_interval_ms' => (int) env('OCR_POLL_BACKOFF_INTERVAL_MS', 10000),

    'poll_backoff_after_ms' => (int) env('OCR_POLL_BACKOFF_AFTER_MS', 20000),

    'stock_low_threshold_pieces' => (int) env('OCR_STOCK_LOW_THRESHOLD', 10),

];
