<?php

/**
 * Default home layout — mirrors docs/home-layout/sample-*.json
 */
return [
    'roles' => ['plumber', 'retail_trader', 'wholesale_distributor', 'guest'],

    'types' => [
        'pending_receipts' => ['label' => 'تنبيه استلام بضاعة', 'source' => 'app'],
        'wallet' => ['label' => 'بطاقة المحفظة', 'source' => 'app'],
        'slider' => ['label' => 'السلايدر الكبير', 'source' => 'api'],
        'search' => ['label' => 'مربع البحث', 'source' => 'app'],
        'low_stock' => ['label' => 'تنبيه نقص المخزون', 'source' => 'app'],
        'shop_quick_links' => ['label' => 'لوحة تحكم المحل', 'source' => 'api'],
        'quick_actions' => ['label' => 'أزرار سريعة', 'source' => 'api'],
        // Personal card is filled per authenticated plumber in HomeMobileLayoutService.
        'reputation' => ['label' => 'سمعتي', 'source' => 'api'],
        'points_summary' => ['label' => 'ملخص النقاط', 'source' => 'app'],
        'pipe_systems' => ['label' => 'أنظمة المواسير', 'source' => 'api'],
        'main_sections' => ['label' => 'الأقسام الرئيسية', 'source' => 'api'],
        'new_products' => ['label' => 'جديد منتجات MG', 'source' => 'api'],
        'collections' => ['label' => 'مجموعات المنتجات', 'source' => 'api'],
        'featured_wholesalers' => ['label' => 'أهم الموزعين', 'source' => 'api'],
        'events' => ['label' => 'الفعاليات', 'source' => 'api'],
        'stores' => ['label' => 'المتاجر', 'source' => 'api'],
        'mg_banner' => ['label' => 'البانر الأخضر منتجات MG', 'source' => 'api'],
        'promo_banners' => ['label' => 'سلايدر الإعلانات', 'source' => 'api'],
        'testimonials' => ['label' => 'ماذا يقول المتعاملون', 'source' => 'api'],
        'plumbers' => ['label' => 'السباكون', 'source' => 'api'],
    ],

    /** Exact order from sample-*.json */
    'defaults' => [
        'plumber' => [
            'pending_receipts', 'wallet', 'search', 'quick_actions', 'reputation',
            'slider', 'pipe_systems', 'main_sections', 'new_products', 'collections',
            'events', 'stores', 'mg_banner', 'promo_banners', 'testimonials', 'plumbers',
        ],
        'guest' => [
            'search', 'slider', 'pipe_systems', 'main_sections', 'new_products',
            'collections', 'events', 'stores', 'mg_banner', 'promo_banners', 'testimonials', 'plumbers',
        ],
        'retail_trader' => [
            'pending_receipts', 'wallet', 'slider', 'search', 'low_stock', 'shop_quick_links',
            'points_summary', 'pipe_systems', 'main_sections', 'new_products', 'collections',
            'featured_wholesalers', 'events', 'stores', 'mg_banner', 'promo_banners', 'testimonials',
        ],
        'wholesale_distributor' => [
            'pending_receipts', 'wallet', 'slider', 'search', 'low_stock', 'shop_quick_links_distributor',
            'points_summary', 'pipe_systems', 'main_sections', 'new_products', 'collections',
            'events', 'stores', 'mg_banner', 'promo_banners', 'testimonials',
        ],
    ],

    /** Map section key → type (when key !== type). */
    'key_types' => [
        'shop_quick_links_distributor' => 'shop_quick_links',
    ],

    'default_titles' => [
        'low_stock' => 'نقص المخزون',
        'shop_quick_links' => 'لوحة التحكم',
        'pipe_systems' => 'أنظمة المواسير',
        'main_sections' => 'الأقسام الرئيسية',
        'new_products' => 'جديد منتجات MG',
        'collections' => 'مجموعات المنتجات',
        'featured_wholesalers' => 'أهم الموزعين',
        'events' => 'الفعاليات',
        'stores' => 'المتاجر',
        'testimonials' => 'ماذا يقول المتعاملون',
        'plumbers' => 'السباكون',
    ],

    'default_limits' => [
        'new_products' => 10,
        'events' => 5,
        'stores' => 3,
        'plumbers' => 3,
        'featured_wholesalers' => 8,
        'promo_banners' => 8,
        'testimonials' => 12,
        'collections' => 8,
        'pipe_systems' => 10,
        'main_sections' => 12,
    ],

    'default_see_all' => [
        'events' => ['text' => 'عرض المزيد', 'link' => 'mgplastic://list/events'],
        'stores' => ['text' => 'عرض المزيد', 'link' => 'mgplastic://list/shops'],
        'plumbers' => ['text' => 'عرض المزيد', 'link' => 'mgplastic://list/plumbers'],
        'pipe_systems' => ['text' => 'عرض المزيد', 'link' => 'mgplastic://catalog'],
    ],

    'default_settings' => [
        'wallet' => ['button_text' => 'عرض المحفظة', 'button_link' => 'mgplastic://wallet'],
        'slider' => ['autoplay' => true, 'interval_ms' => 4000],
        'search' => ['placeholder' => 'ابحث عن المنتج أو المقاس أو الكود...'],
        'reputation' => ['link' => 'mgplastic://reputation'],
        'points_summary' => ['link' => 'mgplastic://points'],
    ],

    /** sample-plumber.json quick_actions */
    'quick_actions_plumber' => [
        ['key' => 'orders', 'title' => 'طلباتي من القطاعي', 'icon' => 'clipboard', 'link' => 'mgplastic://plumber-orders'],
        ['key' => 'works', 'title' => 'أعمالي', 'icon' => 'image', 'link' => 'mgplastic://my-works'],
        ['key' => 'notifications', 'title' => 'الإشعارات', 'icon' => 'bell', 'link' => 'mgplastic://notifications'],
        ['key' => 'support', 'title' => 'الدعم', 'icon' => 'help', 'link' => 'mgplastic://support'],
    ],

    /** sample-retail-trader.json shop_quick_links */
    'shop_quick_links_trader' => [
        ['key' => 'myPlumbers', 'title' => 'سباكيني', 'subtitle' => 'السباكين', 'icon' => 'wrench', 'tint' => '#7C5CFA', 'is_featured' => true, 'link' => 'mgplastic://network/plumbers'],
        ['key' => 'orders', 'title' => 'حالة طلب السباكين', 'subtitle' => 'طلبات السباكين', 'icon' => 'clipboard', 'tint' => '#11C78C', 'is_featured' => true, 'link' => 'mgplastic://trader-orders'],
        ['key' => 'wholesaleOrders', 'title' => 'حالة طلب شراء من الجملة', 'subtitle' => 'طلبات الجملة', 'icon' => 'list', 'tint' => '#F59E0B', 'is_featured' => true, 'link' => 'mgplastic://trader-orders?scope=to_wholesaler'],
        ['key' => 'orderWholesale', 'title' => 'طلب مواد من محلات الجملة', 'subtitle' => 'من محلات الجملة', 'icon' => 'store', 'tint' => '#0D80F2', 'is_featured' => true, 'link' => 'mgplastic://choose-wholesaler'],
        ['key' => 'joinWholesaler', 'title' => 'الانضمام لموزع', 'subtitle' => null, 'icon' => 'link', 'tint' => '#E0559B', 'is_featured' => false, 'link' => 'mgplastic://join-wholesaler'],
        ['key' => 'pendingReceipts', 'title' => 'بضاعة بانتظار الاستلام', 'subtitle' => null, 'icon' => 'truck', 'tint' => '#11C78C', 'is_featured' => false, 'link' => 'mgplastic://pending-receipts'],
        ['key' => 'invoices', 'title' => 'فواتيري', 'subtitle' => null, 'icon' => 'file', 'tint' => '#11C78C', 'is_featured' => false, 'link' => 'mgplastic://invoices'],
        ['key' => 'returns', 'title' => 'المرتجعات', 'subtitle' => null, 'icon' => 'refresh', 'tint' => '#EF4444', 'is_featured' => false, 'link' => 'mgplastic://returns'],
        ['key' => 'products', 'title' => 'منتجاتي', 'subtitle' => null, 'icon' => 'box', 'tint' => '#0EA5E9', 'is_featured' => false, 'link' => 'mgplastic://store-products'],
        ['key' => 'points', 'title' => 'لوحة النقاط', 'subtitle' => null, 'icon' => 'star', 'tint' => '#F59E0B', 'is_featured' => false, 'link' => 'mgplastic://points'],
        ['key' => 'wallet', 'title' => 'المحفظة', 'subtitle' => null, 'icon' => 'wallet', 'tint' => '#11C78C', 'is_featured' => false, 'link' => 'mgplastic://wallet'],
        ['key' => 'reports', 'title' => 'التقارير', 'subtitle' => null, 'icon' => 'chart', 'tint' => '#7C5CFA', 'is_featured' => false, 'link' => 'mgplastic://reports'],
        ['key' => 'storeMedia', 'title' => 'صور المحل', 'subtitle' => null, 'icon' => 'image', 'tint' => '#E0559B', 'is_featured' => false, 'link' => 'mgplastic://store-media'],
        ['key' => 'support', 'title' => 'الدعم', 'subtitle' => null, 'icon' => 'phone', 'tint' => '#0EA5E9', 'is_featured' => false, 'link' => 'mgplastic://support'],
    ],

    /** sample-wholesale-distributor.json shop_quick_links */
    'shop_quick_links_distributor' => [
        ['key' => 'traders', 'title' => 'القطاعيين', 'subtitle' => 'القطاعيين والسباكين', 'icon' => 'store', 'tint' => '#7C5CFA', 'is_featured' => true, 'link' => 'mgplastic://network/traders'],
        ['key' => 'factoryOrders', 'title' => 'مشترياتي من المصنع', 'subtitle' => 'الوارد والمخزون', 'icon' => 'list', 'tint' => '#11C78C', 'is_featured' => true, 'link' => 'mgplastic://distributor-orders?scope=to_factory&source=factory'],
        ['key' => 'incoming', 'title' => 'الطلبات الواردة', 'subtitle' => 'حالة الطلبات', 'icon' => 'clipboard', 'tint' => '#F59E0B', 'is_featured' => true, 'link' => 'mgplastic://distributor-orders?scope=from_retail'],
        ['key' => 'newOrder', 'title' => 'طلب شراء مواد', 'subtitle' => 'من كتالوج MG', 'icon' => 'plus', 'tint' => '#0D80F2', 'is_featured' => true, 'link' => 'mgplastic://new-order'],
        ['key' => 'warehouseOrders', 'title' => 'مشترياتي من المخزن', 'subtitle' => null, 'icon' => 'box', 'tint' => '#0EA5E9', 'is_featured' => false, 'link' => 'mgplastic://distributor-orders?scope=to_factory&source=warehouse'],
        ['key' => 'joinRequests', 'title' => 'طلبات الانضمام', 'subtitle' => null, 'icon' => 'user', 'tint' => '#E0559B', 'is_featured' => false, 'link' => 'mgplastic://join-requests'],
        ['key' => 'pos', 'title' => 'نقطة البيع', 'subtitle' => null, 'icon' => 'cart', 'tint' => '#0D80F2', 'is_featured' => false, 'link' => 'mgplastic://pos'],
        ['key' => 'invoices', 'title' => 'فواتيري', 'subtitle' => null, 'icon' => 'file', 'tint' => '#11C78C', 'is_featured' => false, 'link' => 'mgplastic://invoices'],
        ['key' => 'returns', 'title' => 'المرتجعات', 'subtitle' => null, 'icon' => 'refresh', 'tint' => '#EF4444', 'is_featured' => false, 'link' => 'mgplastic://returns'],
        ['key' => 'products', 'title' => 'منتجاتي', 'subtitle' => null, 'icon' => 'box', 'tint' => '#0EA5E9', 'is_featured' => false, 'link' => 'mgplastic://store-products'],
        ['key' => 'points', 'title' => 'لوحة النقاط', 'subtitle' => null, 'icon' => 'star', 'tint' => '#F59E0B', 'is_featured' => false, 'link' => 'mgplastic://points'],
        ['key' => 'wallet', 'title' => 'المحفظة', 'subtitle' => null, 'icon' => 'wallet', 'tint' => '#11C78C', 'is_featured' => false, 'link' => 'mgplastic://wallet'],
        ['key' => 'reports', 'title' => 'التقارير', 'subtitle' => null, 'icon' => 'chart', 'tint' => '#7C5CFA', 'is_featured' => false, 'link' => 'mgplastic://reports'],
        ['key' => 'storeMedia', 'title' => 'صور المحل', 'subtitle' => null, 'icon' => 'image', 'tint' => '#E0559B', 'is_featured' => false, 'link' => 'mgplastic://store-media'],
        ['key' => 'support', 'title' => 'الدعم', 'subtitle' => null, 'icon' => 'phone', 'tint' => '#0EA5E9', 'is_featured' => false, 'link' => 'mgplastic://support'],
    ],

    'icon_options' => [
        'alert', 'arrowDown', 'arrowUp', 'back', 'bell', 'box', 'calendar', 'cart', 'chart', 'check',
        'chevron', 'clipboard', 'close', 'drop', 'edit', 'eye', 'eyeOff', 'file', 'filter', 'fingerprint',
        'fitting', 'globe', 'grid', 'help', 'home', 'image', 'info', 'link', 'list', 'lock', 'mail',
        'logout', 'message', 'phone', 'pin', 'pipe', 'play', 'plus', 'refresh', 'search', 'send', 'share',
        'shield', 'star', 'store', 'trash', 'trophy', 'truck', 'user', 'video', 'volume', 'volumeOff',
        'wallet', 'whatsapp', 'wrench', 'zap', 'projects', 'sewage', 'water', 'tee', 'valve', 'elbow', 'cap',
    ],
];
