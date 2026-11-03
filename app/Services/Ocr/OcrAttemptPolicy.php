<?php

namespace App\Services\Ocr;

/**
 * Classifies Gemini/pipeline failures into stable OCR-* codes and retry decisions.
 */
class OcrAttemptPolicy
{
    public const RETRYABLE_CODES = [
        'timeout',
        'http_429',
        'http_5xx',
        'http_500',
        'http_503',
        'invalid_json',
        'empty_text',
        'exception',
        'connection',
        'oversize',
        'http_404',
    ];

    /**
     * @param  array<string, mixed>  $result  pipeline/gemini failure payload
     * @return array{stable_code:string, retryable:bool, switch_fallback:bool, notify_auth:bool, notify_model:bool, json_once:bool}
     */
    public function classify(array $result): array
    {
        $codes = config('ocr_resilience.error_codes', []);
        $code = (string) ($result['code'] ?? 'failed');
        $http = isset($result['http_status']) ? (int) $result['http_status'] : null;

        if ($code === 'safety' || ($result['finish_reason'] ?? null) === 'SAFETY') {
            return $this->decision($codes['blocked'] ?? 'OCR-BLOCKED', false);
        }

        if ($code === 'http_auth' || in_array($http, [401, 403], true)) {
            return $this->decision($codes['auth'] ?? 'OCR-AUTH', false, notifyAuth: true);
        }

        if ($code === 'http_404' || $http === 404) {
            return $this->decision($codes['model_missing'] ?? 'OCR-MODEL', true, switchFallback: true, notifyModel: true);
        }

        if (in_array($code, ['invalid_json', 'empty_text'], true)) {
            return $this->decision($codes['empty_json'] ?? 'OCR-EMPTY-JSON', true, jsonOnce: true);
        }

        if ($code === 'timeout' || $http === 408) {
            return $this->decision($codes['timeout'] ?? 'OCR-TIMEOUT', true);
        }

        if ($code === 'http_429' || $http === 429) {
            return $this->decision($codes['busy'] ?? 'OCR-BUSY', true);
        }

        if ($code === 'http_5xx' || ($http !== null && $http >= 500)) {
            return $this->decision($codes['busy'] ?? 'OCR-BUSY', true);
        }

        if ($code === 'oversize') {
            return $this->decision($codes['oversize'] ?? 'OCR-OVERSIZE', true);
        }

        if (in_array($code, self::RETRYABLE_CODES, true)) {
            return $this->decision($codes['failed'] ?? 'OCR-FAILED', true);
        }

        return $this->decision($codes['failed'] ?? 'OCR-FAILED', false);
    }

    /**
     * Delay seconds before next attempt (0-based attempt index after this failure).
     */
    public function delaySeconds(int $nextAttemptIndex, ?int $retryAfterHeader = null): int
    {
        $cap = max(1, (int) config('ocr_resilience.retry_after_cap_seconds', 60));
        if ($retryAfterHeader !== null && $retryAfterHeader > 0) {
            return min($cap, $retryAfterHeader);
        }

        $delays = (array) config('ocr_resilience.retry_delays', [0, 3, 8]);
        $idx = max(0, min($nextAttemptIndex, count($delays) - 1));

        return (int) ($delays[$idx] ?? 8);
    }

    public function maxAttempts(): int
    {
        return count((array) config('ocr_resilience.retry_delays', [0, 3, 8]));
    }

    /**
     * @return array{stable_code:string, retryable:bool, switch_fallback:bool, notify_auth:bool, notify_model:bool, json_once:bool}
     */
    protected function decision(
        string $stable,
        bool $retryable,
        bool $switchFallback = false,
        bool $notifyAuth = false,
        bool $notifyModel = false,
        bool $jsonOnce = false,
    ): array {
        return [
            'stable_code' => $stable,
            'retryable' => $retryable,
            'switch_fallback' => $switchFallback,
            'notify_auth' => $notifyAuth,
            'notify_model' => $notifyModel,
            'json_once' => $jsonOnce,
        ];
    }
}
