<?php

namespace App\Http\Controllers;

use App\Http\Responses\UploadErrorResponse;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\UploadSession;
use App\Services\AbandonedSessionService;
use App\Services\ActivityRecorder;
use App\Services\MetadataPersistenceService;
use App\Services\MultipartUploadService;
use App\Services\MultipartUploadUrlService;
use App\Services\PlanService;
use App\Services\ResumeMetadataService;
use App\Services\UploadCompletionService;
use App\Services\UploadInitiationService;
use App\Services\UploadMetadataSchemaResolver;
use Aws\S3\S3Client;
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
        protected AbandonedSessionService $abandonedService,
        protected MultipartUploadUrlService $multipartUrlService,
        protected MultipartUploadService $multipartService,
        protected UploadMetadataSchemaResolver $uploadMetadataSchemaResolver,
        protected MetadataPersistenceService $metadataPersistenceService,
        protected PlanService $planService
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
        // Phase 2.5 Step 2: Use normalized error response
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission - viewers cannot upload
        if (!$user->hasPermissionForTenant($tenant, 'asset.upload')) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'You do not have permission to upload assets.',
                403,
                []
            );
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
            // Phase 2.5 Step 2: Use normalized error response
            return UploadErrorResponse::fromException($e, 403, [
                'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
                'file_type' => UploadErrorResponse::extractFileType($validated['file_name'] ?? null),
            ]);
        } catch (\Exception $e) {
            // Phase 2.5 Step 2: Use normalized error response
            return UploadErrorResponse::fromException($e, 500, [
                'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
                'file_type' => UploadErrorResponse::extractFileType($validated['file_name'] ?? null),
            ]);
        }
    }

    /**
     * Check storage limits before upload.
     *
     * GET /uploads/storage-check
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function checkStorageLimits(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check upload permission - viewers cannot check storage limits (they can't upload)
        if (!$user->hasPermissionForTenant($tenant, 'asset.upload')) {
            return response()->json([
                'error' => 'You do not have permission to upload assets.',
            ], 403);
        }

        try {
            // Get storage information
            $storageInfo = $this->planService->getStorageInfo($tenant);
            
            // Get plan limits for context
            $planLimits = $this->planService->getPlanLimits($tenant);
            $maxUploadSizeBytes = $this->planService->getMaxUploadSize($tenant);

            return response()->json([
                'storage' => $storageInfo,
                'limits' => [
                    'max_upload_size_bytes' => $maxUploadSizeBytes,
                    'max_upload_size_mb' => round($maxUploadSizeBytes / 1024 / 1024, 2),
                ],
                'plan' => [
                    'name' => $this->planService->getCurrentPlan($tenant),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to check storage limits', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to check storage limits'], 500);
        }
    }

    /**
     * Check if specific files can be uploaded (pre-upload validation).
     *
     * POST /uploads/validate
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function validateUpload(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check upload permission - viewers cannot upload
        if (!$user->hasPermissionForTenant($tenant, 'asset.upload')) {
            return response()->json([
                'error' => 'You do not have permission to upload assets.',
            ], 403);
        }

        $validated = $request->validate([
            'files' => 'required|array|min:1|max:100',
            'files.*.file_name' => 'required|string|max:255',
            'files.*.file_size' => 'required|integer|min:1',
        ]);

        try {
            $results = [];
            $totalSize = 0;
            
            // Get current limits
            $maxUploadSizeBytes = $this->planService->getMaxUploadSize($tenant);
            $storageInfo = $this->planService->getStorageInfo($tenant);

            foreach ($validated['files'] as $file) {
                $fileSize = $file['file_size'];
                $fileName = $file['file_name'];
                $totalSize += $fileSize;

                $canUpload = true;
                $errors = [];

                // Check individual file size limit
                if ($fileSize > $maxUploadSizeBytes) {
                    $canUpload = false;
                    $errors[] = [
                        'type' => 'file_size_limit',
                        'message' => "File size (" . round($fileSize / 1024 / 1024, 2) . " MB) exceeds maximum upload size (" . round($maxUploadSizeBytes / 1024 / 1024, 2) . " MB) for your plan.",
                    ];
                }

                // Check if this file alone would exceed storage
                if (!$this->planService->canAddFile($tenant, $fileSize)) {
                    $canUpload = false;
                    $errors[] = [
                        'type' => 'storage_limit',
                        'message' => "This file would exceed your storage limit.",
                    ];
                }

                $results[] = [
                    'file_name' => $fileName,
                    'file_size' => $fileSize,
                    'can_upload' => $canUpload,
                    'errors' => $errors,
                ];
            }

            // Check if total batch would exceed storage
            $batchStorageExceeded = !$this->planService->canAddFile($tenant, $totalSize);

            return response()->json([
                'files' => $results,
                'batch_summary' => [
                    'total_files' => count($validated['files']),
                    'total_size_bytes' => $totalSize,
                    'total_size_mb' => round($totalSize / 1024 / 1024, 2),
                    'can_upload_batch' => !$batchStorageExceeded && collect($results)->every('can_upload'),
                    'storage_exceeded' => $batchStorageExceeded,
                ],
                'storage_info' => $storageInfo,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to validate upload', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to validate upload'], 500);
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
        // Phase 2.5 Step 2: Use normalized error response
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission - viewers cannot upload
        if (!$user->hasPermissionForTenant($tenant, 'asset.upload')) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'You do not have permission to upload assets.',
                403,
                []
            );
        }


        // Validate request - enforce required fields and types
        // Note: asset_type is nullable for backward compatibility, defaults to 'asset' if not provided
        try {
            $validated = $request->validate([
                'upload_session_id' => 'required|uuid|exists:upload_sessions,id',
                'asset_type' => 'nullable|string|in:asset,deliverable,ai_generated',
                'filename' => 'nullable|string|max:255',
                'title' => 'nullable|string|max:255',
                'category_id' => 'nullable|integer',
                'metadata' => 'nullable|array',
            ]);
            
            // Default asset_type to 'asset' if not provided (backward compatibility)
            if (empty($validated['asset_type'])) {
                $validated['asset_type'] = 'asset';
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Log validation errors for debugging
            Log::error('[Upload Complete] Validation failed', [
                'errors' => $e->errors(),
                'request_data' => $request->all(),
            ]);
            
            // Phase 2.5 Step 2: Use normalized error response
            return UploadErrorResponse::fromException($e, 422, [
                'upload_session_id' => $request->input('upload_session_id'),
                'pipeline_stage' => UploadErrorResponse::STAGE_FINALIZE,
            ]);
        }

        // Get upload session
        $uploadSession = UploadSession::where('id', $validated['upload_session_id'])
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;

        try {
            // Get authenticated user
            $user = Auth::user();
            $userId = $user ? $user->id : null;

            $asset = $this->completionService->complete(
                $uploadSession,
                $validated['asset_type'] ?? null,
                $validated['filename'] ?? null,
                $validated['title'] ?? null,
                null, // $s3Key - optional, will be determined
                $categoryId,
                $validated['metadata'] ?? null,
                $userId // User who uploaded the asset
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

            // Phase 2.5 Step 2: Use normalized error response
            return UploadErrorResponse::fromException($e, 400, [
                'upload_session_id' => $uploadSession->id,
                'file_type' => UploadErrorResponse::extractFileType(null, $uploadSession),
                'pipeline_stage' => UploadErrorResponse::STAGE_FINALIZE,
            ]);
        } catch (\Exception $e) {
            Log::error('Unexpected error during upload completion', [
                'upload_session_id' => $uploadSession->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Refresh to get current status before error response
            $uploadSession->refresh();

            // Phase 2.5 Step 2: Use normalized error response
            return UploadErrorResponse::fromException($e, 500, [
                'upload_session_id' => $uploadSession->id,
                'file_type' => UploadErrorResponse::extractFileType(null, $uploadSession),
                'pipeline_stage' => UploadErrorResponse::STAGE_FINALIZE,
            ]);
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
        // Phase 2.5 Step 2: Use normalized error response
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission - viewers cannot upload
        if (!$user->hasPermissionForTenant($tenant, 'asset.upload')) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'You do not have permission to upload assets.',
                403,
                []
            );
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
            'category_id' => 'nullable|integer|exists:categories,id', // Category is optional for upload initiation (required for finalization)
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

        if (!$brand) {
            // Phase 2.5 Step 2: Use normalized error response
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_VALIDATION_FAILED,
                'Brand not found.',
                404,
                [
                    'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
                ]
            );
        }

        // Verify category belongs to tenant and brand (only if provided)
        $category = null;
        if (isset($validated['category_id'])) {
            $categoryId = $validated['category_id'];
            $category = Category::where('id', $categoryId)
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->first();

            if (!$category) {
                Log::warning('Upload initiation: Invalid category provided', [
                    'category_id' => $categoryId,
                    'tenant_id' => $tenant->id,
                    'brand_id' => $brand->id,
                ]);

                return response()->json([
                    'error' => 'invalid_category',
                    'message' => 'Invalid category. Category must belong to this brand.',
                ], 422);
            }
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

            // Phase 2.5 Step 2: Use normalized error response
            return UploadErrorResponse::fromException($e, 500, [
                'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
            ]);
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
        // Phase 2.5 Step 2: Use normalized error response
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Verify upload session belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if ($uploadSession->tenant_id !== $tenant->id) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_SESSION_NOT_FOUND,
                'Upload session not found.',
                404,
                [
                    'upload_session_id' => $uploadSession->id,
                    'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
                ]
            );
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

            // Phase 2.5 Step 2: Use normalized error response
            return UploadErrorResponse::fromException($e, 500, [
                'upload_session_id' => $uploadSession->id,
                'file_type' => UploadErrorResponse::extractFileType(null, $uploadSession),
                'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
            ]);
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
        // Phase 2.5 Step 2: Use normalized error response
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission - viewers cannot upload
        if (!$user->hasPermissionForTenant($tenant, 'asset.upload')) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'You do not have permission to upload assets.',
                403,
                []
            );
        }

        // Verify upload session belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if ($uploadSession->tenant_id !== $tenant->id) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_SESSION_NOT_FOUND,
                'Upload session not found.',
                404,
                [
                    'upload_session_id' => $uploadSession->id,
                    'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
                ]
            );
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

                // Phase 2.5 Step 2: Use normalized error response for resume errors
                return UploadErrorResponse::error(
                    UploadErrorResponse::CODE_SESSION_INVALID,
                    $metadata['error'],
                    400,
                    [
                        'upload_session_id' => $uploadSession->id,
                        'file_type' => UploadErrorResponse::extractFileType(null, $uploadSession),
                        'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
                    ]
                );
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

            // Phase 2.5 Step 2: Use normalized error response
            return UploadErrorResponse::fromException($e, 500, [
                'upload_session_id' => $uploadSession->id,
                'file_type' => UploadErrorResponse::extractFileType(null, $uploadSession),
                'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
            ]);
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
        // Phase 2.5 Step 2: Use normalized error response
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission - viewers cannot upload
        if (!$user->hasPermissionForTenant($tenant, 'asset.upload')) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'You do not have permission to upload assets.',
                403,
                []
            );
        }

        // Verify upload session belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if ($uploadSession->tenant_id !== $tenant->id) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_SESSION_NOT_FOUND,
                'Upload session not found.',
                404,
                [
                    'upload_session_id' => $uploadSession->id,
                    'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
                ]
            );
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
        // Phase 2.5 Step 2: Use normalized error response
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission - viewers cannot upload
        if (!$user->hasPermissionForTenant($tenant, 'asset.upload')) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'You do not have permission to upload assets.',
                403,
                []
            );
        }

        // Verify upload session belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if ($uploadSession->tenant_id !== $tenant->id) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_SESSION_NOT_FOUND,
                'Upload session not found.',
                404,
                [
                    'upload_session_id' => $uploadSession->id,
                    'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
                ]
            );
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

            // Phase 2.5 Step 2: Use normalized error response
            return UploadErrorResponse::fromException($e, 500, [
                'upload_session_id' => $uploadSession->id,
                'file_type' => UploadErrorResponse::extractFileType(null, $uploadSession),
                'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
            ]);
        }
    }

    /**
     * Get presigned URL for a multipart upload part.
     *
     * POST /uploads/{uploadSession}/multipart-part-url
     *
     * Generates a secure, time-limited presigned URL for uploading
     * a single part of a multipart upload directly to S3.
     *
     * @param Request $request
     * @param UploadSession $uploadSession
     * @return JsonResponse
     */
    public function getMultipartPartUrl(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission - viewers cannot upload
        if (!$user->hasPermissionForTenant($tenant, 'asset.upload')) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'You do not have permission to upload assets.',
                403,
                []
            );
        }

        // Verify upload session belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if ($uploadSession->tenant_id !== $tenant->id) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_SESSION_NOT_FOUND,
                'Upload session not found.',
                404,
                [
                    'upload_session_id' => $uploadSession->id,
                    'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
                ]
            );
        }

        // Validate request input
        $validated = $request->validate([
            'part_number' => 'required|integer|min:1',
        ]);

        $partNumber = $validated['part_number'];

        try {
            // Refresh to get latest state
            $uploadSession->refresh();

            Log::info('Multipart part URL requested', [
                'upload_session_id' => $uploadSession->id,
                'part_number' => $partNumber,
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'current_status' => $uploadSession->status->value,
                'multipart_upload_id' => $uploadSession->multipart_upload_id,
            ]);

            // Generate presigned URL (service validates state)
            $result = $this->multipartUrlService->generatePartUploadUrl($uploadSession, $partNumber);

            return response()->json($result, 200);
        } catch (\RuntimeException $e) {
            // Handle validation errors with appropriate HTTP status codes
            $errorMessage = $e->getMessage();

            // Check for specific error conditions
            if (str_contains($errorMessage, 'terminal state')) {
                // Upload already completed or in terminal state
                Log::warning('Multipart part URL requested for terminal session', [
                    'upload_session_id' => $uploadSession->id,
                    'part_number' => $partNumber,
                    'status' => $uploadSession->status->value,
                    'error' => $errorMessage,
                ]);

                return response()->json([
                    'message' => $errorMessage,
                    'upload_session_id' => $uploadSession->id,
                    'upload_session_status' => $uploadSession->status->value,
                ], 409); // Conflict - resource state doesn't allow operation
            }

            if (str_contains($errorMessage, 'expired')) {
                // Upload session expired
                Log::warning('Multipart part URL requested for expired session', [
                    'upload_session_id' => $uploadSession->id,
                    'part_number' => $partNumber,
                    'expires_at' => $uploadSession->expires_at?->toIso8601String(),
                    'error' => $errorMessage,
                ]);

                // Phase 2.5 Step 2: Use normalized error response
                return UploadErrorResponse::error(
                    UploadErrorResponse::CODE_SESSION_EXPIRED,
                    $errorMessage,
                    410,
                    [
                        'upload_session_id' => $uploadSession->id,
                        'file_type' => UploadErrorResponse::extractFileType(null, $uploadSession),
                        'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
                    ]
                );
            }

            if (str_contains($errorMessage, 'invalid state') || str_contains($errorMessage, 'does not have a multipart upload ID')) {
                // Invalid state or missing multipart_upload_id
                Log::warning('Multipart part URL requested but session is in invalid state', [
                    'upload_session_id' => $uploadSession->id,
                    'part_number' => $partNumber,
                    'status' => $uploadSession->status->value,
                    'has_multipart_upload_id' => !empty($uploadSession->multipart_upload_id),
                    'error' => $errorMessage,
                ]);

                return response()->json([
                    'message' => $errorMessage,
                    'upload_session_id' => $uploadSession->id,
                    'upload_session_status' => $uploadSession->status->value,
                ], 409); // Conflict - resource state doesn't allow operation
            }

            // Generic runtime exception
            Log::error('Failed to generate multipart part URL', [
                'upload_session_id' => $uploadSession->id,
                'part_number' => $partNumber,
                'error' => $errorMessage,
                'trace' => $e->getTraceAsString(),
            ]);

            // Phase 2.5 Step 2: Use normalized error response
            return UploadErrorResponse::fromException($e, 400, [
                'upload_session_id' => $uploadSession->id,
                'file_type' => UploadErrorResponse::extractFileType(null, $uploadSession),
                'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
            ]);
        } catch (\Exception $e) {
            // Unexpected error
            Log::error('Unexpected error generating multipart part URL', [
                'upload_session_id' => $uploadSession->id,
                'part_number' => $partNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Phase 2.5 Step 2: Use normalized error response
            return UploadErrorResponse::fromException($e, 500, [
                'upload_session_id' => $uploadSession->id,
                'file_type' => UploadErrorResponse::extractFileType(null, $uploadSession),
                'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
            ]);
        }
    }

    /**
     * Initiate a multipart upload for an upload session.
     *
     * POST /uploads/{uploadSession}/multipart/init
     *
     * This endpoint is idempotent - if a multipart upload is already initiated,
     * it returns the existing multipart_upload_id without creating a new one.
     *
     * @param Request $request
     * @param UploadSession $uploadSession
     * @return JsonResponse
     */
    public function initMultipart(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission - viewers cannot upload
        if (!$user->hasPermissionForTenant($tenant, 'asset.upload')) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'You do not have permission to upload assets.',
                403,
                []
            );
        }

        // Verify upload session belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if ($uploadSession->tenant_id !== $tenant->id) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_SESSION_NOT_FOUND,
                'Upload session not found.',
                404,
                [
                    'upload_session_id' => $uploadSession->id,
                    'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
                ]
            );
        }

        try {
            // Refresh to get latest state
            $uploadSession->refresh();

            Log::info('Multipart upload init requested', [
                'upload_session_id' => $uploadSession->id,
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'current_status' => $uploadSession->status->value,
            ]);

            // Initiate multipart upload (idempotent)
            $result = $this->multipartService->initiateMultipartUpload($uploadSession);

            // Refresh to get updated state
            $uploadSession->refresh();

            return response()->json([
                'upload_session_id' => $uploadSession->id,
                'multipart_upload_id' => $result['multipart_upload_id'],
                'part_size' => $result['part_size'],
                'total_parts' => $result['total_parts'],
                'already_initiated' => $result['already_initiated'],
            ], 200);
        } catch (\RuntimeException $e) {
            Log::error('Failed to initiate multipart upload', [
                'upload_session_id' => $uploadSession->id,
                'error' => $e->getMessage(),
            ]);

            // Phase 2.5 Step 2: Use normalized error response
            return UploadErrorResponse::fromException($e, 400, [
                'upload_session_id' => $uploadSession->id,
                'file_type' => UploadErrorResponse::extractFileType(null, $uploadSession),
                'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
            ]);
        } catch (\Exception $e) {
            Log::error('Unexpected error initiating multipart upload', [
                'upload_session_id' => $uploadSession->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Phase 2.5 Step 2: Use normalized error response
            return UploadErrorResponse::fromException($e, 500, [
                'upload_session_id' => $uploadSession->id,
                'file_type' => UploadErrorResponse::extractFileType(null, $uploadSession),
                'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
            ]);
        }
    }

    /**
     * Sign a presigned URL for uploading a specific part.
     *
     * POST /uploads/{uploadSession}/multipart/sign-part
     *
     * @param Request $request
     * @param UploadSession $uploadSession
     * @return JsonResponse
     */
    public function signMultipartPart(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission - viewers cannot upload
        if (!$user->hasPermissionForTenant($tenant, 'asset.upload')) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'You do not have permission to upload assets.',
                403,
                []
            );
        }

        // Verify upload session belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if ($uploadSession->tenant_id !== $tenant->id) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_SESSION_NOT_FOUND,
                'Upload session not found.',
                404,
                [
                    'upload_session_id' => $uploadSession->id,
                    'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
                ]
            );
        }

        // Validate request input
        $validated = $request->validate([
            'part_number' => 'required|integer|min:1',
        ]);

        $partNumber = $validated['part_number'];

        try {
            // Refresh to get latest state
            $uploadSession->refresh();

            Log::info('Multipart part URL sign requested', [
                'upload_session_id' => $uploadSession->id,
                'part_number' => $partNumber,
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
            ]);

            // Sign part upload URL
            $result = $this->multipartService->signPartUploadUrl($uploadSession, $partNumber);

            return response()->json([
                'upload_session_id' => $uploadSession->id,
                'part_number' => $result['part_number'],
                'upload_url' => $result['upload_url'],
                'expires_in' => $result['expires_in'],
            ], 200);
        } catch (\RuntimeException $e) {
            Log::error('Failed to sign multipart part URL', [
                'upload_session_id' => $uploadSession->id,
                'part_number' => $partNumber,
                'error' => $e->getMessage(),
            ]);

            // Phase 2.5 Step 2: Use normalized error response
            return UploadErrorResponse::fromException($e, 400, [
                'upload_session_id' => $uploadSession->id,
                'file_type' => UploadErrorResponse::extractFileType(null, $uploadSession),
                'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
            ]);
        } catch (\Exception $e) {
            Log::error('Unexpected error signing multipart part URL', [
                'upload_session_id' => $uploadSession->id,
                'part_number' => $partNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Phase 2.5 Step 2: Use normalized error response
            return UploadErrorResponse::fromException($e, 500, [
                'upload_session_id' => $uploadSession->id,
                'file_type' => UploadErrorResponse::extractFileType(null, $uploadSession),
                'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
            ]);
        }
    }

    /**
     * Complete a multipart upload.
     *
     * POST /uploads/{uploadSession}/multipart/complete
     *
     * This endpoint is idempotent - if the upload is already completed,
     * it returns success without re-completing.
     *
     * @param Request $request
     * @param UploadSession $uploadSession
     * @return JsonResponse
     */
    public function completeMultipart(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission - viewers cannot upload
        if (!$user->hasPermissionForTenant($tenant, 'asset.upload')) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'You do not have permission to upload assets.',
                403,
                []
            );
        }

        // Verify upload session belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if ($uploadSession->tenant_id !== $tenant->id) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_SESSION_NOT_FOUND,
                'Upload session not found.',
                404,
                [
                    'upload_session_id' => $uploadSession->id,
                    'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
                ]
            );
        }

        // Validate request input
        $validated = $request->validate([
            'parts' => 'required|array|min:1',
            'parts.*' => 'required|string', // ETags as strings
        ]);

        // Convert parts array to [part_number => etag] format
        // Expected format: ["1" => "etag1", "2" => "etag2", ...]
        // Or: [{"part_number": 1, "etag": "etag1"}, ...]
        $parts = [];
        foreach ($validated['parts'] as $key => $value) {
            if (is_array($value) && isset($value['part_number']) && isset($value['etag'])) {
                // Format: [{"part_number": 1, "etag": "etag1"}, ...]
                $parts[$value['part_number']] = $value['etag'];
            } elseif (is_string($value)) {
                // Format: ["1" => "etag1", "2" => "etag2", ...]
                $parts[$key] = $value;
            } else {
                return response()->json([
                    'message' => 'Invalid parts format. Expected array of [part_number => etag] or [{"part_number": int, "etag": string}, ...]',
                ], 422);
            }
        }

        try {
            // Refresh to get latest state
            $uploadSession->refresh();

            Log::info('Multipart upload complete requested', [
                'upload_session_id' => $uploadSession->id,
                'parts_count' => count($parts),
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
            ]);

            // Complete multipart upload (idempotent)
            $result = $this->multipartService->completeMultipartUpload($uploadSession, $parts);

            // Refresh to get updated state
            $uploadSession->refresh();

            return response()->json([
                'upload_session_id' => $uploadSession->id,
                'completed' => $result['completed'],
                'already_completed' => $result['already_completed'],
                'etag' => $result['etag'],
            ], 200);
        } catch (\RuntimeException $e) {
            Log::error('Failed to complete multipart upload', [
                'upload_session_id' => $uploadSession->id,
                'parts_count' => count($parts),
                'error' => $e->getMessage(),
            ]);

            // Phase 2.5 Step 2: Use normalized error response
            return UploadErrorResponse::fromException($e, 400, [
                'upload_session_id' => $uploadSession->id,
                'file_type' => UploadErrorResponse::extractFileType(null, $uploadSession),
                'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
            ]);
        } catch (\Exception $e) {
            Log::error('Unexpected error completing multipart upload', [
                'upload_session_id' => $uploadSession->id,
                'parts_count' => count($parts),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Phase 2.5 Step 2: Use normalized error response
            return UploadErrorResponse::fromException($e, 500, [
                'upload_session_id' => $uploadSession->id,
                'file_type' => UploadErrorResponse::extractFileType(null, $uploadSession),
                'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
            ]);
        }
    }

    /**
     * Abort a multipart upload.
     *
     * POST /uploads/{uploadSession}/multipart/abort
     *
     * This endpoint safely cleans up both S3 and database state.
     * It is idempotent - safe to call multiple times.
     *
     * @param Request $request
     * @param UploadSession $uploadSession
     * @return JsonResponse
     */
    public function abortMultipart(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission - viewers cannot upload
        if (!$user->hasPermissionForTenant($tenant, 'asset.upload')) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'You do not have permission to upload assets.',
                403,
                []
            );
        }

        // Verify upload session belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if ($uploadSession->tenant_id !== $tenant->id) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_SESSION_NOT_FOUND,
                'Upload session not found.',
                404,
                [
                    'upload_session_id' => $uploadSession->id,
                    'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
                ]
            );
        }

        try {
            // Refresh to get latest state
            $uploadSession->refresh();

            Log::info('Multipart upload abort requested', [
                'upload_session_id' => $uploadSession->id,
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'multipart_upload_id' => $uploadSession->multipart_upload_id,
            ]);

            // Abort multipart upload (idempotent)
            $result = $this->multipartService->abortMultipartUpload($uploadSession);

            // Refresh to get updated state
            $uploadSession->refresh();

            return response()->json([
                'upload_session_id' => $uploadSession->id,
                'aborted' => $result['aborted'],
                'already_aborted' => $result['already_aborted'],
            ], 200);
        } catch (\RuntimeException $e) {
            Log::error('Failed to abort multipart upload', [
                'upload_session_id' => $uploadSession->id,
                'error' => $e->getMessage(),
            ]);

            // Phase 2.5 Step 2: Use normalized error response
            return UploadErrorResponse::fromException($e, 400, [
                'upload_session_id' => $uploadSession->id,
                'file_type' => UploadErrorResponse::extractFileType(null, $uploadSession),
                'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
            ]);
        } catch (\Exception $e) {
            Log::error('Unexpected error aborting multipart upload', [
                'upload_session_id' => $uploadSession->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Phase 2.5 Step 2: Use normalized error response
            return UploadErrorResponse::fromException($e, 500, [
                'upload_session_id' => $uploadSession->id,
                'file_type' => UploadErrorResponse::extractFileType(null, $uploadSession),
                'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
            ]);
        }
    }

    /**
     * Finalize multiple uploads from a manifest (manifest-driven batch finalize).
     *
     * POST /app/assets/upload/finalize
     *
     * Processes each manifest item independently. Never fails the entire request
     * due to one bad item. Each item is validated and processed separately.
     *
     * ORPHAN HANDLING:
     * Failed finalize items leave S3 objects in temp/uploads/ - they are NOT deleted synchronously.
     * Synchronous deletion is forbidden to:
     *   - Prevent blocking finalize response (deletion can be slow)
     *   - Allow retries without re-upload (S3 object must exist)
     *   - Avoid race conditions with concurrent finalize attempts
     *   - Enable manual recovery of failed items
     * Cleanup is handled by S3 lifecycle rules (expected window: 7 days).
     * Orphan candidates are logged for monitoring purposes.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function finalize(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // CRITICAL: Brand context must be available (bound by ResolveTenant middleware)
        // This ensures assets are created with the same brand_id used by UI queries
        if (!$brand) {
            Log::error('[UploadController::finalize] Brand context not bound', [
                'tenant_id' => $tenant->id ?? null,
                'note' => 'Brand context must be bound by ResolveTenant middleware. Ensure finalize route is in tenant middleware group.',
            ]);
            return response()->json([
                'message' => 'Brand context is not available. The finalize endpoint must be accessed through the tenant middleware which binds the active brand.',
            ], 500);
        }

        // Verify user belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission - viewers cannot upload
        if (!$user->hasPermissionForTenant($tenant, 'asset.upload')) {
            return response()->json([
                'message' => 'You do not have permission to upload assets.',
            ], 403);
        }

        // Validate request structure
        $validated = $request->validate([
            'manifest' => 'required|array|min:1',
            'manifest.*.upload_key' => 'required|string',
            'manifest.*.expected_size' => 'required|integer|min:1',
            'manifest.*.category_id' => 'required|integer|exists:categories,id',
            'manifest.*.metadata' => 'nullable|array',
            'manifest.*.title' => 'nullable|string|max:255',
            'manifest.*.resolved_filename' => 'nullable|string|max:255',
        ]);

        $manifest = $validated['manifest'];
        $results = [];

        // Process each manifest item independently
        foreach ($manifest as $item) {
            $uploadKey = $item['upload_key'];
            $expectedSize = $item['expected_size'];
            $categoryId = $item['category_id'];
            // CRITICAL: Extract metadata - handle both array and object formats from JSON
            $metadata = $item['metadata'] ?? [];
            // If metadata is an object (stdClass from JSON), convert to array
            if (is_object($metadata)) {
                $metadata = (array) $metadata;
            }
            $title = $item['title'] ?? null;
            $resolvedFilename = $item['resolved_filename'] ?? null;
            

            $uploadSession = null; // Initialize for use in catch blocks
            
            try {
                // Extract upload_session_id from upload_key
                // Format: temp/uploads/{upload_session_id}/original
                if (!preg_match('#^temp/uploads/([^/]+)/original$#', $uploadKey, $matches)) {
                    throw new \RuntimeException("Invalid upload_key format: {$uploadKey}");
                }

                $uploadSessionId = $matches[1];

                // Find upload session (tenant-scoped)
                $uploadSession = UploadSession::where('id', $uploadSessionId)
                    ->where('tenant_id', $tenant->id)
                    ->first();

                if (!$uploadSession) {
                    throw new \RuntimeException("Upload session not found: {$uploadSessionId}");
                }

                // CRITICAL: Verify category belongs to tenant and ACTIVE BRAND (not upload_session->brand_id)
                // The user is selecting a category in the UI, which is scoped to the active brand
                // Assets are created with the active brand_id to match UI queries
                $category = Category::where('id', $categoryId)
                    ->where('tenant_id', $tenant->id)
                    ->where('brand_id', $brand->id)
                    ->first();

                if (!$category) {
                    throw new \RuntimeException("Category not found or does not belong to tenant/brand");
                }

                // IDEMPOTENCY CHECK: Check if asset already exists for this upload_session_id
                // Uses upload_session_id only (unique constraint) - brand_id is determined by active brand context
                // This makes finalize safe under retries, refreshes, and race conditions
                $existingAsset = Asset::where('upload_session_id', $uploadSessionId)
                    ->where('tenant_id', $tenant->id)
                    ->first();

                if ($existingAsset) {
                    // Asset already exists - return existing asset (idempotent)
                    $results[] = [
                        'upload_key' => $uploadKey,
                        'status' => 'success',
                        'asset_id' => $existingAsset->id,
                    ];
                    continue; // Skip to next manifest item
                }

                // Verify S3 object exists and size matches
                $this->verifyS3Upload($uploadSession, $uploadKey, $expectedSize);

                // Validate resolved_filename extension matches original file extension
                if ($resolvedFilename !== null) {
                    $bucket = $uploadSession->storageBucket;
                    if (!$bucket) {
                        throw new \RuntimeException('Storage bucket not found for upload session');
                    }
                    
                    $s3Client = $this->createS3ClientForFinalize(); // Reuse same client from verifyS3Upload
                    
                    try {
                        // Get object metadata to extract original filename
                        $headResult = $s3Client->headObject([
                            'Bucket' => $bucket->name,
                            'Key' => $uploadKey,
                        ]);
                        
                        // Extract filename from S3 metadata (same logic as UploadCompletionService)
                        // CRITICAL: Use different variable name to avoid overwriting $metadata (which contains photo_type)
                        $s3Metadata = $headResult->get('Metadata');
                        $contentDisposition = $headResult->get('ContentDisposition');
                        
                        $originalFilename = null;
                        if ($contentDisposition && preg_match('/filename[^;=\n]*=(([\'"]).*?\2|[^;\n]*)/', $contentDisposition, $matches)) {
                            $originalFilename = trim($matches[1], '"\'');
                        } elseif (isset($s3Metadata['original-filename'])) {
                            $originalFilename = $s3Metadata['original-filename'];
                        }
                        
                        // If we have an original filename, validate extension
                        if ($originalFilename) {
                            $originalExt = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
                            $resolvedExt = strtolower(pathinfo($resolvedFilename, PATHINFO_EXTENSION));
                            
                            // Extensions must match (case-insensitive)
                            if ($originalExt !== $resolvedExt) {
                                throw new \RuntimeException(
                                    "Resolved filename extension mismatch. Original file has extension '{$originalExt}', but resolved filename has '{$resolvedExt}'. File extensions cannot be changed."
                                );
                            }
                        }
                    } catch (\RuntimeException $e) {
                        // Re-throw validation errors
                        throw $e;
                    } catch (\Exception $e) {
                        // For other errors (S3 access issues), log warning but don't block
                        // The completion service will also validate, so this is a best-effort check
                        Log::warning('Could not validate resolved filename extension in finalize', [
                            'upload_session_id' => $uploadSessionId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Create asset using UploadCompletionService
                // Determine asset type from category
                $assetType = $category->asset_type->value;

                // Phase 2 – Step 4: Extract and validate metadata fields BEFORE asset creation
                // Frontend sends: { fieldKey: value } (only valid fields, no empty values)
                $metadataFields = [];
                // Handle both array and object formats
                if (!empty($metadata)) {
                    // Convert object to array if needed
                    $metadataArray = is_object($metadata) ? (array)$metadata : $metadata;
                    
                    if (is_array($metadataArray)) {
                        if (isset($metadataArray['fields']) && is_array($metadataArray['fields'])) {
                            // Legacy format: { fields: {...} }
                            $metadataFields = $metadataArray['fields'];
                        } elseif (isset($metadataArray['category_id'])) {
                            // Metadata has category_id mixed in - extract fields only
                            $metadataFields = $metadataArray;
                            unset($metadataFields['category_id']);
                        } else {
                            // Direct format: { fieldKey: value } (Phase 2 – Step 4 format)
                            $metadataFields = $metadataArray;
                        }
                    }
                }

                // Phase 2 – Step 4: Validate metadata against schema BEFORE asset creation
                if (!empty($metadataFields)) {
                    try {
                        // Determine asset type for schema resolution (file type, not category asset_type)
                        // Default to 'image' - can be enhanced to detect from file MIME type
                        $fileAssetType = 'image';

                        // Resolve schema to validate fields
                        $schema = $this->uploadMetadataSchemaResolver->resolve(
                            $tenant->id,
                            $brand->id,
                            $category->id,
                            $fileAssetType
                        );

                        // Build allowlist of valid field keys
                        $allowedFieldKeys = [];
                        foreach ($schema['groups'] ?? [] as $group) {
                            foreach ($group['fields'] ?? [] as $field) {
                                $allowedFieldKeys[] = $field['key'];
                            }
                        }

                        // Validate all provided field keys are in the allowlist
                        $invalidKeys = array_diff(array_keys($metadataFields), $allowedFieldKeys);
                        if (!empty($invalidKeys)) {
                            throw new \InvalidArgumentException(
                                'Invalid metadata fields: ' . implode(', ', $invalidKeys) . '. ' .
                                'Fields must be present in the resolved upload schema.'
                            );
                        }
                    } catch (\InvalidArgumentException $e) {
                        // Validation failure - return error BEFORE creating asset
                        Log::error('[UploadController::finalize] Metadata validation failed', [
                            'upload_key' => $uploadKey,
                            'error' => $e->getMessage(),
                            'metadata_fields' => array_keys($metadataFields),
                        ]);

                        $results[] = [
                            'upload_key' => $uploadKey,
                            'status' => 'error',
                            'error' => 'Metadata validation failed: ' . $e->getMessage(),
                        ];
                        continue; // Skip to next manifest item
                    }
                }

                $asset = $this->completionService->complete(
                    $uploadSession,
                    $assetType,
                    $resolvedFilename, // filename - use resolvedFilename from frontend, fallback to S3 if null
                    $title, // title - use title from frontend, fallback to filename if null
                    $uploadKey, // s3Key
                    $categoryId,
                    null, // Do NOT pass metadata here - persist separately below with approval check
                    $user->id
                );

                // IMPORTANT:
                // Metadata MUST be persisted exactly once during upload.
                // UploadController::finalize() is the single source of truth for
                // metadata persistence so that approval logic is always enforced.
                // Do NOT persist metadata inside UploadCompletionService.
                // Phase 2 – Step 4: Persist metadata to asset_metadata table (after asset creation)
                // UX-2: CRITICAL - Metadata persistence happens AFTER asset creation
                // This ensures approval logic runs only after asset exists, not during upload
                if (!empty($metadataFields)) {
                    try {
                        // UX-2: Assertion - Asset must exist before metadata persistence
                        // This guard ensures approval logic runs only after asset creation
                        if (!$asset || !$asset->id) {
                            Log::error('[UploadController::finalize] Asset not created before metadata persistence', [
                                'upload_key' => $uploadKey,
                                'upload_session_id' => $uploadSessionId,
                            ]);
                            throw new \RuntimeException('Asset must be created before metadata persistence');
                        }

                        // Determine asset type for schema resolution (file type, not category asset_type)
                        // Default to 'image' - can be enhanced to detect from file MIME type
                        $fileAssetType = 'image';

                        // UX-2: CRITICAL - Pass autoApprove=false to allow approval resolver to check
                        // Approval is determined AFTER upload, not during metadata entry
                        // This ensures contributors can enter metadata, which is then reviewed
                        $this->metadataPersistenceService->persistMetadata(
                            $asset,
                            $category,
                            $metadataFields,
                            $user->id,
                            $fileAssetType,
                            false // autoApprove=false: Let approval resolver determine if approval needed
                        );
                    } catch (\Exception $e) {
                        // Persistence failure - log but don't fail entire finalize
                        // Asset is already created, metadata persistence is best-effort
                        // Transaction rollback in service handles partial writes
                        Log::error('[UploadController::finalize] Metadata persistence failed', [
                            'asset_id' => $asset->id,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);

                        // Continue - asset is created, metadata persistence failed
                        // This is acceptable as metadata can be added later via edit
                    }
                }

                // Success result
                $results[] = [
                    'upload_key' => $uploadKey,
                    'status' => 'success',
                    'asset_id' => $asset->id,
                ];

                // Note: Activity logging is handled by UploadCompletionService::complete()
                // which logs ASSET_UPLOAD_FINALIZED (the canonical event for processing start)
            } catch (\RuntimeException $e) {
                // Phase 2.5 Step 2: Normalize error response for finalize failures
                $errorMessage = $e->getMessage();
                
                // Determine error code and category from exception
                // Map to normalized error codes matching frontend expectations
                $errorCode = UploadErrorResponse::CODE_VALIDATION_FAILED;
                if (str_contains($errorMessage, 'does not exist in S3') || 
                    str_contains($errorMessage, 'not found in S3') ||
                    str_contains($errorMessage, 'Upload does not exist')) {
                    $errorCode = UploadErrorResponse::CODE_FILE_MISSING;
                } elseif (str_contains($errorMessage, 'not found') || 
                         str_contains($errorMessage, 'does not exist')) {
                    $errorCode = UploadErrorResponse::CODE_SESSION_NOT_FOUND;
                }
                
                $category = UploadErrorResponse::getCategoryFromErrorCode($errorCode);
                
                // Extract file type from upload session
                $fileType = $uploadSession 
                    ? UploadErrorResponse::extractFileType(null, $uploadSession)
                    : null;
                
                $results[] = [
                    'upload_key' => $uploadKey,
                    'status' => 'failed',
                    'error' => [
                        'code' => $errorCode,
                        'message' => $errorMessage,
                        // Include normalized error structure for AI agents
                        'error_code' => $errorCode,
                        'category' => $category,
                        'context' => [
                            'upload_session_id' => $uploadSession?->id,
                            'file_type' => $fileType,
                            'pipeline_stage' => UploadErrorResponse::STAGE_FINALIZE,
                        ],
                    ],
                ];

                // ORPHAN HANDLING: Log orphan candidate for monitoring
                // S3 object remains in temp/uploads/ - NOT deleted synchronously
                // Cleanup handled by S3 lifecycle rules (7 day window)
                if (!$isFileMissing && $uploadSession) {
                    Log::warning('Orphan candidate: Failed finalize item', [
                        'upload_key' => $uploadKey,
                        'upload_session_id' => $uploadSession->id ?? null,
                        'tenant_id' => $tenant->id,
                        'brand_id' => $uploadSession->brand_id ?? null,
                        'error_code' => $errorCode,
                        'error_message' => $errorMessage,
                        'note' => 'S3 object left in temp/uploads/ for lifecycle cleanup (7 days)',
                    ]);
                }

                // Record activity event based on error type
                if ($isFileMissing) {
                    // asset.file_missing
                    ActivityRecorder::record(
                        tenant: $tenant,
                        eventType: 'asset.file_missing',
                        subject: null,
                        actor: $user,
                        brand: $uploadSession->brand_id ?? null,
                        metadata: [
                            'upload_key' => $uploadKey,
                        ]
                    );
                } else {
                    // asset.create_failed (for other RuntimeExceptions)
                    ActivityRecorder::record(
                        tenant: $tenant,
                        eventType: 'asset.create_failed',
                        subject: null,
                        actor: $user,
                        brand: $uploadSession->brand_id ?? null,
                        metadata: [
                            'upload_key' => $uploadKey,
                            'reason' => $errorCode,
                            'error' => $errorMessage,
                        ]
                    );
                }
            } catch (\Illuminate\Validation\ValidationException $e) {
                // Validation errors with fields
                $results[] = [
                    'upload_key' => $uploadKey,
                    'status' => 'failed',
                    'error' => [
                        'code' => 'validation_error',
                        'message' => 'Validation failed',
                        'fields' => $e->errors(),
                    ],
                ];

                // ORPHAN HANDLING: Log orphan candidate for monitoring
                // S3 object remains in temp/uploads/ - NOT deleted synchronously
                // Cleanup handled by S3 lifecycle rules (7 day window)
                if ($uploadSession) {
                    Log::warning('Orphan candidate: Failed finalize item (validation)', [
                        'upload_key' => $uploadKey,
                        'upload_session_id' => $uploadSession->id ?? null,
                        'tenant_id' => $tenant->id,
                        'brand_id' => $uploadSession->brand_id ?? null,
                        'error_fields' => array_keys($e->errors()),
                        'note' => 'S3 object left in temp/uploads/ for lifecycle cleanup (7 days)',
                    ]);
                }

                // Record activity event: asset.validation_failed
                ActivityRecorder::record(
                    tenant: $tenant,
                    eventType: 'asset.validation_failed',
                    subject: null,
                    actor: $user,
                    brand: $uploadSession->brand_id ?? null,
                    metadata: [
                        'upload_key' => $uploadKey,
                        'fields' => $e->errors(),
                    ]
                );
            } catch (\Exception $e) {
                // Unexpected errors - log and return as failed item
                Log::error('Unexpected error finalizing upload', [
                    'upload_key' => $uploadKey,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $errorCode = 'server_error';
                $errorMessage = 'An unexpected error occurred';

                $results[] = [
                    'upload_key' => $uploadKey,
                    'status' => 'failed',
                    'error' => [
                        'code' => $errorCode,
                        'message' => $errorMessage,
                    ],
                ];

                // ORPHAN HANDLING: Log orphan candidate for monitoring
                // S3 object remains in temp/uploads/ - NOT deleted synchronously
                // Cleanup handled by S3 lifecycle rules (7 day window)
                if ($uploadSession) {
                    Log::warning('Orphan candidate: Failed finalize item (server error)', [
                        'upload_key' => $uploadKey,
                        'upload_session_id' => $uploadSession->id ?? null,
                        'tenant_id' => $tenant->id,
                        'brand_id' => $uploadSession->brand_id ?? null,
                        'error_code' => $errorCode,
                        'error_message' => $errorMessage,
                        'note' => 'S3 object left in temp/uploads/ for lifecycle cleanup (7 days)',
                    ]);
                }

                // Record activity event: asset.create_failed
                ActivityRecorder::record(
                    tenant: $tenant,
                    eventType: 'asset.create_failed',
                    subject: null,
                    actor: $user,
                    brand: $uploadSession->brand_id ?? null,
                    metadata: [
                        'upload_key' => $uploadKey,
                        'reason' => $errorCode,
                        'error' => $errorMessage,
                    ]
                );
            }
        }

        // Always return 200 OK with results array
        // Individual items may have status 'success' or 'failed'
        return response()->json([
            'results' => $results,
        ], 200);
    }

    /**
     * Verify S3 upload exists and matches expected size.
     *
     * @param UploadSession $uploadSession
     * @param string $uploadKey
     * @param int $expectedSize
     * @return void
     * @throws \RuntimeException If verification fails
     */
    protected function verifyS3Upload(UploadSession $uploadSession, string $uploadKey, int $expectedSize): void
    {
        $bucket = $uploadSession->storageBucket;
        if (!$bucket) {
            throw new \RuntimeException('Storage bucket not found for upload session');
        }

        // Create S3 client using reflection to access protected method
        // Or create our own instance using the same configuration
        $s3Client = $this->createS3ClientForFinalize();

        try {
            // Verify object exists in S3
            $exists = $s3Client->doesObjectExist($bucket->name, $uploadKey);

            if (!$exists) {
                throw new \RuntimeException("Upload does not exist in S3: {$uploadKey}");
            }

            // Get object metadata to verify size
            $headResult = $s3Client->headObject([
                'Bucket' => $bucket->name,
                'Key' => $uploadKey,
            ]);

            $actualSize = (int) $headResult->get('ContentLength');

            // Verify file size matches expected size
            if ($actualSize !== $expectedSize) {
                throw new \RuntimeException(
                    "File size mismatch. Expected: {$expectedSize} bytes, Actual: {$actualSize} bytes"
                );
            }
        } catch (S3Exception $e) {
            Log::error('S3 verification failed', [
                'upload_key' => $uploadKey,
                'error' => $e->getMessage(),
                'code' => $e->getAwsErrorCode(),
            ]);

            throw new \RuntimeException(
                "Failed to verify upload in S3: {$e->getMessage()}"
            );
        }
    }

    /**
     * Receive frontend upload diagnostics.
     *
     * POST /uploads/diagnostics
     *
     * This endpoint receives structured diagnostic information from the frontend
     * about upload failures. It logs the information for debugging purposes.
     * 
     * Phase 2.5: Dev-only observability feature
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function diagnostics(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Validate request
        $validated = $request->validate([
            'type' => 'required|string|in:auth,cors,network,s3,validation,unknown',
            'message' => 'required|string',
            'http_status' => 'nullable|integer|min:100|max:599',
            'request_phase' => 'nullable|string|max:255',
            'upload_session_id' => 'nullable|uuid',
            'file_name' => 'nullable|string|max:255',
            'file_size' => 'nullable|integer|min:0',
            'details' => 'nullable|array',
            'timestamp' => 'nullable|string',
            'user_agent' => 'nullable|string|max:500',
            'is_online' => 'nullable|boolean',
        ]);

        try {
            // Build structured log context
            $logContext = [
                'diagnostic_type' => 'upload_error',
                'error_type' => $validated['type'],
                'error_message' => $validated['message'],
                'http_status' => $validated['http_status'] ?? null,
                'request_phase' => $validated['request_phase'] ?? null,
                'upload_session_id' => $validated['upload_session_id'] ?? null,
                'file_name' => $validated['file_name'] ?? null,
                'file_size' => $validated['file_size'] ?? null,
                'details' => $validated['details'] ?? [],
                'timestamp' => $validated['timestamp'] ?? now()->toIso8601String(),
                'user_agent' => $validated['user_agent'] ?? null,
                'is_online' => $validated['is_online'] ?? null,
                'tenant_id' => $tenant->id ?? null,
                'user_id' => $user->id ?? null,
            ];

            // Log structured diagnostic information
            Log::info('[Upload Diagnostics] Frontend error reported', $logContext);

            // Phase 2.65: Emit normalized upload signal for AI analysis
            // Signal emission is best-effort and never throws
            try {
                $signalService = app(\App\Services\UploadSignalService::class);
                $signalService->emitErrorSignal($validated, $tenant);
            } catch (\Exception $signalError) {
                // Silently fail - signal emission must not disrupt diagnostics flow
                Log::debug('[Upload Diagnostics] Signal emission failed (non-critical)', [
                    'error' => $signalError->getMessage(),
                ]);
            }

            // Return success (never throw - diagnostics are best-effort)
            return response()->json([
                'message' => 'Diagnostics received',
                'logged' => true,
            ], 200);
        } catch (\Exception $e) {
            // Never throw from diagnostics endpoint - it's best-effort observability
            Log::warning('[Upload Diagnostics] Failed to process diagnostics', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenant->id ?? null,
                'user_id' => $user->id ?? null,
            ]);

            // Still return success to prevent frontend errors
            return response()->json([
                'message' => 'Diagnostics received (processing failed)',
                'logged' => false,
            ], 200);
        }
    }

    /**
     * Create S3 client instance for finalize verification.
     *
     * @return S3Client
     * @throws \RuntimeException
     */
    protected function createS3ClientForFinalize(): S3Client
    {
        if (!class_exists(S3Client::class)) {
            throw new \RuntimeException(
                'AWS SDK for PHP is required for upload finalization. ' .
                'Install it via: composer require aws/aws-sdk-php'
            );
        }

        $config = [
            'version' => 'latest',
            'region' => config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1')),
        ];

        // Add credentials if provided
        if (config('filesystems.disks.s3.key') && config('filesystems.disks.s3.secret')) {
            $config['credentials'] = [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ];
        }

        // Add endpoint for MinIO/local S3
        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }

        return new S3Client($config);
    }

    /**
     * Get upload metadata schema for a given context.
     *
     * GET /uploads/metadata-schema
     *
     * Phase 2 – Step 2: Returns upload metadata schema for UI rendering.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getMetadataSchema(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Verify user belongs to tenant
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Unauthorized. Please check your account permissions.',
            ], 403);
        }

        // Validate request
        $validated = $request->validate([
            'category_id' => 'required|integer|exists:categories,id',
            'asset_type' => 'nullable|string|in:image,video,document',
        ]);

        // Verify category belongs to tenant and brand
        $category = Category::where('id', $validated['category_id'])
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->first();

        if (!$category) {
            return response()->json([
                'error' => 'Invalid category',
                'message' => 'Category must belong to this brand.',
            ], 422);
        }

        // Determine asset type (file type, not category asset_type)
        // Default to 'image' if not provided
        $assetType = $validated['asset_type'] ?? 'image';

        try {
            // Phase 4: Get user role for permission checks
            $userRole = $user->getRoleForBrand($brand) ?? $user->getRoleForTenant($tenant) ?? 'member';

            // Resolve upload metadata schema
            $schema = $this->uploadMetadataSchemaResolver->resolve(
                $tenant->id,
                $brand->id,
                $category->id,
                $assetType,
                $userRole
            );

            return response()->json($schema);
        } catch (\Exception $e) {
            Log::error('[Upload Metadata Schema] Failed to resolve schema', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'category_id' => $category->id,
                'asset_type' => $assetType,
            ]);

            return response()->json([
                'error' => 'Failed to load metadata schema',
                'message' => 'An error occurred while loading metadata fields.',
            ], 500);
        }
    }
}
