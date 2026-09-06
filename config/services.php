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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'semaphore' => [
        'api_key' => env('SEMAPHORE_API_KEY', ''),
        'sender_name' => env('SEMAPHORE_SENDER_NAME', 'PadillaOptical'),
        'endpoint' => env('SEMAPHORE_ENDPOINT', 'https://api.semaphore.co/api/v4/messages'),
        'timeout' => (int) env('SEMAPHORE_TIMEOUT', 10),
        'retries' => (int) env('SEMAPHORE_RETRIES', 2),
        'webhook_secret' => env('SEMAPHORE_WEBHOOK_SECRET'),
        'enabled' => env('SEMAPHORE_ENABLED', false),
    ],

    'textbee' => [
        'api_key' => env('TEXTBEE_API_KEY', ''),
        'device_id' => env('TEXTBEE_DEVICE_ID', ''),
        'endpoint' => env('TEXTBEE_ENDPOINT', 'https://api.textbee.dev/api/v1/gateway/send-sms'),
        'timeout' => (int) env('TEXTBEE_TIMEOUT', 10),
        'retries' => (int) env('TEXTBEE_RETRIES', 2),
        'webhook_secret' => env('TEXTBEE_WEBHOOK_SECRET'),
        'enabled' => env('TEXTBEE_ENABLED', false),
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'semaphore'),
    ],

];
