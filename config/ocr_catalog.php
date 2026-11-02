<?php

/**
 * Structured OCR catalog matching: families, synonyms, category→system.
 */
return [

    /*
    | Category IDs that imply a pipe/fittings system.
    | 17 accessories + 10 pipes = American; 21 accessories + 9 pipes = European.
    | Gold(19)/gray(18)/orange(12) pipes have no US/EU system.
    */
    'category_system' => [
        17 => 'us',
        10 => 'us',
        21 => 'eu',
        9 => 'eu',
        19 => null,
        18 => null,
        12 => null,
    ],

    'pipe_category_ids' => [10, 9, 19, 18, 12],

    /*
    | Canonical family key => list of normalized synonym tokens / phrases.
    | Matching uses the shared ArabicTextNormalizer first.
    */
    'families' => [
        'coupling' => ['وصله عاديه', 'وصلة عادية', 'وصله عادي', 'وصلة عادي', 'وصله', 'وصلة'],
        'elbow_90' => ['كوع 90', 'كوع٩٠', 'كوع'],
        'elbow_45' => ['كوع 45', 'كوع٤٥'],
        'tee' => ['مثلث عادي', 'مثلث'],
        'wye' => ['واي عادي', 'واي'],
        'end_cap' => ['نهايه خط', 'نهاية خط', 'طابه نهايه خط', 'طابة نهاية خط', 'نهيه', 'نهايه', 'نهية'],
        'male_thread_coupling' => ['وصله سن خارجي', 'وصلة سن خارجي', 'سن خارجي'],
        'female_thread_coupling' => ['وصله سن داخلي', 'وصلة سن داخلي', 'سن داخلي'],
        'female_thread_elbow' => ['كوع سن داخلي'],
        'reducer' => ['مخفض', 'محفض'],
        'door_elbow' => ['كوع باب'],
        'door_tee' => ['مثلث باب'],
        'manifold' => ['مجمع'],
        'trap' => ['مانع رائحه', 'مانع رائحة'],
        'cleanout_ball' => ['طابه تسليك', 'طابة تسليك'],
        'manifold_ball' => ['طابه مجمع', 'طابة مجمع'],
        'cleanout' => ['طابه نظافه', 'طابة نظافة', 'طابه نضافه', 'طابة نضافة'],
        'vent' => ['هاويه', 'هاوية'],
        'cross' => ['صليبه', 'صليبة', 'مربع سوبر', 'مربع', 'كروس'],
        'centra_elbow' => ['كوع سنترا'],
        'centra_tee' => ['مثلث سنترا'],
        'centra_door_elbow' => ['كوع باب سنترا'],
        'centra_door_tee' => ['مثلث باب سنترا'],
        'expansion_coupling' => ['وصله تمدد', 'وصلة تمدد'],
        'repair_coupling' => ['وصله اصلاح', 'وصلة اصلاح', 'وصلة إصلاح'],
        'short_coupling' => ['وصله قصيره', 'وصلة قصيرة'],
        'drain_extender' => ['مطول بالوعه', 'مطول بالوعة'],
        's_trap' => ['حرف اس', 'حرف س'],
        'drain_assembly' => ['وصله تجميع صرف', 'وصلة تجميع صرف'],
        'flexible' => ['جرجوري'],
        'adapter_114_110' => ['تحويله 114 الى 110', 'تحويلة 114 الى 110'],
        'yard_drain' => ['صرف حوش'],
        'pipe' => ['ماسوره', 'ماسورة'],
    ],

    /*
    | Tokens that flip angle/system/variant slots.
    */
    'system_words' => [
        'us' => ['امريك', 'امريكي', 'امريكان', 'نظام امريك', 'نظام امريكي', 'اتک', 'اتك'],
        'eu' => ['اوروبي', 'اورو', 'يورو', 'نظام اوروبي', 'ارم'],
    ],

    'variant_tokens' => [
        'door' => ['باب'],
        'centra' => ['سنترا'],
        'male_thread' => ['سن خارجي'],
        'female_thread' => ['سن داخلي'],
        'small' => ['صغير'],
        'large' => ['كبير', 'كبيير'],
        'layers_silent' => ['ثلاث طبقات', 'كاتم صوت', 'كاتم'],
        'gold' => ['ذهبي', 'خط ذهبي'],
        'gray' => ['رمادي'],
        'orange' => ['برتقالي'],
        'white' => ['ابيض', 'أبيض'],
        'ac' => ['تكييف'],
        'electric' => ['كهربائي', 'كهرباء'],
    ],

    'known_data_issues' => [
        ['id' => 260, 'note' => 'قهوة test product'],
        ['id' => 37, 'note' => '3 inch under category 21 duplicates id 259 (category 17)'],
        ['id' => 250, 'note' => 'gold pipe under category 21 duplicates id 116'],
        ['id' => 153, 'note' => 'مانع رائحة 2 بوصة duplicate of 180 with different points'],
        ['id' => 180, 'note' => 'مانع رائحة 2 بوصة duplicate of 153 with different points'],
        ['id' => 62, 'note' => 'typo محفض'],
        ['id' => 72, 'note' => 'typo نضافة'],
        ['id' => 207, 'note' => 'typo "11" instead of 110'],
        ['id' => 167, 'note' => 'typo كبيير'],
        ['id' => null, 'note' => 'mm sizes labeled بوصة in catalog names'],
    ],
];
