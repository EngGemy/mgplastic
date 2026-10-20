<?php

namespace App\Support;

/**
 * Unified Latin digit formatting for WhatsApp cards & captions.
 */
class Money
{
    public static function format(int|float|string|null $amount, string $currency = 'د.ل'): string
    {
        $n = is_numeric($amount) ? (float) $amount : 0.0;

        return number_format($n, 2, '.', ',').($currency !== '' ? ' '.$currency : '');
    }

    /** Cents → "1,250.00 د.ل" */
    public static function fromCents(int|null $cents, string $currency = 'د.ل'): string
    {
        return self::format(((int) $cents) / 100, $currency);
    }

    public static function points(int|float|string|null $points): string
    {
        $n = (int) round((float) (is_numeric($points) ? $points : 0));

        return number_format($n, 0, '.', ',').' نقطة';
    }
}
