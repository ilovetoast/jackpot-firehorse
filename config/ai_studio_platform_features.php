<?php

/**
 * Platform-wide Studio / vendor API feature switches (not per-tenant).
 *
 * Defaults apply when no row exists in {@see \App\Models\AIStudioPlatformFeatureToggle}.
 * Admin: AI Dashboard → Studio platform features.
 */
return [
    'features' => [
        'studio_composition_animation' => [
            'label' => 'Still → AI video (Studio composition animation)',
            'description' => 'Image-to-video jobs from the composition editor (e.g. Kling). When off, the control is hidden in Studio and API creation is rejected.',
            'default_enabled' => true,
        ],
        'studio_layer_extraction_ai' => [
            'label' => 'Extract layers — AI segmentation',
            'description' => 'Remote segmentation (Fal/SAM, etc.). When off, only local extraction is offered regardless of keys.',
            'default_enabled' => true,
        ],
        'studio_layer_background_fill' => [
            'label' => 'Extract layers — filled background (inpaint)',
            'description' => 'Optional inpainted background layer after cutout. When off, the option is hidden and confirm rejects the request.',
            'default_enabled' => true,
        ],
    ],
];
