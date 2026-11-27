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

    'ably' => [
        'key' => env('ABLY_KEY'),
    ],

    // Native app push. Each one is optional: without its keys that kind of
    // push is skipped, the way a missing ABLY_KEY means polling.
    'expo' => [
        // Only needed when "enhanced push security" is on for the Expo project.
        'access_token' => env('EXPO_ACCESS_TOKEN'),
    ],

    'apns' => [
        'key_id' => env('APNS_KEY_ID'),
        'team_id' => env('APNS_TEAM_ID'),
        'key_path' => env('APNS_KEY_PATH'),
        'bundle_id' => env('APNS_BUNDLE_ID'),
        'production' => (bool) env('APNS_PRODUCTION', false),
    ],

    'fcm' => [
        // Empty values fall back: the key at its usual place, and the project
        // id read from that key, so a stray empty line in .env cannot turn
        // Android push off.
        'project_id' => env('FCM_PROJECT_ID') ?: null,
        'credentials_path' => env('FCM_CREDENTIALS_PATH') ?: 'storage/app/firebase-service-account.json',
    ],

];
