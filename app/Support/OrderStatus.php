<?php

namespace App\Support;

class OrderStatus
{
    public const PLACED = 'placed';
    public const CONFIRMED = 'confirmed';
    public const SHIPPING = 'shipping';
    public const DELIVERED = 'delivered';
    public const CANCELLED = 'cancelled';
    public const REJECTED = 'rejected';

    public const CHANNEL_FACTORY_TO_WHOLESALE = 'factory_to_wholesale';
    public const CHANNEL_WHOLESALE_TO_RETAIL = 'wholesale_to_retail';

    /** @return array<string, array{label:string, color:string, icon:string, description:string}> */
    public static function definitions(): array
    {
        return [
            self::PLACED => [
                'label' => 'بانتظار التأكيد',
                'color' => 'warning',
                'icon' => 'heroicon-o-clock',
                'description' => 'تم إرسال الطلب وبانتظار تأكيد المورّد.',
            ],
            self::CONFIRMED => [
                'label' => 'تم التأكيد',
                'color' => 'info',
                'icon' => 'heroicon-o-check-circle',
                'description' => 'وافق المورّد على الطلب ويجري تجهيزه.',
            ],
            self::SHIPPING => [
                'label' => 'في الطريق',
                'color' => 'primary',
                'icon' => 'heroicon-o-truck',
                'description' => 'تم شحن الطلب وهو في طريقه إليك.',
            ],
            self::DELIVERED => [
                'label' => 'تم التسليم',
                'color' => 'success',
                'icon' => 'heroicon-o-check-badge',
                'description' => 'تم استلام الطلب وأُضيفت الكميات إلى مخزونك.',
            ],
            self::CANCELLED => [
                'label' => 'ملغي',
                'color' => 'gray',
                'icon' => 'heroicon-o-x-circle',
                'description' => 'تم إلغاء الطلب.',
            ],
            self::REJECTED => [
                'label' => 'مرفوض',
                'color' => 'danger',
                'icon' => 'heroicon-o-no-symbol',
                'description' => 'رفض المورّد هذا الطلب.',
            ],
        ];
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::definitions())->map(fn ($d) => $d['label'])->all();
    }

    public static function label(?string $status): string
    {
        return self::definitions()[$status]['label'] ?? ($status ?: '—');
    }

    public static function color(?string $status): string
    {
        return self::definitions()[$status]['color'] ?? 'gray';
    }

    public static function icon(?string $status): string
    {
        return self::definitions()[$status]['icon'] ?? 'heroicon-o-question-mark-circle';
    }

    public static function description(?string $status): string
    {
        return self::definitions()[$status]['description'] ?? '';
    }

    /** Ordered lifecycle steps for the progress tracker. */
    public static function timeline(): array
    {
        return [self::PLACED, self::CONFIRMED, self::SHIPPING, self::DELIVERED];
    }

    public static function isOpen(?string $status): bool
    {
        return in_array($status, [self::PLACED, self::CONFIRMED, self::SHIPPING], true);
    }

    public static function isFinal(?string $status): bool
    {
        return in_array($status, [self::DELIVERED, self::CANCELLED, self::REJECTED], true);
    }

    /** @return array<string, string> */
    public static function channelOptions(): array
    {
        return [
            self::CHANNEL_FACTORY_TO_WHOLESALE => 'من المصنع لموزع الجملة',
            self::CHANNEL_WHOLESALE_TO_RETAIL => 'من موزع الجملة للتاجر القطاعي',
        ];
    }

    public static function channelLabel(?string $channel): string
    {
        return self::channelOptions()[$channel] ?? ($channel ?: '—');
    }
}
