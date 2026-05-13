<?php

/**
 * DAM 3D preview pipeline configuration.
 *
 * Upload acceptance for 3D formats is controlled only by config/file_types.php
 * (and global upload size policies). This file gates preview/thumbnail/conversion
 * behaviour — not whether a .glb/.fbx/etc. may be stored.
 *
 * Environment (minimal):
 *   DAM_3D                 When true, 3D raster thumbnail/poster pipeline may run (Phase 4+).
 *   DAM_3D_BLENDER_BINARY  Optional path to blender; used when conversion is implemented.
 */
return [
    'enabled' => (bool) env('DAM_3D', false),

    'blender_binary' => env('DAM_3D_BLENDER_BINARY', '/usr/bin/blender'),

    /** When false, FBX/BLEND → GLB conversion jobs must not run (Phase 6). */
    'conversion_enabled' => false,

    /** Maximum source bytes considered for 3D preview work (upload may still succeed above this). */
    'max_upload_bytes' => 52_428_800, // 50 MiB

    /** Hard ceiling for server-side decode/render inputs (Phase 4+). */
    'max_server_render_bytes' => 104_857_600, // 100 MiB

    'max_conversion_seconds' => 180,
    'max_render_seconds' => 90,
    'max_texture_dimension' => 4096,
    'max_triangles_soft' => 500_000,
    'max_triangles_hard' => 1_500_000,

    /** Solid plate behind stub poster text (Phase 4A — not real 3D render). */
    'poster_stub_background_hex' => '#0f172a',

    /** Accent line / secondary text colour. */
    'poster_stub_accent_hex' => '#38bdf8',
];
