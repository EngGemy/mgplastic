<?php

return [
    'enabled' => filter_var(env('TESTIMONIALS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'daily_generate' => filter_var(env('TESTIMONIALS_DAILY_GENERATE', true), FILTER_VALIDATE_BOOLEAN),
    'daily_count' => max(1, (int) env('TESTIMONIALS_DAILY_COUNT', 2)),
    'public_limit' => max(6, (int) env('TESTIMONIALS_PUBLIC_LIMIT', 24)),
    'timezone' => env('REMINDER_TIMEZONE', 'Africa/Tripoli'),

    'roles' => [
        'plumber' => 'سباك محترف',
        'retail' => 'تاجر قطاعي',
        'wholesale' => 'موزّع جملة',
        'customer' => 'صاحب مشروع',
        'engineer' => 'مهندس صحي',
    ],

    /** أسماء ليبية شائعة (عرض فقط — ليست حسابات حقيقية) */
    'names' => [
        'أحمد الترهوني', 'محمد الزوي', 'خالد المصري', 'عمر الغرياني', 'مصطفى الفيتوري',
        'عبدالله البكوش', 'يوسف الشلماني', 'إبراهيم القمودي', 'سالم الورفلي', 'حسام الزنتاني',
        'فاطمة الشلماني', 'نادية الطرابلسي', 'سارة بن عاشور', 'مريم الكيب', 'ليلى الزاوي',
        'علي الحاسي', 'محمود بن غشير', 'رضا السوايسي', 'طارق المصراتي', 'نوري الجعفري',
        'حسن البوسيفي', 'كريم الزليطني', 'أنس العبيدي', 'زياد الفرجاني', 'رامي الشويهدي',
        'هشام الدرسي', 'وليد المغربي', 'سامي القرقني', 'فادي النعاس', 'باسم العجيلي',
    ],

    'cities' => [
        'طرابلس', 'بنغازي', 'مصراتة', 'الزاوية', 'سبها', 'زليتن', 'الخمس', 'غريان', 'صبراتة', 'درنة',
    ],
];
