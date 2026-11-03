<?php

namespace App\Services\Ocr;

use Illuminate\Support\Facades\Cache;

/**
 * Soft OCR breaker — only opens after N consecutive failures across images.
 * Does not share state with WAHA.
 */
class OcrCircuitBreaker
{
    protected function key(): string
    {
        return 'ocr:circuit:consecutive_failures';
    }

    protected function openKey(): string
    {
        return 'ocr:circuit:open_until';
    }

    public function isOpen(): bool
    {
        $until = Cache::get($this->openKey());

        return is_numeric($until) && (int) $until > time();
    }

    public function recordSuccess(): void
    {
        Cache::forget($this->key());
        Cache::forget($this->openKey());
    }

    public function recordFailure(): void
    {
        $n = (int) Cache::increment($this->key());
        $threshold = max(1, (int) config('ocr_resilience.circuit_consecutive_failures', 8));
        if ($n >= $threshold) {
            $ttl = max(30, (int) config('ocr_resilience.circuit_open_seconds', 120));
            Cache::put($this->openKey(), time() + $ttl, $ttl + 10);
            Cache::forget($this->key());
        }
    }
}
