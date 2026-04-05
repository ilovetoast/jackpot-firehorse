<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Batched upload notification window (minutes)
    |--------------------------------------------------------------------------
    |
    | Batch keys align to this window so uploads in the same interval group
    | into one approver notification. The processing job waits until this
    | many minutes have passed since the last upload in the batch.
    |
    */
    'batch_window_minutes' => (int) env('PROSTAFF_BATCH_WINDOW_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Maximum time a batch may stay open (minutes)
    |--------------------------------------------------------------------------
    |
    | After started_at + this duration, the job sends even if uploads keep
    | extending last_activity_at (prevents infinite release loops).
    |
    */
    'max_batch_duration_minutes' => (int) env('PROSTAFF_MAX_BATCH_DURATION_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Optional dedicated permission for prostaff batch approvers
    |--------------------------------------------------------------------------
    |
    | If any tenant user with active brand membership has this permission on
    | the brand (or tenant), they receive batched prostaff upload alerts first.
    | When nobody matches, recipients fall back to normal approval-capable users.
    |
    */
    'batch_notification_permission' => env('PROSTAFF_BATCH_NOTIFICATION_PERMISSION', 'brand.prostaff.approve'),

];
