<?php

namespace App\Support;

use App\Models\PriceList;

class PriceListWhatsApp
{
    public static function url(?string $phone, string $message): ?string
    {
        $digits = static::international($phone);
        if ($digits === null || strlen($digits) < 11) {
            return null;
        }

        return 'https://wa.me/'.$digits.'?text='.rawurlencode($message);
    }

    public static function international(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '218')) {
            return $digits;
        }
        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return '218'.$digits;
    }

    public static function message(PriceList $list, string $shareUrl): string
    {
        $lines = [
            'عرض سعر من MG Plastic',
            $list->title,
        ];
        if ($label = $list->customerLabel()) {
            $lines[] = $label;
        }
        $lines[] = 'راجع الأسعار والتفاصيل من الرابط:';
        $lines[] = $shareUrl;

        return implode("\n", $lines);
    }
}
