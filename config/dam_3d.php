<?php

/**
 * DAM 3D preview pipeline configuration.
 *
 * Upload acceptance for 3D formats is controlled only by config/file_types.php
 * (and global upload size policies). This file gates preview/thumbnail/conversion
 * behaviour — not whether a .glb/.fbx/etc. may be stored.
 *
 * Environment (minimal):
 *   DAM_3D                        When true, 3D raster thumbnail/poster pipeline may run.
 *   DAM_3D_REALTIME_VIEWER        When true, the app mounts `<model-viewer>` for native GLB (`model_glb`) when URLs exist.
 *                                   Defaults to the same value as DAM_3D. Set true on **web-only staging** where
 *                                   `DAM_3D=false` on containers but GLB signed URLs + CDN CORS still work.
 *   DAM_3D_BLENDER_BINARY         Optional path to the Blender executable. **Workers:** use the official **Blender 4.5.3 LTS** linux-x64 tarball, install to **`/usr/local/bin/blender`**, and set **`DAM_3D_BLENDER_BINARY=/usr/local/bin/blender`** (see `docs/environments/BLENDER_DAM_3D_INSTALL.md`). Never required on web-only PHP nodes.
 *   DAM_3D_REAL_RENDER_ENABLED    Default true. Set false to always use stub posters (workers without Blender).
 *
 * Headless reliability (no extra .env): Blender poster renders always run with software
 * rasterization (LIBGL_ALWAYS_SOFTWARE + llvmpipe) and a writable HOME under storage — avoids flaky GPU/EGL in Docker/WSL2.
 */
return [
    'enabled' => (bool) env('DAM_3D', false),

    /**
     * In-browser GLB preview (`<model-viewer>`). Independent of `enabled` so staging web can turn on
     * the viewer while workers keep `DAM_3D=false` (no Blender on web nodes).
     */
    'realtime_viewer_enabled' => (static function (): bool {
        $raw = env('DAM_3D_REALTIME_VIEWER');
        if ($raw !== null && $raw !== '') {
            return filter_var($raw, FILTER_VALIDATE_BOOL);
        }

        return (bool) env('DAM_3D', false);
    })(),

    'blender_binary' => env('DAM_3D_BLENDER_BINARY', '/usr/local/bin/blender'),

    /** Point HOME (and XDG_CONFIG_HOME) at storage/framework/cache/dam3d-blender-home for Blender caches. */
    'writable_home_for_blender' => true,

    /**
     * When true and DAM_3D is enabled, workers attempt a real Blender render when the binary is present.
     * Set DAM_3D_REAL_RENDER_ENABLED=false to force stub posters only (debug / workers without Blender).
     */
    'real_render_enabled' => (bool) env('DAM_3D_REAL_RENDER_ENABLED', true),

    /** When true, STL/OBJ/FBX/BLEND may export a canonical GLB sidecar (viewer_path). */
    'conversion_enabled' => false,

    /**
     * Square poster master size for Blender (then downscaled to thumb/medium/large).
     * Capped internally to 4096.
     */
    'poster_blender_max_px' => 1024,

    /**
     * Optional dedicated queue name for future async 3D jobs; null = use images-heavy
     * via {@see \App\Support\PipelineQueueResolver} (thumbnail job already routes there).
     */
    'preview_queue' => env('DAM_3D_QUEUE', null),

    /** Maximum source bytes considered for 3D preview work (upload may still succeed above this). */
    'max_upload_bytes' => 52_428_800, // 50 MiB

    /** Hard ceiling for server-side decode/render inputs. */
    'max_server_render_bytes' => 104_857_600, // 100 MiB

    'max_conversion_seconds' => 180,
    /** Headless Blender (EEVEE); allow headroom for software-GL retry / cold cache (job has its own timeout). */
    'max_render_seconds' => 180.0,
    'max_texture_dimension' => 4096,
    'max_triangles_soft' => 500_000,
    'max_triangles_hard' => 1_500_000,

    /** Solid plate behind stub poster text (Phase 4A — not real 3D render). */
    'poster_stub_background_hex' => '#0f172a',

    /** Accent line / secondary text colour. */
    'poster_stub_accent_hex' => '#38bdf8',
];
