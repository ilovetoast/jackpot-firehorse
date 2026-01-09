<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\UploadSession;
use App\Services\AbandonedSessionService;
use App\Services\ResumeMetadataService;
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
        protected UploadCompletionService $completionService,
        protected ResumeMetadataService $resumeService,
        protected AbandonedSessionService $abandonedService
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
            'client_reference' => 'nullable|uuid', // Optional client reference for frontend mapping
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
                $validated['mime_type'] ?? null,
                $validated['client_reference'] ?? null
            );

            return response()->json([
                'upload_session_id' => $result['upload_session_id'],
                'client_reference' => $result['client_reference'],
                'upload_session_status' => $result['upload_session_status'],
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

            // Refresh upload session to get updated status
            $uploadSession->refresh();

            return response()->json([
                'asset_id' => $asset->id,
                'upload_session_id' => $uploadSession->id,
                'upload_session_status' => $uploadSession->status->value, // Should be "completed"
                'asset_status' => $asset->status->value,
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

            // Refresh to get current status (might have changed)
            $uploadSession->refresh();

            return response()->json([
                'message' => $e->getMessage(),
                'upload_session_id' => $uploadSession->id,
                'upload_session_status' => $uploadSession->status->value,
            ], 400);
        } catch (\Exception $e) {
            Log::error('Unexpected error during upload completion', [
                'upload_session_id' => $uploadSession->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Refresh to get current status before error response
            $uploadSession->refresh();

            return response()->json([
                'message' => 'Failed to complete upload: ' . $e->getMessage(),
                'upload_session_id' => $uploadSession->id,
                'upload_session_status' => $uploadSession->status->value,
            ], 500);
        }
    }

    /**
     * Initiate multiple upload sessions in parallel (batch upload).
     *
     * POST /uploads/initiate-batch
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function initiateBatch(Request $request): JsonResponse
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
            'files' => 'required|array|min:1|max:100', // Limit batch size (hard limit for safety)
            'files.*.file_name' => 'required|string|max:255',
            'files.*.file_size' => 'required|integer|min:1',
            'files.*.mime_type' => 'nullable|string|max:255',
            'files.*.client_reference' => 'nullable|uuid', // Optional client reference for frontend mapping
            'brand_id' => 'nullable|exists:brands,id', // Shared brand for all files in batch
            'batch_reference' => 'nullable|uuid', // Optional batch-level correlation ID for grouping/debugging/analytics
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
            // Prepare files array for service
            $files = array_map(function ($file) {
                return [
                    'file_name' => $file['file_name'],
                    'file_size' => $file['file_size'],
                    'mime_type' => $file['mime_type'] ?? null,
                    'client_reference' => $file['client_reference'] ?? null,
                ];
            }, $validated['files']);

            // Get optional batch_reference for correlation (helps with frontend grouping, debugging, analytics)
            $batchReference = $validated['batch_reference'] ?? null;

            // Initiate batch upload (one UploadSession per file)
            $results = $this->uploadService->initiateBatch(
                $tenant,
                $brand,
                $files,
                $batchReference
            );

            return response()->json([
                'batch_reference' => $batchReference, // Return batch reference for correlation
                'uploads' => $results,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to initiate batch upload', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to initiate batch upload: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancel an upload session.
     *
     * POST /uploads/{uploadSession}/cancel
     *
     * @param Request $request
     * @param UploadSession $uploadSession
     * @return JsonResponse
     */
    public function cancel(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        // Verify upload session belongs to tenant
        if ($uploadSession->tenant_id !== $tenant->id) {
            return response()->json([
                'message' => 'Upload session not found',
            ], 404);
        }

        try {
            // Refresh to get latest state
            $uploadSession->refresh();
            
            // Check if already in terminal state (idempotent check)
            $isTerminal = $uploadSession->isTerminal();
            
            // Attempt cancellation (idempotent - returns false if already terminal)
            $wasCancelled = $this->uploadService->cancel($uploadSession);
            
            // Refresh to get updated state
            $uploadSession->refresh();
            
            // Always return 200 OK with current state (idempotent behavior)
            $isExpired = $uploadSession->expires_at && $uploadSession->expires_at->isPast();
            
            if ($isTerminal || !$wasCancelled) {
                // Already in terminal state - return current state
                return response()->json([
                    'message' => 'Upload session is already in terminal state',
                    'upload_session_id' => $uploadSession->id,
                    'upload_session_status' => $uploadSession->status->value,
                    'is_expired' => $isExpired,
                    'already_terminated' => true,
                ], 200);
            }
            
            // Successfully cancelled
            return response()->json([
                'message' => 'Upload session cancelled successfully',
                'upload_session_id' => $uploadSession->id,
                'upload_session_status' => $uploadSession->status->value,
                'already_terminated' => false,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to cancel upload session', [
                'upload_session_id' => $uploadSession->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Refresh to get current status before error response
            $uploadSession->refresh();

            return response()->json([
                'message' => 'Failed to cancel upload session: ' . $e->getMessage(),
                'upload_session_id' => $uploadSession->id,
                'upload_session_status' => $uploadSession->status->value,
            ], 500);
        }
    }

    /**
     * Get resume metadata for an upload session.
     *
     * GET /uploads/{uploadSession}/resume
     *
     * This endpoint enables reload-safe, resumable uploads by providing
     * information about already uploaded parts for multipart uploads.
     *
     * @param Request $request
     * @param UploadSession $uploadSession
     * @return JsonResponse
     */
    public function resume(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        // Verify upload session belongs to tenant
        if ($uploadSession->tenant_id !== $tenant->id) {
            return response()->json([
                'message' => 'Upload session not found',
            ], 404);
        }

        try {
            Log::info('Resume metadata requested', [
                'upload_session_id' => $uploadSession->id,
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'current_status' => $uploadSession->status->value,
            ]);

            // Get resume metadata (queries S3 for uploaded parts if multipart)
            // This also updates last_activity_at automatically
            $metadata = $this->resumeService->getResumeMetadata($uploadSession);

            if (!$metadata['can_resume'] && $metadata['error']) {
                // Resume is blocked - return error with metadata
                // Fail loudly with actionable error message
                Log::warning('Resume blocked for upload session', [
                    'upload_session_id' => $uploadSession->id,
                    'reason' => $metadata['error'],
                    'status' => $metadata['upload_session_status'],
                    'is_expired' => $metadata['is_expired'],
                ]);

                return response()->json([
                    'message' => $metadata['error'],
                    'upload_session_id' => $uploadSession->id,
                    ...$metadata,
                ], 400);
            }

            // Return resume metadata
            Log::info('Resume metadata retrieved successfully', [
                'upload_session_id' => $uploadSession->id,
                'can_resume' => $metadata['can_resume'],
                'parts_count' => $metadata['already_uploaded_parts'] ? count($metadata['already_uploaded_parts']) : 0,
            ]);

            return response()->json([
                'upload_session_id' => $uploadSession->id,
                ...$metadata,
            ], 200);
        } catch (\Exception $e) {
            // Fail loudly - return detailed error for debugging
            Log::error('Failed to get resume metadata', [
                'upload_session_id' => $uploadSession->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to get resume metadata: ' . $e->getMessage(),
                'upload_session_id' => $uploadSession->id,
            ], 500);
        }
    }

    /**
     * Update activity timestamp for an upload session.
     *
     * PUT /uploads/{uploadSession}/activity
     *
     * This endpoint should be called periodically during active uploads
     * to prevent the session from being marked as abandoned.
     *
     * @param Request $request
     * @param UploadSession $uploadSession
     * @return JsonResponse
     */
    public function updateActivity(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        // Verify upload session belongs to tenant
        if ($uploadSession->tenant_id !== $tenant->id) {
            return response()->json([
                'message' => 'Upload session not found',
            ], 404);
        }

        try {
            // Update activity timestamp (only for non-terminal sessions)
            $this->abandonedService->updateActivity($uploadSession);

            Log::debug('Upload session activity updated', [
                'upload_session_id' => $uploadSession->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'message' => 'Activity updated successfully',
                'upload_session_id' => $uploadSession->id,
                'last_activity_at' => $uploadSession->fresh()->last_activity_at?->toIso8601String(),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to update upload session activity', [
                'upload_session_id' => $uploadSession->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to update activity: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark upload session as actively uploading.
     *
     * PUT /uploads/{uploadSession}/start
     *
     * This endpoint should be called when the client actually starts uploading data.
     * Transitions status from INITIATING to UPLOADING and updates activity timestamp.
     *
     * @param Request $request
     * @param UploadSession $uploadSession
     * @return JsonResponse
     */
    public function markAsUploading(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        // Verify upload session belongs to tenant
        if ($uploadSession->tenant_id !== $tenant->id) {
            return response()->json([
                'message' => 'Upload session not found',
            ], 404);
        }

        try {
            Log::info('Mark upload as UPLOADING requested', [
                'upload_session_id' => $uploadSession->id,
                'user_id' => $user->id,
                'current_status' => $uploadSession->status->value,
            ]);

            // Transition to UPLOADING (updates activity automatically)
            $transitioned = $this->uploadService->markAsUploading($uploadSession);

            // Refresh to get updated state
            $uploadSession->refresh();

            if ($transitioned) {
                return response()->json([
                    'message' => 'Upload session marked as UPLOADING',
                    'upload_session_id' => $uploadSession->id,
                    'upload_session_status' => $uploadSession->status->value,
                    'last_activity_at' => $uploadSession->last_activity_at?->toIso8601String(),
                ], 200);
            } else {
                // Already UPLOADING or cannot transition
                return response()->json([
                    'message' => 'Upload session is already UPLOADING or cannot transition',
                    'upload_session_id' => $uploadSession->id,
                    'upload_session_status' => $uploadSession->status->value,
                    'last_activity_at' => $uploadSession->last_activity_at?->toIso8601String(),
                ], 200);
            }
        } catch (\Exception $e) {
            Log::error('Failed to mark upload session as UPLOADING', [
                'upload_session_id' => $uploadSession->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to mark as UPLOADING: ' . $e->getMessage(),
                'upload_session_id' => $uploadSession->id,
            ], 500);
        }
    }
}
