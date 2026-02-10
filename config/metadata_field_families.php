<?php

/**
 * Metadata Field Families (Phase K)
 *
 * Presentation-only grouping for admin metadata management.
 * Does NOT affect storage, querying, or API payloads.
 * Individual fields remain independently addressable.
 *
 * If a family key is missing or invalid, fall back to rendering individual fields.
 */
return [
    'type' => [
        'label' => 'Type',
        'description' => 'Category-specific type classification',
        'fields' => [
            'photo_type',
            'logo_type',
            'graphic_type',
            'video_type',
            'execution_video_type',
            'print_type',
            'digital_type',
            'ooh_type',
            'event_type',
            'radio_type',
            'packaging_type',
            'sales_collateral_type',
            'pr_type',
            'template_type',
            'audio_type',
            'model_3d_type',
            'product_render_type',
        ],
        'shared_defaults' => [
            'is_primary' => true,
            'is_filterable' => true,
            'is_upload_visible' => true,
        ],
    ],
];
