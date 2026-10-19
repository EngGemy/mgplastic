<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],



    ],

    'marsol' => [
        'base_url' => env('MARSOL_BASE_URL', 'https://api.marsol.ly'),
        'token' => env('MARSOL_API_TOKEN'),
        'sender_id' => env('MARSOL_SENDER_ID'),
        'app_name' => env('MARSOL_APP_NAME', 'MG Plastic'),
        // Always OTP API (/public/otp/*). SMS API is not available until company is verified.
        'use_otp_api' => true,
    ],

    /*
    | WhatsApp customer alerts (orders + invoices).
    | driver=log → builds message/link only (staff can open wa.me).
    | driver=evolution → Evolution API (Baileys) self-hosted sendText.
    | driver=libyasms → https://docs.libyasms.com WhatsApp text API.
    | driver=http → POST JSON {to,message} to WHATSAPP_API_URL with Bearer token.
    */
    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'log'),
        'share_days' => (int) env('WHATSAPP_SHARE_DAYS', 30),
        'libyasms' => [
            'base_url' => env('WHATSAPP_LIBYASMS_URL', 'https://whatsapp.libyasms.com'),
            'token' => env('WHATSAPP_LIBYASMS_TOKEN'),
            'instance_id' => env('WHATSAPP_LIBYASMS_INSTANCE_ID'),
        ],
        'evolution' => [
            'base_url' => env('WHATSAPP_EVOLUTION_URL'),
            'api_key' => env('WHATSAPP_EVOLUTION_API_KEY'),
            'instance' => env('WHATSAPP_EVOLUTION_INSTANCE', 'mg-plastic'),
            // v2 → {number,text} | v1 → {number,textMessage:{text}}
            'version' => env('WHATSAPP_EVOLUTION_VERSION', 'v2'),
        ],
        'http' => [
            'url' => env('WHATSAPP_API_URL'),
            'token' => env('WHATSAPP_API_TOKEN'),
        ],
    ],

    /*
    | WAHA WhatsApp HTTP API (OTP sync + queued business alerts).
    | POST {url}/api/sendText  Header X-Api-Key  Body {session,chatId,text}
    */
    'waha' => [
        'url' => env('WAHA_URL'),
        'key' => env('WAHA_API_KEY'),
        'session' => env('WAHA_SESSION', 'default'),
        'enabled' => filter_var(env('WHATSAPP_NOTIFY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'admin_phones' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('WHATSAPP_ADMIN_PHONES', '201030003186,218912159316'))
        ))),
        'default_country_code' => preg_replace('/\D+/', '', (string) env('WHATSAPP_DEFAULT_COUNTRY_CODE', '218')) ?: '218',
        // Queue name for business alerts (OTP stays sync). Use "default" unless a dedicated worker exists.
        'queue' => env('WAHA_QUEUE', 'default'),
        // true = send alerts immediately without queue:work (recommended if worker is not running)
        'sync_alerts' => filter_var(env('WAHA_SYNC_ALERTS', true), FILTER_VALIDATE_BOOLEAN),
        // Mobile / web app link embedded in WhatsApp alerts
        'app_url' => env('MG_APP_URL', env('PLUMBER_APP_URL', env('APP_URL'))),
        // Optional cover image for alerts (public URL or storage path)
        'brand_image_url' => env('WAHA_BRAND_IMAGE_URL'),
        'brand_image_path' => env('WAHA_BRAND_IMAGE_PATH'),
    ],

    /*
    | OTP delivery channels: whatsapp, sms, or both (comma-separated).
    | WhatsApp uses local hashed OTP. SMS uses Marsol OTP API (own code) —
    | verify accepts either channel via PhoneOtpService::matches().
    */
    'otp' => [
        'channels' => env('OTP_CHANNELS', 'whatsapp,sms'),
    ],

    'ffmpeg' => [
        'ffmpeg_binaries' => env('FFMPEG_BINARIES', PHP_OS_FAMILY === 'Windows' ? 'ffmpeg.exe' : 'ffmpeg'),
        'ffprobe_binaries' => env('FFPROBE_BINARIES', PHP_OS_FAMILY === 'Windows' ? 'ffprobe.exe' : 'ffprobe'),
    ],

    /*
    | Google Gemini — decision Q&A inside admin reports.
    | Get a key: https://aistudio.google.com/apikey
    */
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 45),
    ],

];
