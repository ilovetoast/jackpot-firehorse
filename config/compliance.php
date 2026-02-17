<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Stuck Pipeline Timeout (minutes)
    |--------------------------------------------------------------------------
    |
    | Assets with analysis_status in a transient state (generating_thumbnails,
    | extracting_metadata, generating_embedding, scoring) for longer than this
    | are considered stuck. compliance:recover-stuck resets and requeues them.
    |
    */
    'stuck_timeout_minutes' => (int) env('COMPLIANCE_STUCK_TIMEOUT_MINUTES', 30),
];
