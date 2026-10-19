<?php

/**
 * Universal / App Links for opening the MG mobile app from WhatsApp captions.
 *
 * Fill APP_ANDROID_SHA256 (colon-separated cert fingerprints) and APP_APPLE_TEAM_ID
 * before publishing /.well-known/* to production.
 */
return [
    'scheme' => env('APP_SCHEME', 'mgplastic'),
    'package' => env('APP_PACKAGE', 'com.mgplastic.app'),
    // Public host used in captions (must serve this Laravel app for /open + well-known)
    'public_base' => rtrim((string) env('APP_LINKS_BASE', env('APP_URL', 'https://mg-plastic.com')), '/'),
    'android_store_url' => env('APP_ANDROID_URL', ''),
    'ios_store_url' => env('APP_IOS_URL', ''),
    // Placeholder — replace with Play App Signing SHA-256 fingerprints
    'android_sha256_fingerprints' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('APP_ANDROID_SHA256', 'AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99'))
    ))),
    // Placeholder — replace with Apple Developer Team ID
    'apple_team_id' => env('APP_APPLE_TEAM_ID', 'TEAMIDXXXX'),
    'apple_app_id' => env('APP_APPLE_APP_ID', ''), // e.g. TEAMID.com.mgplastic.app

    'types' => [
        'invoice',
        'order',
        'return',
        'points',
        'supply',
        'withdrawal',
        'home',
    ],
];
