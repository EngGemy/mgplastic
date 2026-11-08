<?php

/**
 * OCR receipt image storage — retention & signed preview.
 * All env reads stay here (never env() in app code).
 */
return [

    /** Days to keep uploaded OCR images after batch is finished (added/abandoned). */
    'retention_days' => (int) env('OCR_UPLOAD_RETENTION_DAYS', 30),

    /** Signed preview URL lifetime (minutes). */
    'signed_preview_minutes' => (int) env('OCR_SIGNED_PREVIEW_MINUTES', 30),

];
