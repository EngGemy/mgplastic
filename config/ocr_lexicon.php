<?php

/**
 * Trade vocabulary for invoice OCR (Libyan/Egyptian dialect, loanwords, plurals, misspellings).
 *
 * confirmed=true  → may auto-accept when score + margin rules pass.
 * confirmed=false → suggestions only (never auto-accept).
 *
 * Lexicon family keys map to catalog_family via 'catalog_family_map'.
 */
return [

    'catalog_family_map' => [
        'pipe' => 'pipe',
        'tee' => 'tee',
        'y_tee' => 'wye',
        'access_tee' => 'door_tee',
        'access_elbow' => 'door_elbow',
        'elbow' => 'elbow_90', // angle 45 → elbow_45 at parse time
        'coupling' => 'coupling',
        'reducer' => 'reducer',
        'end_cap' => 'end_cap',
        'plug' => 'cleanout',
        'floor_trap' => 'manifold',
        'odor_trap' => 'trap',
        'vent_cap' => 'vent',
        'cross' => 'cross',
    ],

    /*
    | Explicit broken plurals → singular lemma (after normalize).
    */
    'broken_plurals' => [
        'اكواع' => 'كوع',
        'مواسير' => 'ماسوره',
        'مجامع' => 'مجمع',
        'مخافض' => 'مخفض',
        'طوابي' => 'طوبو',
        'مثلثات' => 'مثلث',
        'وصلات' => 'وصله',
        'مخفضات' => 'مخفض',
        'ماسورات' => 'ماسوره',
    ],

    /*
    | Ambiguous lemmas → multiple lexicon family keys (needs user choice).
    */
    'ambiguous_lemmas' => [
        'مثلث' => ['tee', 'y_tee'], // bare مثلثات / مثلث without واي|باب
    ],

    'units' => [
        'in' => ['بوصه', 'بوصة', 'انش', 'إنش', 'انج', 'inch', 'in', '"'],
        'mm' => ['مم', 'ملم', 'ميلي', 'مليم', 'mm'],
    ],

    /*
    | Size dialect helpers applied after digit extraction.
    | "ونص" / "و نص" after a number → +0.5; bare "نص" → 0.5; "وربع" → +0.25.
    */
    'size_words' => [
        'half_add' => ['ونص', 'و نص', 'و نصف', 'ونصف'],
        'half' => ['نص', 'نصف'],
        'quarter_add' => ['وربع', 'و ربع', 'و ربعه'],
        'fractions' => [
            '1/2' => 0.5,
            '½' => 0.5,
            '3/4' => 0.75,
            '¾' => 0.75,
            '1/4' => 0.25,
            '¼' => 0.25,
        ],
        'number_words' => [
            'واحد' => 1,
            'واحده' => 1,
            'اتنين' => 2,
            'اثنين' => 2,
            'اثنان' => 2,
            'زوز' => 2, // Maghrebi — unconfirmed
            'ثلاثه' => 3,
            'ثلاثة' => 3,
            'تلاته' => 3,
            'تلاتة' => 3,
            'اربعه' => 4,
            'اربعة' => 4,
            'اربعة' => 4,
            'خمسه' => 5,
            'خمسة' => 5,
            'سته' => 6,
            'ستة' => 6,
        ],
        'number_words_confirmed' => [
            'واحد' => true,
            'واحده' => true,
            'اتنين' => true,
            'اثنين' => true,
            'اثنان' => true,
            'زوز' => false,
            'ثلاثه' => true,
            'ثلاثة' => true,
            'تلاته' => true,
            'تلاتة' => true,
            'اربعه' => true,
            'اربعة' => true,
            'خمسه' => true,
            'خمسة' => true,
            'سته' => true,
            'ستة' => true,
        ],
    ],

    /*
    | Confusable letter groups for weighted Levenshtein (cost 0.3).
    */
    'confusable_groups' => [
        ['ب', 'ت', 'ث', 'ن', 'ي'],
        ['ج', 'ح', 'خ'],
        ['د', 'ذ'],
        ['ر', 'ز'],
        ['س', 'ش'],
        ['ص', 'ض'],
        ['ط', 'ظ', 'ت'],
        ['ع', 'غ'],
        ['ف', 'ق'],
        ['ه', 'ة'],
        ['ا', 'أ', 'إ', 'آ'],
        ['و', 'ؤ'],
    ],

    /*
    | Fuzzy thresholds by token length (max weighted distance to accept as candidate).
    | Auto-accept also requires confirmed=true and margin over runner-up.
    */
    'fuzzy' => [
        'max_distance' => [
            1 => 0.0,
            2 => 0.0,
            3 => 0.35,
            4 => 0.55,
            5 => 0.75,
            6 => 0.95,
            'default' => 1.15,
        ],
        'auto_accept_max' => 0.35,
        'auto_accept_margin' => 0.25,
    ],

    'gemini_reading_hints_limit' => 120,

    'gemini_normalizer' => [
        'enabled' => false, // config flag; OFF by default
        'cache_days' => 30,
        'daily_cap' => 50,
    ],

    'alias_promotion' => [
        'min_hits' => 3,
        'min_distinct_users' => 2,
    ],

    /*
    | Family vocabulary. Each entry: term + confirmed.
    */
    'families' => [

        'pipe' => [
            'catalog_name' => 'ماسورة',
            'english' => 'pipe',
            'synonyms' => [
                'ly' => [
                    ['term' => 'طوبو', 'confirmed' => true],
                    ['term' => 'توبو', 'confirmed' => false],
                    ['term' => 'طوبوا', 'confirmed' => false],
                    ['term' => 'طوبوو', 'confirmed' => false],
                    ['term' => 'طوابي', 'confirmed' => false],
                ],
                'eg' => [
                    ['term' => 'ماسورة', 'confirmed' => true],
                    ['term' => 'ماسوره', 'confirmed' => true],
                ],
                'plural' => [
                    ['term' => 'مواسير', 'confirmed' => true],
                    ['term' => 'ماسورات', 'confirmed' => false],
                ],
                'loanword' => [
                    ['term' => 'tube', 'confirmed' => false],
                    ['term' => 'tubo', 'confirmed' => false],
                ],
                'english' => [
                    ['term' => 'pipe', 'confirmed' => true],
                ],
                'misspelling' => [],
            ],
        ],

        'tee' => [
            'catalog_name' => 'مثلث',
            'english' => 'tee',
            'synonyms' => [
                'eg' => [
                    ['term' => 'مثلث', 'confirmed' => true],
                    ['term' => 'تي', 'confirmed' => false],
                    ['term' => 'T', 'confirmed' => false],
                ],
                'plural' => [
                    ['term' => 'مثلثات', 'confirmed' => false], // ambiguous with y_tee
                ],
                'english' => [
                    ['term' => 'tee', 'confirmed' => true],
                ],
                'ly' => [],
                'loanword' => [],
                'misspelling' => [],
            ],
        ],

        'y_tee' => [
            'catalog_name' => 'مثلث واي',
            'english' => 'wye',
            'synonyms' => [
                'eg' => [
                    ['term' => 'مثلث واي', 'confirmed' => true],
                    ['term' => 'واي', 'confirmed' => true],
                ],
                'english' => [
                    ['term' => 'Y', 'confirmed' => false],
                    ['term' => 'wye', 'confirmed' => true],
                ],
                'ly' => [],
                'plural' => [],
                'loanword' => [],
                'misspelling' => [],
            ],
        ],

        'access_tee' => [
            'catalog_name' => 'مثلث باب',
            'english' => 'access tee',
            'synonyms' => [
                'eg' => [
                    ['term' => 'مثلث باب', 'confirmed' => true],
                ],
                'ly' => [],
                'plural' => [],
                'loanword' => [],
                'english' => [],
                'misspelling' => [],
            ],
        ],

        'access_elbow' => [
            'catalog_name' => 'كوع باب',
            'english' => 'access elbow',
            'synonyms' => [
                'eg' => [
                    ['term' => 'كوع باب', 'confirmed' => true],
                ],
                'ly' => [],
                'plural' => [],
                'loanword' => [],
                'english' => [],
                'misspelling' => [],
            ],
        ],

        'elbow' => [
            'catalog_name' => 'كوع',
            'english' => 'elbow',
            'synonyms' => [
                'eg' => [
                    ['term' => 'كوع', 'confirmed' => true],
                    ['term' => 'كوعة', 'confirmed' => false],
                ],
                'plural' => [
                    ['term' => 'أكواع', 'confirmed' => true],
                    ['term' => 'اكواع', 'confirmed' => true],
                ],
                'english' => [
                    ['term' => 'elbow', 'confirmed' => true],
                ],
                'ly' => [],
                'loanword' => [],
                'misspelling' => [],
            ],
        ],

        'coupling' => [
            'catalog_name' => 'وصلة',
            'english' => 'coupling',
            'synonyms' => [
                'eg' => [
                    ['term' => 'وصلة', 'confirmed' => true],
                    ['term' => 'وصله', 'confirmed' => true],
                ],
                'plural' => [
                    ['term' => 'وصلات', 'confirmed' => true],
                ],
                'loanword' => [
                    ['term' => 'مانشون', 'confirmed' => false],
                    ['term' => 'كوبلن', 'confirmed' => false],
                ],
                'english' => [
                    ['term' => 'coupling', 'confirmed' => true],
                ],
                'ly' => [],
                'misspelling' => [],
            ],
        ],

        'reducer' => [
            'catalog_name' => 'مخفض',
            'english' => 'reducer',
            'synonyms' => [
                'eg' => [
                    ['term' => 'مخفض', 'confirmed' => true],
                ],
                'plural' => [
                    ['term' => 'مخافض', 'confirmed' => true],
                    ['term' => 'مخفضات', 'confirmed' => false],
                ],
                'loanword' => [
                    ['term' => 'ريدكشن', 'confirmed' => false],
                ],
                'english' => [
                    ['term' => 'reducer', 'confirmed' => true],
                ],
                'ly' => [],
                'misspelling' => [
                    ['term' => 'محفض', 'confirmed' => false],
                ],
            ],
        ],

        'end_cap' => [
            'catalog_name' => 'نهاية خط',
            'english' => 'end cap',
            'synonyms' => [
                'eg' => [
                    ['term' => 'نهاية خط', 'confirmed' => true],
                    ['term' => 'نهايه خط', 'confirmed' => true],
                    ['term' => 'نهاية', 'confirmed' => false],
                    ['term' => 'نهايه', 'confirmed' => false],
                ],
                'loanword' => [
                    ['term' => 'كاب', 'confirmed' => false],
                ],
                'english' => [
                    ['term' => 'cap', 'confirmed' => false],
                ],
                'ly' => [],
                'plural' => [],
                'misspelling' => [],
            ],
        ],

        'plug' => [
            'catalog_name' => 'طابة',
            'english' => 'plug',
            'synonyms' => [
                'eg' => [
                    ['term' => 'طابة', 'confirmed' => false],
                    ['term' => 'طابه', 'confirmed' => false],
                ],
                'plural' => [
                    ['term' => 'طوابي', 'confirmed' => false],
                ],
                'english' => [
                    ['term' => 'plug', 'confirmed' => false],
                ],
                'ly' => [],
                'loanword' => [],
                'misspelling' => [],
            ],
        ],

        'floor_trap' => [
            'catalog_name' => 'مجمع',
            'english' => 'floor trap',
            'synonyms' => [
                'eg' => [
                    ['term' => 'مجمع', 'confirmed' => true],
                ],
                'plural' => [
                    ['term' => 'مجامع', 'confirmed' => true],
                ],
                'english' => [
                    ['term' => 'trap', 'confirmed' => false],
                ],
                'ly' => [],
                'loanword' => [],
                'misspelling' => [],
            ],
        ],

        'odor_trap' => [
            'catalog_name' => 'مانع رائحة',
            'english' => 'odor trap',
            'synonyms' => [
                'eg' => [
                    ['term' => 'مانع رائحة', 'confirmed' => true],
                    ['term' => 'مانع رائحه', 'confirmed' => true],
                ],
                'ly' => [],
                'plural' => [],
                'loanword' => [],
                'english' => [],
                'misspelling' => [],
            ],
        ],

        'vent_cap' => [
            'catalog_name' => 'هاوية',
            'english' => 'vent cap',
            'synonyms' => [
                'eg' => [
                    ['term' => 'هاوية', 'confirmed' => true],
                    ['term' => 'هاويه', 'confirmed' => true],
                ],
                'ly' => [],
                'plural' => [],
                'loanword' => [],
                'english' => [],
                'misspelling' => [],
            ],
        ],

        'cross' => [
            'catalog_name' => 'صليبة',
            'english' => 'cross',
            'synonyms' => [
                'eg' => [
                    ['term' => 'صليبة', 'confirmed' => true],
                    ['term' => 'صليبه', 'confirmed' => true],
                    ['term' => 'مربع سوبر', 'confirmed' => true],
                    ['term' => 'سوبر', 'confirmed' => false],
                ],
                'loanword' => [
                    ['term' => 'كروس', 'confirmed' => false],
                ],
                'english' => [
                    ['term' => 'cross', 'confirmed' => false],
                ],
                'ly' => [],
                'plural' => [],
                'misspelling' => [],
            ],
        ],
    ],
];
