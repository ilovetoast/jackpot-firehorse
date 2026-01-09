<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\UploadSession;
use App\Services\UploadCompletionService;
use App\Services\UploadInitiationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class UploadController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected UploadInitiationService $uploadService,
        protected UploadCompletionService $completionService
    ) {
    }

    /**
     * Initiate an upload session.
     *
     * POST /uploads/initiate
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function initiate(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        // Validate request
        $validated = $request->validate([
            'file_name' => 'required|string|max:255',
            'file_size' => 'required|integer|min:1',
            'mime_type' => 'nullable|string|max:255',
            'brand_id' => 'nullable|exists:brands,id',
        ]);

        // Verify brand belongs to tenant if provided
        $brand = null;
        if (isset($validated['brand_id'])) {
            $brand = Brand::where('id', $validated['brand_id'])
                ->where('tenant_id', $tenant->id)
                ->firstOrFail();
        } else {
            // Use default brand if no brand specified
            $brand = $tenant->defaultBrand;
        }

        try {
            $result = $this->uploadService->initiate(
                $tenant,
                $brand,
                $validated['file_name'],
                $validated['file_size'],
                $validated['mime_type'] ?? null
            );

            return response()->json([
                'upload_session_id' => $result['upload_session_id'],
                'upload_type' => $result['upload_type'],
                'upload_url' => $result['upload_url'],
                'multipart_upload_id' => $result['multipart_upload_id'],
                'chunk_size' => $result['chunk_size'],
                'expires_at' => $result['expires_at'],
            ], 201);
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'limit_type' => $e->limitType,
                'current_count' => $e->currentCount,
                'max_allowed' => $e->maxAllowed,
            ], 403);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to initiate upload: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Complete an upload session and create an asset.
     *
     * POST /assets/upload/complete
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function complete(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        // Validate request
        $validated = $request->validate([
            'upload_session_id' => 'required|uuid|exists:upload_sessions,id',
            'asset_type' => 'nullable|string|in:asset,marketing,ai_generated',
        ]);

        // Get upload session
        $uploadSession = UploadSession::where('id', $validated['upload_session_id'])
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        try {
            $asset = $this->completionService->complete(
                $uploadSession,
                $validated['asset_type'] ?? null
            );

            return response()->json([
                'asset_id' => $asset->id,
                'status' => $asset->status->value,
                'file_name' => $asset->file_name,
                'file_size' => $asset->file_size,
                'mime_type' => $asset->mime_type,
            ], 201);
        } catch (\RuntimeException $e) {
            // Fail loudly - return detailed error for debugging
            Log::error('Upload completion failed', [
                'upload_session_id' => $uploadSession->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Unexpected error during upload completion', [
                'upload_session_id' => $uploadSession->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to complete upload: ' . $e->getMessage(),
            ], 500);
        }
    }
}
