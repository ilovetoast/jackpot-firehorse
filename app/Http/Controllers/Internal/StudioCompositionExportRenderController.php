<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\StudioCompositionVideoExportJob;
use App\Models\User;
use App\Services\Studio\CompositionRenderPayloadFactory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Signed, chrome-free render surface for Studio composition **canvas runtime** export (Playwright / automation).
 *
 * Not part of the interactive editor shell — no AssetEditor chrome, trays, or studio sidebars.
 */
final class StudioCompositionExportRenderController extends Controller
{
    public function show(Request $request, StudioCompositionVideoExportJob $exportJob): Response
    {
        $composition = $exportJob->composition;
        if (! $composition) {
            abort(404, 'Composition not found for export job.');
        }
        $tenant = $composition->tenant;
        if (! $tenant) {
            abort(404, 'Tenant not found.');
        }
        $user = $exportJob->user;
        if (! $user instanceof User) {
            abort(403, 'Export render surface requires the job owner user.');
        }

        $payload = CompositionRenderPayloadFactory::fromComposition(
            $composition,
            $tenant,
            $user,
            $exportJob,
        );

        return Inertia::render('StudioExport/CompositionExportRender', [
            'renderPayload' => $payload,
            'exportJobId' => (string) $exportJob->id,
            'compositionId' => (string) $composition->id,
        ]);
    }
}
