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
        // Shown only when using branded /public/sms/send (MARSOL_USE_OTP_API=false).
        // Marsol OTP API template uses the project name from the Marsol dashboard.
        'app_name' => env('MARSOL_APP_NAME', 'MG Plastic'),
        // true (default): /public/otp/initiate — works even when SMS API is locked.
        // false: branded /public/sms/send — requires a verified Marsol account.
        'use_otp_api' => filter_var(env('MARSOL_USE_OTP_API', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'ffmpeg' => [
        'ffmpeg_binaries' => env('FFMPEG_BINARIES', PHP_OS_FAMILY === 'Windows' ? 'ffmpeg.exe' : 'ffmpeg'),
        'ffprobe_binaries' => env('FFPROBE_BINARIES', PHP_OS_FAMILY === 'Windows' ? 'ffprobe.exe' : 'ffprobe'),
    ],

];
