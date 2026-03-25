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

    'aws' => [
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

    'image_embedding' => [
        'url' => env('IMAGE_EMBEDDING_API_URL'),
        'model' => env('IMAGE_EMBEDDING_MODEL', 'clip-vit-base-patch32'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
    ],

    /*
    | Mailtrap Email Sending API (railsware/mailtrap-php). Required when MAIL_MAILER=mailtrap-sdk.
    | The API token is passed as the Symfony Mailer DSN "user"; if empty you get IncompleteDsnException: User is not set.
    | Env: MAILTRAP_API_KEY or MAILTRAP_API_TOKEN (both supported).
    | @see https://docs.mailtrap.io/developers
    | @see https://mailtrap.io/api-tokens
    */
    'mailtrap-sdk' => [
        'host' => env('MAILTRAP_HOST', 'send.api.mailtrap.io'),
        // Mailtrap docs say "API token"; support MAILTRAP_API_TOKEN as well as MAILTRAP_API_KEY.
        'apiKey' => env('MAILTRAP_API_KEY') ?: env('MAILTRAP_API_TOKEN'),
        'inboxId' => env('MAILTRAP_INBOX_ID'),
    ],

    /*
    | OneSignal (web push — external user id matches numeric User id after OneSignal.login).
    */
    'onesignal' => [
        'app_id' => env('ONESIGNAL_APP_ID'),
        'rest_api_key' => env('ONESIGNAL_REST_API_KEY'),
        /** When true, Blade emits meta so the web SDK may use HTTP (see pushService.js + OneSignal v16). */
        'allow_http_local' => filter_var(env('ONESIGNAL_ALLOW_HTTP_LOCAL', false), FILTER_VALIDATE_BOOLEAN),
    ],

];
