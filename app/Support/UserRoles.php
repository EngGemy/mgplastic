<?php

namespace App\Support;

use App\Models\User;

class UserRoles
{
    /** @return array<string, array{label: string, icon: string, color: string, group: string}> */
    public static function definitions(): array
    {
        return [
            'super_admin' => [
                'label' => 'سوبر أدمن',
                'icon' => 'heroicon-o-shield-check',
                'color' => 'purple',
                'group' => 'admin',
            ],
            'admin' => [
                'label' => 'مدير لوحة التحكم',
                'icon' => 'heroicon-o-key',
                'color' => 'indigo',
                'group' => 'admin',
            ],
            'wholesale_distributor' => [
                'label' => 'موزع جملة',
                'icon' => 'heroicon-o-building-storefront',
                'color' => 'blue',
                'group' => 'network',
            ],
            'retail_trader' => [
                'label' => 'تاجر قطاعي',
                'icon' => 'heroicon-o-building-office',
                'color' => 'amber',
                'group' => 'network',
            ],
            User::ROLE_PLUMBER => [
                'label' => 'سباك',
                'icon' => 'heroicon-o-wrench-screwdriver',
                'color' => 'green',
                'group' => 'field',
            ],
            User::ROLE_VENDOR => [
                'label' => 'بائع / متجر',
                'icon' => 'heroicon-o-shopping-bag',
                'color' => 'teal',
                'group' => 'field',
            ],
            'user' => [
                'label' => 'مستخدم تطبيق',
                'icon' => 'heroicon-o-user',
                'color' => 'gray',
                'group' => 'app',
            ],
        ];
    }

    public static function label(?string $role): string
    {
        return self::definitions()[$role]['label'] ?? ($role ?: '—');
    }

    public static function color(?string $role): string
    {
        return self::definitions()[$role]['color'] ?? 'gray';
    }

    public static function icon(?string $role): string
    {
        return self::definitions()[$role]['icon'] ?? 'heroicon-o-user';
    }

    /** @return array<string, string> */
    public static function selectOptions(): array
    {
        return collect(self::definitions())->mapWithKeys(
            fn ($def, $key) => [$key => $def['label']]
        )->all();
    }

    /** @return array<string, array{label: string, roles: array<int, string>}> */
    public static function reportTabs(): array
    {
        return [
            'all' => ['label' => 'الكل', 'roles' => []],
            'admin' => ['label' => 'الإدارة', 'roles' => ['super_admin', 'admin']],
            'wholesale_distributor' => ['label' => 'موزعو الجملة', 'roles' => ['wholesale_distributor']],
            'retail_trader' => ['label' => 'تجار القطاعي', 'roles' => ['retail_trader']],
            'plumber' => ['label' => 'السبّاكون', 'roles' => [User::ROLE_PLUMBER]],
            'other' => ['label' => 'أخرى', 'roles' => [User::ROLE_VENDOR, 'user']],
        ];
    }
}
