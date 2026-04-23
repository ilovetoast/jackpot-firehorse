<?php

namespace App\Services\Studio;

use App\Models\StudioCompositionVideoExportJob;
use App\Models\Tenant;
use App\Models\User;

/**
 * Dispatches composition video export to legacy bitmap, FFmpeg-native full scene, or Playwright canvas runtime.
 */
final class StudioCompositionVideoExportOrchestrator
{
    public function __construct(
        protected StudioCompositionVideoExportService $legacyBitmapExport,
        protected StudioCompositionCanvasRuntimeVideoExportService $canvasRuntimeExport,
        protected StudioCompositionFfmpegNativeVideoExportService $ffmpegNativeExport,
    ) {}

    public function run(StudioCompositionVideoExportJob $row, Tenant $tenant, User $user): void
    {
        $mode = StudioCompositionVideoExportRenderMode::tryFrom((string) $row->render_mode)
            ?? StudioCompositionVideoExportRenderMode::LEGACY_BITMAP;

        if ($mode === StudioCompositionVideoExportRenderMode::FFMPEG_NATIVE) {
            $this->ffmpegNativeExport->run($row, $tenant, $user);

            return;
        }

        if ($mode === StudioCompositionVideoExportRenderMode::CANVAS_RUNTIME) {
            $this->canvasRuntimeExport->run($row, $tenant, $user);

            return;
        }

        $this->legacyBitmapExport->run($row, $tenant, $user);
    }
}
