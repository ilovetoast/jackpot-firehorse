<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by ThumbnailGenerationService when a 3D model file (.glb, .stl, .obj, .fbx, .blend, …)
 * cannot be turned into a master raster preview — typically because Blender failed to spawn,
 * the source bytes aren't valid for the declared file type, or the model itself is unsupported.
 *
 * Treated as a *terminal user-data failure* by GenerateThumbnailsJob (mark SKIPPED, no retry,
 * not reported to Sentry) — same UX as the resource-exhaustion / source-too-large path. Retrying
 * the same broken file 32× on the same hardware never helps and only spams the error tracker.
 *
 * Callers should surface `userMessage` (sanitized) to the asset card and keep `failureMessage` /
 * `debug` in `metadata.model_3d_preview` for support diagnostics.
 */
class Model3dPreviewFailedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $debug
     */
    public function __construct(
        string $message,
        public readonly string $userMessage,
        public readonly ?string $fileType = null,
        public readonly bool $blenderAttempted = false,
        public readonly bool $invalidSource = false,
        public readonly array $debug = [],
    ) {
        parent::__construct($message);
    }
}
