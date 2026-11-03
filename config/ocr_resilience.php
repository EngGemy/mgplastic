<?php

/**
 * OCR batch resilience — retries, parallelism, fallbacks.
 * All env reads stay here (never env() in app code).
 */
return [

    'max_images_per_batch' => (int) env('OCR_BATCH_MAX_IMAGES', 10),

    'max_parallel_per_batch' => (int) env('OCR_BATCH_MAX_PARALLEL', 2),

    /** Soft cap for one job attempt (cPanel cron --max-time=55). */
    'job_timeout_seconds' => (int) env('OCR_JOB_TIMEOUT', 50),

    /** Wall-clock retry budget per image across attempts. */
    'retry_budget_seconds' => (int) env('OCR_RETRY_BUDGET', 90),

    /** Delays before attempt 1, 2, 3 (attempt index 0..2). */
    'retry_delays' => [0, 3, 8],

    'retry_after_cap_seconds' => 60,

    /** Attempt index (0-based) that switches to fallback model. */
    'fallback_attempt_index' => 2,

    'fallback_model' => env('GEMINI_OCR_FALLBACK_MODEL'),

    'primary_model' => env('GEMINI_OCR_MODEL', env('GEMINI_MODEL', 'gemini-2.0-flash')),

    /** Consecutive image failures before opening a soft OCR breaker (seconds open). */
    'circuit_consecutive_failures' => (int) env('OCR_CIRCUIT_FAILURES', 8),
    'circuit_open_seconds' => (int) env('OCR_CIRCUIT_TTL', 120),

    'poll_interval_ms' => 2000,
    'poll_backoff_after_ms' => 20000,
    'poll_backoff_interval_ms' => 5000,

    'client_max_side' => 2000,
    'client_jpeg_quality' => 0.85,

    'error_codes' => [
        'timeout' => 'OCR-TIMEOUT',
        'busy' => 'OCR-BUSY',
        'auth' => 'OCR-AUTH',
        'blocked' => 'OCR-BLOCKED',
        'empty_json' => 'OCR-EMPTY-JSON',
        'model_missing' => 'OCR-MODEL',
        'oversize' => 'OCR-OVERSIZE',
        'failed' => 'OCR-FAILED',
    ],
];
