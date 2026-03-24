<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Generative editor (AI images in canvas)
    |--------------------------------------------------------------------------
    |
    | When persist is true, generated/edited images are stored on the tenant
    | bucket as Asset rows (type ai_generated) and the API returns a stable
    | /app/api/assets/{id}/file URL. When false or on failure, the legacy
    | same-origin proxy URL (short TTL cache) is used.
    |
    */
    'generative' => [
        'persist' => env('EDITOR_GENERATIVE_PERSIST', true),
    ],

];
