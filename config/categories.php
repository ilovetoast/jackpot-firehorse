<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Max visible categories per brand (by asset_type)
    |--------------------------------------------------------------------------
    |
    | Non-hidden categories count toward this cap. Applies to asset and
    | deliverable libraries only (not reference_material / REFERENCE).
    |
    */
    'max_visible_per_brand_by_asset_type' => [
        'asset' => 20,
        'deliverable' => 20,
    ],
];
