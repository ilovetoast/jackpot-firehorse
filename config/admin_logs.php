<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deploy script log (admin viewer)
    |--------------------------------------------------------------------------
    |
    | Shown on Admin Logs → Deploy tab (last N lines). Must be readable by the
    | PHP/web user on the server.
    |
    */

    'deploy_log_path' => env('ADMIN_DEPLOY_LOG_PATH', '/var/www/jackpot/deploy/deploy.log'),

    'deploy_log_tail_lines' => (int) env('ADMIN_DEPLOY_LOG_TAIL_LINES', 100),

];
