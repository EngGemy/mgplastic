<?php

namespace App\Support;

final class WahaResult
{
    public const REASON_OK = 'ok';

    public const REASON_UNAUTHORIZED = 'unauthorized';

    public const REASON_SESSION_DOWN = 'session_down';

    public const REASON_TIMEOUT = 'timeout';

    public const REASON_MEDIA_ERROR = 'media_error';

    public const REASON_HTTP_ERROR = 'http_error';

    public const REASON_BREAKER_OPEN = 'breaker_open';

    public const REASON_DISABLED = 'disabled';

    public const REASON_CONFIG = 'config';

    public const REASON_INVALID_PHONE = 'invalid_phone';

    public const REASON_SKIPPED = 'skipped';

    public function __construct(
        public readonly bool $ok,
        public readonly string $reason = self::REASON_OK,
        public readonly ?int $status = null,
        public readonly ?string $detail = null,
    ) {}

    public static function success(?int $status = 200): self
    {
        return new self(true, self::REASON_OK, $status);
    }

    public static function failure(string $reason, ?int $status = null, ?string $detail = null): self
    {
        return new self(false, $reason, $status, $detail);
    }

    public function isSessionOrAuthFailure(): bool
    {
        return in_array($this->reason, [
            self::REASON_UNAUTHORIZED,
            self::REASON_SESSION_DOWN,
            self::REASON_BREAKER_OPEN,
            self::REASON_TIMEOUT,
        ], true);
    }

    public function shouldOpenBreaker(): bool
    {
        return in_array($this->reason, [
            self::REASON_UNAUTHORIZED,
            self::REASON_SESSION_DOWN,
            self::REASON_TIMEOUT,
            self::REASON_HTTP_ERROR,
        ], true) && ($this->status === null || $this->status === 401 || $this->status >= 500 || $this->status === 422);
    }
}
