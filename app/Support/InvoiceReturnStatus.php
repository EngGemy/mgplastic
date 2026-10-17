<?php

namespace App\Support;

class InvoiceReturnStatus
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    /** Legacy synonym kept for old rows before migration. */
    public const CONFIRMED = 'confirmed';

    /** @return list<string> */
    public static function settled(): array
    {
        return [self::APPROVED, self::CONFIRMED];
    }

    public static function isSettled(?string $status): bool
    {
        return in_array($status, self::settled(), true);
    }

    public static function isPending(?string $status): bool
    {
        return $status === self::PENDING;
    }

    /** @return array<string, array{label:string, color:string}> */
    public static function definitions(): array
    {
        return [
            self::PENDING => [
                'label' => 'بانتظار الموافقة',
                'color' => 'warning',
            ],
            self::APPROVED => [
                'label' => 'مقبول',
                'color' => 'success',
            ],
            self::CONFIRMED => [
                'label' => 'مقبول',
                'color' => 'success',
            ],
            self::REJECTED => [
                'label' => 'مرفوض',
                'color' => 'danger',
            ],
        ];
    }

    public static function label(?string $status): string
    {
        return self::definitions()[$status]['label'] ?? ($status ?: '—');
    }

    public static function color(?string $status): string
    {
        return self::definitions()[$status]['color'] ?? 'gray';
    }

    /** Normalize API status: confirmed → approved. */
    public static function normalize(?string $status): string
    {
        if ($status === self::CONFIRMED) {
            return self::APPROVED;
        }

        return $status ?: self::PENDING;
    }
}
