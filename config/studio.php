<?php

return [
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
