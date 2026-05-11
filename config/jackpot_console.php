<?php

/**
 * Browser console banner (see resources/js/utils/jackpotConsoleBanner.js).
 * Prefer commit time from CI / release manifest; git is only used on local/staging when .git exists.
 */
return [
    'build_time' => env('APP_BUILD_TIME'),
];
