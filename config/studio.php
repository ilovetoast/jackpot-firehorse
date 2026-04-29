<?php

return [
    /**
     * When true, still → AI video library assets (staged animation MP4, composition export from a still→clip layer)
     * get default discovery tags (ai, ai-generated, etc.). Set false on a non-primary / sandbox site via env.
     */
    'still_to_video_library_tags' => [
        'enabled' => (bool) env('STUDIO_STILL_TO_VIDEO_LIBRARY_TAGS_ENABLED', true),
    ],

    /**
     * When true, Studio variant families (color / size / generic groups) are persisted and exposed via API.
     * Phased rollout: keep false in production until ready.
     */
    'variant_groups_v1' => (bool) env('STUDIO_VARIANT_GROUPS_V1', false),

    /**
     * When true, duplicate composition may create a single-member generic {@see \App\Models\StudioVariantGroup} (opt-in; requires set context in future).
     */
    'auto_generic_group' => (bool) env('STUDIO_AUTO_GENERIC_GROUP', false),
];
