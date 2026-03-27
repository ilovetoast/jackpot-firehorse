<?php

/**
 * Application-level error recording (non-exception flows).
 *
 * Keys here must NOT live in config/sentry.php — that file is merged into the Sentry SDK
 * OptionsResolver and only supports documented Sentry client options.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Sentry captureMessage for application_error_events
    |--------------------------------------------------------------------------
    |
    | When true, selected AI/provider failures recorded in application_error_events
    | also call Sentry::captureMessage (warning) so overload/rate-limit noise is visible.
    |
    */
    'sentry_capture_messages' => env('SENTRY_APPLICATION_ERROR_MESSAGES', true),

];
