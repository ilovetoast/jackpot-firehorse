<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Services\BrandDNA\BrandVersionService;
use App\Services\FeatureGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Brand Guidelines Customization — JSON patch endpoint for the sidebar editor.
 * Operates on the active (published) version only.
 * Restricted to presentation_overrides, presentation_content, and presentation keys.
 */
class BrandGuidelinesCustomizeController extends Controller
{
    private const PRESENTATION_ALLOWED_PATHS = [
        'presentation_overrides',
        'presentation_content',
        'presentation',
    ];

    private const DNA_ALLOWED_PATHS = [
        'identity',
        'personality',
        'visual',
        'typography',
    ];

    public function __construct(
        private BrandVersionService $versionService,
        private FeatureGate $featureGate
    ) {}

    /**
     * PATCH /brands/{brand}/guidelines/customize
     *
     * Body: { payload: { presentation_overrides?, presentation_content?, presentation? }, dna_patches?: { identity?, ... } }
     */
    public function patch(Request $request, Brand $brand): JsonResponse
    {
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        $this->authorize('update', $brand);

        if (! $this->featureGate->guidelinesCustomization($tenant)) {
            return response()->json([
                'error' => 'Guidelines customization requires a Pro plan or higher.',
            ], 403);
        }

        $brandModel = $brand->brandModel;
        $activeVersion = $brandModel?->activeVersion;
        if (! $activeVersion) {
            return response()->json([
                'error' => 'No active Brand DNA version. Publish a version first.',
            ], 404);
        }

        $validated = $request->validate([
            'payload' => 'required|array',
            'dna_patches' => 'nullable|array',
        ]);

        $payload = $validated['payload'];

        $filteredPayload = array_intersect_key($payload, array_flip(self::PRESENTATION_ALLOWED_PATHS));
        if (empty($filteredPayload)) {
            return response()->json([
                'error' => 'No valid presentation keys in payload.',
            ], 422);
        }

        $version = $this->versionService->patchActivePayload(
            $brand,
            $filteredPayload,
            self::PRESENTATION_ALLOWED_PATHS
        );

        if (! empty($validated['dna_patches']) && is_array($validated['dna_patches'])) {
            $dnaPatches = array_intersect_key($validated['dna_patches'], array_flip(self::DNA_ALLOWED_PATHS));
            if (! empty($dnaPatches)) {
                $version = $this->versionService->patchActivePayload(
                    $brand,
                    $dnaPatches,
                    self::DNA_ALLOWED_PATHS
                );
            }
        }

        return response()->json([
            'success' => true,
            'version_id' => $version?->id,
        ]);
    }
}
