<?php

/**
 * Studio Versions (color × scene) generation.
 *
 * Queue requirement (staging/production): {@see \App\Support\StudioCreativeSetGenerationQueueGuard}
 * rejects {@code QUEUE_CONNECTION=sync} outside local/testing so generation never runs inline on web workers.
 * Configure Redis (or database) + Horizon / queue workers for async item processing.
 */
return [
    'max_colors' => 6,
    'max_scenes' => 5,
    'max_outputs_per_request' => 24,

    /**
     * Placeholders: :label, :hex (hex may be empty).
     */
    'color_instruction_template' => 'Recolor the main product or garment to :label (reference color :hex). Keep the same pose, camera angle, and realism. Do not change logos, text, or background unless needed for seamless integration.',

    /**
     * Built-in scene presets (merged with user-supplied scenes that pass validation).
     */
    'preset_scenes' => [
        [
            'id' => 'studio',
            'label' => 'Studio',
            'instruction' => 'Place the product on a clean neutral studio sweep, soft even lighting, subtle shadow, ecommerce packshot style.',
        ],
        [
            'id' => 'minimal',
            'label' => 'Minimal',
            'instruction' => 'Minimal abstract background, soft gradient, lots of negative space, premium editorial look.',
        ],
        [
            'id' => 'lifestyle_indoor',
            'label' => 'Indoor lifestyle',
            'instruction' => 'Natural indoor lifestyle setting, warm ambient light, realistic context that complements the product.',
        ],
        [
            'id' => 'lifestyle_outdoor',
            'label' => 'Outdoor lifestyle',
            'instruction' => 'Outdoor lifestyle scene with natural daylight, shallow depth of field, believable environment.',
        ],
    ],

    'preset_colors' => [
        ['id' => 'black', 'label' => 'Black', 'hex' => '#111111'],
        ['id' => 'white', 'label' => 'White', 'hex' => '#f5f5f5'],
        ['id' => 'navy', 'label' => 'Navy', 'hex' => '#1a2744'],
        ['id' => 'red', 'label' => 'Red', 'hex' => '#b91c1c'],
        ['id' => 'green', 'label' => 'Green', 'hex' => '#166534'],
        ['id' => 'blue', 'label' => 'Blue', 'hex' => '#1d4ed8'],
    ],
];
