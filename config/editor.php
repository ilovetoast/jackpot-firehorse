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
        /** Max AssetVersion rows per generative layer asset (metadata.generative_layer_uuid). */
        'max_versions_per_layer' => (int) env('EDITOR_GENERATIVE_MAX_VERSIONS_PER_LAYER', 20),
        /** Days after {@code lifecycle=orphaned} before hard delete (generative_layer AI assets only). */
        'orphan_cleanup_days' => max(1, (int) env('GENERATIVE_ORPHAN_CLEANUP_DAYS', 7)),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI provenance (DAM JSON metadata, not binary XMP)
    |--------------------------------------------------------------------------
    |
    | Shown in asset.metadata["jackpot_ai_provenance"] for editor publish and persisted generative layers.
    |
    */
    'provenance' => [
        'vendor_name' => env('JACKPOT_PROVENANCE_VENDOR', 'Velvetysoft'),
        /** Empty string → "{APP_NAME} Generative Editor" */
        'generator_label' => env('JACKPOT_PROVENANCE_GENERATOR_LABEL', ''),
    ],

];
