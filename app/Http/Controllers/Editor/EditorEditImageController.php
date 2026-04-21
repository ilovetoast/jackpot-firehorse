<?php

namespace App\Http\Controllers\Editor;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Editor\EditorGenerativeImageEditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /app/api/edit-image — AI edit of an existing editor image (new result URL; original asset unchanged).
 */
class EditorEditImageController extends Controller
{
    public function __construct(
        protected EditorGenerativeImageEditService $generativeImageEditService
    ) {}

    public function edit(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'image_url' => 'nullable|string|max:8192',
            'instruction' => 'required|string|max:8000',
            'brand_context' => 'nullable|array',
            'composition_id' => 'nullable|integer|min:1',
            'asset_id' => 'nullable|uuid',
            'brand_id' => 'nullable|integer',
            'model_key' => 'nullable|string|max:64',
            'generative_layer_uuid' => 'nullable|string|max:128',
        ]);

        $tenant = app('tenant');
        if (! $tenant instanceof Tenant) {
            return response()->json(['message' => 'Tenant context required.'], 422);
        }

        $outcome = $this->generativeImageEditService->editFromValidated($user, $tenant, $validated, $request);

        return response()->json($outcome->data, $outcome->status);
    }
}
