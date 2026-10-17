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
