<?php

namespace App\Support;

class AdminPermissions
{
    public const USERS_VIEW = 'users.view';

    public const USERS_MANAGE = 'users.manage';

    public const USERS_DELETE = 'users.delete';

    public const PRODUCTS_MANAGE = 'products.manage';

    public const INVOICES_MANAGE = 'invoices.manage';

    public const DISTRIBUTIONS_MANAGE = 'distributions.manage';

    public const STORES_MANAGE = 'stores.manage';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const REPORTS_VIEW = 'reports.view';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::USERS_VIEW => 'عرض المستخدمين والتقارير',
            self::USERS_MANAGE => 'إضافة وتعديل المستخدمين',
            self::USERS_DELETE => 'حذف المستخدمين',
            self::PRODUCTS_MANAGE => 'إدارة المنتجات والفئات',
            self::INVOICES_MANAGE => 'إدارة الفواتير',
            self::DISTRIBUTIONS_MANAGE => 'إدارة التوزيعات والنقاط',
            self::STORES_MANAGE => 'إدارة المتاجر والشبكة',
            self::SETTINGS_MANAGE => 'إعدادات النظام',
            self::REPORTS_VIEW => 'عرض التقارير',
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::labels());
    }
}
