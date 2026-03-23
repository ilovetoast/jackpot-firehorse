<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Automation / system emails
    |--------------------------------------------------------------------------
    |
    | When false (default), mailables with emailType "system" are not sent.
    | User-initiated mailables (emailType "user") always send. See docs/email-notifications.md.
    |
    */

    'automations_enabled' => filter_var(env('MAIL_AUTOMATIONS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        /*
        |--------------------------------------------------------------------------
        | Mailtrap (alias)
        |--------------------------------------------------------------------------
        |
        | Some environments set MAIL_MAILER=mailtrap. Mailtrap uses SMTP; this is
        | the same transport as "smtp" with MAIL_HOST / MAIL_PORT / credentials.
        |
        */
        // 'mailtrap' => [
        //     'transport' => 'smtp',
        //     'scheme' => env('MAIL_SCHEME'),
        //     'url' => env('MAIL_URL'),
        //     'host' => env('MAIL_HOST', 'sandbox.smtp.mailtrap.io'),
        //     'port' => env('MAIL_PORT', 2525),
        //     'username' => env('MAIL_USERNAME'),
        //     'password' => env('MAIL_PASSWORD'),
        //     'timeout' => null,
        //     'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        // ],

        'mailtrap' => [
            'transport' => 'mailtrap',
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant mail branding (staging)
    |--------------------------------------------------------------------------
    |
    | Single verified From domain; per-tenant display name and optional Reply-To.
    | enabled: null = only when APP_ENV=staging; true/false to override (e.g. tests).
    |
    */

    'tenant_branding' => [
        'enabled' => ($v = env('MAIL_TENANT_BRANDING_ENABLED')) === null
            ? null
            : filter_var($v, FILTER_VALIDATE_BOOLEAN),
        'from_address' => env('MAIL_STAGING_BRAND_FROM_ADDRESS', 'no-reply@staging-jackpot.velvetysoft.com'),
    ],

];
