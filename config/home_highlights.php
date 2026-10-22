<?php

/**
 * Green banner icons on wholesale home (المشاريع · الصرف الصحي · مياه الشرب).
 * category_match: Arabic/English fragments to resolve category_id dynamically.
 */
return [
    [
        'key' => 'projects',
        'label' => 'المشاريع',
        'icon' => 'projects',
        'link' => null,
        'deep_link' => 'mgplastic://catalog',
        'category_match' => [],
    ],
    [
        'key' => 'sewage',
        'label' => 'الصرف الصحي',
        'icon' => 'sewage',
        'link' => null,
        'deep_link' => null,
        'category_match' => ['صرف', 'sewer', 'drainage', 'خارجي', 'external'],
    ],
    [
        'key' => 'drinking_water',
        'label' => 'مياه الشرب',
        'icon' => 'water',
        'link' => null,
        'deep_link' => null,
        'category_match' => ['مياه', 'شرب', 'ضغط', 'pressure', 'water'],
    ],
];
