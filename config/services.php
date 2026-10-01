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

    'youtube' => [
        'key' => env('YOUTUBE_API_KEY'),
        'daily_quota' => (int) env('YOUTUBE_DAILY_QUOTA', 10000),
        'safety_margin' => (int) env('YOUTUBE_QUOTA_SAFETY_MARGIN', 1000),
        'alert_at' => (int) env('YOUTUBE_QUOTA_ALERT_AT', 70),
    ],

    /*
     * The payment operator: HotPay.
     *
     * Both values come from the HotPay panel - "kod sekretny uslugi" (the
     * secret) and "haslo z ustawien" (the password). The password serves ONLY
     * to compute signatures and never leaves the server; the secret is public
     * and travels inside the form.
     */
    'hotpay' => [
        'secret' => env('HOTPAY_SECRET'),
        'password' => env('HOTPAY_PASSWORD'),
    ],

];
