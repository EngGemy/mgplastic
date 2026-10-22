<?php

/**
 * Wholesale home «الأقسام الرئيسية» — piece types (not pipe systems).
 * Matching uses product tags + Arabic/English name keywords.
 */
return [
    [
        'key' => 'water_pipe',
        'name' => 'مواسير مياه',
        'subtitle' => 'جميع المقاسات',
        'icon' => 'pipe',
        // Set after uploading to storage/app/public/product-types/… — missing files → image_url null
        'image' => null,
        'keywords' => ['ماسورة', 'مواسير', 'انبوب', 'أنبوب', 'pipe', 'pipes', 'مياه'],
        'exclude_keywords' => ['كوع', 'تيه', 'تي', 'محبس', 'صمام', 'سداد', 'وصلة', 'elbow', 'tee', 'valve', 'cap'],
    ],
    [
        'key' => 'tee',
        'name' => 'وصلات T',
        'subtitle' => 'تيهات بجميع المقاسات',
        'icon' => 'tee',
        'image' => null,
        'keywords' => ['تيه', 'تي ', 'تي-', 'وصلة t', 'وصلة تي', 'tee', 't-piece', 't piece'],
        'exclude_keywords' => [],
    ],
    [
        'key' => 'valve_fitting',
        'name' => 'محابس ووصلات',
        'subtitle' => 'محابس · وصلات · صمامات',
        'icon' => 'valve',
        'image' => null,
        'keywords' => ['محبس', 'محابس', 'صمام', 'وصلة', 'coupling', 'valve', 'fitting', 'union'],
        'exclude_keywords' => ['تيه', 'tee', 'كوع', 'elbow', 'سداد', 'cap'],
    ],
    [
        'key' => 'elbow',
        'name' => 'أكواع',
        'subtitle' => 'جميع المقاسات',
        'icon' => 'elbow',
        'image' => null,
        'keywords' => ['كوع', 'أكواع', 'اكواع', 'elbow'],
        'exclude_keywords' => [],
    ],
    [
        'key' => 'cap',
        'name' => 'سدادات',
        'subtitle' => 'سدادات ونهايات',
        'icon' => 'cap',
        'image' => null,
        'keywords' => ['سداد', 'سدادة', 'سدادات', 'غطاء', 'cap', 'end cap', 'plug'],
        'exclude_keywords' => [],
    ],
];
