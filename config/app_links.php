<?php

/**
 * Universal / App Links for opening the MG mobile app from WhatsApp captions.
 *
 * Production IDs (do not use placeholders on mg-plastic.com):
 * - Android package: com.MgTools
 * - iOS appID: LP86Z6FUHH.com.mgPlumbers.app
 *
 * After changing .env on the server: php artisan config:clear
 * Apple CDN may cache AASA for up to ~24h — verify via:
 * https://app-site-association.cdn-apple.com/a/v1/mg-plastic.com
 */
return [
    'scheme' => env('APP_SCHEME', 'mgplastic'),
    'package' => env('APP_PACKAGE', 'com.MgTools'),
    // Public host used in captions (must serve this Laravel app for /open + well-known)
    'public_base' => rtrim((string) env('APP_LINKS_BASE', env('APP_URL', 'https://mg-plastic.com')), '/'),
    'android_store_url' => env('APP_ANDROID_URL', ''),
    'ios_store_url' => env('APP_IOS_URL', ''),
    // Play App Signing SHA-256 fingerprint(s), comma-separated
    'android_sha256_fingerprints' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'APP_ANDROID_SHA256',
            '2C:C3:68:A2:7B:57:DE:E8:19:DA:0A:05:B3:87:58:B6:68:1F:7D:94:BD:C6:A9:D8:68:18:FD:89:8A:C8:D9:E1,A7:96:F1:20:2C:86:8E:E1:ED:9A:70:D1:F8:7F:AE:5B:89:E5:7E:98:F7:A0:50:18:36:86:A4:BE:8F:E0:59:4B'
        ))
    ))),
    'apple_team_id' => env('APP_APPLE_TEAM_ID', 'LP86Z6FUHH'),
    // Full Apple appID = TeamID.bundle — never invent this from the Android package
    'apple_app_id' => env('APP_APPLE_APP_ID', 'LP86Z6FUHH.com.mgPlumbers.app'),
    'ios_bundle_id' => env('APP_IOS_BUNDLE_ID', 'com.mgPlumbers.app'),

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
