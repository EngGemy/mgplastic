<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final class HealthCheck
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $status,
        public readonly mixed $value = null,
        public readonly ?string $details = null,
        public readonly ?string $checkedAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attrs
     */
    public static function make(array $attrs): self
    {
        return new self(
            key: (string) $attrs['key'],
            label: (string) $attrs['label'],
            status: (string) $attrs['status'],
            value: $attrs['value'] ?? null,
            details: isset($attrs['details']) ? (string) $attrs['details'] : null,
            checkedAt: isset($attrs['checked_at'])
                ? (string) $attrs['checked_at']
                : now()->toIso8601String(),
        );
    }

    /**
     * @return array{key: string, label: string, status: string, value: mixed, details: ?string, checked_at: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'status' => $this->status,
            'value' => $this->value,
            'details' => $this->details,
            'checked_at' => $this->checkedAt ?? now()->toIso8601String(),
        ];
    }

    public function isProblem(): bool
    {
        return in_array($this->status, ['warning', 'critical'], true);
    }

    public function checkedAtCarbon(): ?Carbon
    {
        return $this->checkedAt ? Carbon::parse($this->checkedAt) : null;
    }
}
