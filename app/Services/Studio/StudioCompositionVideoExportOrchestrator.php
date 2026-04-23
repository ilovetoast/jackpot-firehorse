<?php

namespace App\Services\Studio;

use App\Models\StudioCompositionVideoExportJob;
use App\Models\Tenant;
use App\Models\User;

/**
 * Dispatches composition video export to the correct pipeline without changing legacy behavior.
 */
final class StudioCompositionVideoExportOrchestrator
{
    public function __construct(
        protected StudioCompositionVideoExportService $legacyBitmapExport,
        protected StudioCompositionCanvasRuntimeVideoExportService $canvasRuntimeExport,
    ) {}

    public function run(StudioCompositionVideoExportJob $row, Tenant $tenant, User $user): void
    {
        $mode = StudioCompositionVideoExportRenderMode::tryFrom((string) $row->render_mode)
            ?? StudioCompositionVideoExportRenderMode::LEGACY_BITMAP;

        if ($mode === StudioCompositionVideoExportRenderMode::CANVAS_RUNTIME) {
            $this->canvasRuntimeExport->run($row, $tenant, $user);

            return;
        }

        $this->legacyBitmapExport->run($row, $tenant, $user);
    }
}
