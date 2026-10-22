<?php

/**
 * Green banner icons on wholesale home (المشاريع · الصرف الصحي · مياه الشرب).
 * category_match: fragments to resolve category_id.
 * category_prefer / category_exclude: refine which category wins.
 */
return [
    [
        'key' => 'projects',
        'label' => 'المشاريع',
        'icon' => 'projects',
        'link' => null,
        'deep_link' => 'mgplastic://catalog',
        'category_match' => ['مشروع', 'projects', 'ملحق'],
    ],
    [
        'key' => 'sewage',
        'label' => 'الصرف الصحي',
        'icon' => 'sewage',
        'link' => null,
        'deep_link' => null,
        'category_match' => ['صرف', 'sewer', 'drainage', 'خارجي', 'external', 'برتق', 'رماد', 'ابار', 'آبار'],
        'category_prefer' => ['صرف', 'برتق', 'رماد'],
        'category_exclude' => ['اوروب', 'أوروب', 'امريك', 'أمريك', 'ذهب', 'europ', 'american'],
    ],
    [
        'key' => 'drinking_water',
        'label' => 'مياه الشرب',
        'icon' => 'water',
        'link' => null,
        'deep_link' => 'mgplastic://catalog?systems=1',
        'category_match' => [
            'شرب', 'مياه', 'ضغط', 'pressure', 'water',
            'اوروب', 'أوروب', 'امريك', 'أمريك', 'ذهب', 'مواسير', 'انابيب', 'أنابيب',
            'europ', 'american', 'gold',
        ],
        'category_prefer' => ['اوروب', 'أوروب', 'امريك', 'أمريك', 'europ', 'american'],
        'category_exclude' => ['صرف', 'sewer', 'برتق', 'رماد', 'ابار', 'آبار', 'ملحق'],
    ],
];
