<?php

namespace App\Support;

/**
 * Permissions for network store control panels (wholesale + retail).
 * Empty / null permissions on a store user = full access (same pattern as AdminPermissions).
 */
class StorePermissions
{
    public const PANEL_ACCESS = 'panel.access';

    public const POS_SELL = 'pos.sell';

    public const ORDERS_PLACE = 'orders.place';

    public const ORDERS_MANAGE = 'orders.manage';

    public const INVOICES_VIEW = 'invoices.view';

    public const CATALOG_MANAGE = 'catalog.manage';

    public const STORE_PROFILE = 'store.profile';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::PANEL_ACCESS => 'دخول لوحة التحكم',
            self::POS_SELL => 'البيع من الكاشير (POS)',
            self::ORDERS_PLACE => 'إنشاء طلبيات للمورد',
            self::ORDERS_MANAGE => 'إدارة واستلام الطلبيات',
            self::INVOICES_VIEW => 'عرض الفواتير والتوزيعات',
            self::CATALOG_MANAGE => 'إدارة منتجات المتجر',
            self::STORE_PROFILE => 'تعديل بيانات المتجر',
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::labels());
    }
}
