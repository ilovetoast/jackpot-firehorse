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

    /*
     * STRIPE_SECRET selects the Stripe account + mode (test vs live). Customer and subscription IDs in the DB
     * are not portable: restoring production tenants into staging with staging keys causes "No such customer"
     * until stripe_id is repaired (see `billing:repair-stripe-customer` and BillingService::ensureValidStripeCustomer).
     */
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
    ],

    /** fal.ai (Studio SAM2 — base URL and model id from env, not hardcoded in services). */
    'fal' => [
        'key' => env('FAL_KEY'),
        'api_base' => rtrim((string) env('FAL_API_BASE', 'https://fal.run'), '/'),
        /** Used when the sync endpoint returns a queue request_id (poll until COMPLETED). */
        'queue_base' => rtrim((string) env('FAL_QUEUE_BASE', 'https://queue.fal.run'), '/'),
        'sam2_endpoint' => env('FAL_SAM2_ENDPOINT', 'https://fal.run/fal-ai/sam2/image'),
        /** Optional Fal /models or /api/pricing look-ups for studio SAM cost estimate. */
        'sam2_pricing_model_id' => (string) env('FAL_SAM2_PRICING_MODEL_ID', 'fal-ai/sam2/image'),
    ],

    'replicate' => [
        'api_token' => env('REPLICATE_API_TOKEN'),
    ],

    'clipdrop' => [
        'key' => env('CLIPDROP_API_KEY'),
        'cleanup_endpoint' => env('CLIPDROP_CLEANUP_ENDPOINT', 'https://clipdrop-api.co/cleanup/v1'),
    ],

    /*
    | Sales / inbound lead routing. `notify_to` is the inbox that receives
    | new public contact form and newsletter submissions. Leave SALES_NOTIFY_EMAIL
    | unset to fall back to MAIL_FROM_ADDRESS. Accepts a single address or
    | comma-separated list.
    */
    'sales' => [
        'notify_to' => env('SALES_NOTIFY_EMAIL'),
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
        /** Must use config(), not env(), in app code — env() is empty when config is cached. */
        'push_enabled' => filter_var(env('PUSH_NOTIFICATIONS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'app_id' => filled($v = trim((string) (env('ONESIGNAL_APP_ID') ?? ''))) ? $v : null,
        'rest_api_key' => filled($v = trim((string) (env('ONESIGNAL_REST_API_KEY') ?? ''))) ? $v : null,
        /** Optional: Organization API key — required for GET /apps/{id} (see onesignal:verify-app). REST key alone may be 403. */
        'organization_api_key' => filled($v = trim((string) (env('ONESIGNAL_ORGANIZATION_API_KEY') ?? ''))) ? $v : null,
        /** When true, Blade emits meta so the web SDK may use HTTP (see pushService.js + OneSignal v16). */
        'allow_http_local' => filter_var(env('ONESIGNAL_ALLOW_HTTP_LOCAL', false), FILTER_VALIDATE_BOOLEAN),
    ],

];
