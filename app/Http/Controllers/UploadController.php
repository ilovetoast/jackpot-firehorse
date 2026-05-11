<?php

namespace App\Http\Controllers;

use App\Enums\AssetType;
use App\Exceptions\CreatorModuleInactiveException;
use App\Exceptions\UploadContentRejectedException;
use App\Http\Responses\UploadErrorResponse;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\AbandonedSessionService;
use App\Services\ActivityRecorder;
use App\Services\AssetEligibilityService;
use App\Services\CollectionAssetService;
use App\Services\FileTypeService;
use App\Services\Metadata\AssetMetadataStateResolver;
use App\Services\MetadataApprovalResolver;
use App\Services\MetadataPersistenceService;
use App\Services\MultipartUploadService;
use App\Services\MultipartUploadUrlService;
use App\Services\PlanService;
use App\Services\ResumeMetadataService;
use App\Services\UploadCompletionService;
use App\Services\UploadInitiationService;
use App\Services\UploadMetadataSchemaResolver;
use App\Services\UploadPreflightService;
use App\Support\Billing\PlanLimitUpgradePayload;
use App\Support\Metadata\CategoryTypeResolver;
use App\Support\UploadAuditLogger;
use Aws\S3\S3Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class UploadController extends Controller
{
    /**
     * One S3 client per finalize HTTP request — constructing Aws\S3\S3Client repeatedly
     * loads endpoint partition data each time and can exceed PHP max_execution_time on
     * multi-item manifests or extension checks (see PartitionEndpointProvider).
     */
    private ?S3Client $finalizeS3Client = null;

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
        protected PlanService $planService,
        protected AssetMetadataStateResolver $metadataStateResolver,
        protected MetadataApprovalResolver $approvalResolver,
        protected CollectionAssetService $collectionAssetService,
        protected AssetEligibilityService $assetEligibilityService,
        protected UploadPreflightService $uploadPreflightService,
        protected FileTypeService $fileTypeService,
    ) {}

    /**
     * Resolve brand for upload authorization (session row, request brand_id, container brand, tenant default).
     */
    private function brandForUploadGate(Tenant $tenant, ?Request $request = null, ?UploadSession $uploadSession = null): ?Brand
    {
        if ($uploadSession && $uploadSession->brand_id) {
            $b = Brand::query()
                ->where('id', $uploadSession->brand_id)
                ->where('tenant_id', $tenant->id)
                ->first();
            if ($b) {
                return $b;
            }
        }
        if ($request && $request->has('brand_id') && $request->input('brand_id') !== null && $request->input('brand_id') !== '') {
            return Brand::query()
                ->where('id', (int) $request->input('brand_id'))
                ->where('tenant_id', $tenant->id)
                ->first();
        }
        if (app()->bound('brand')) {
            $b = app('brand');
            if ($b instanceof Brand && (int) $b->tenant_id === (int) $tenant->id) {
                return $b;
            }
        }

        return $tenant->defaultBrand;
    }

    /**
     * Upload permission must respect **brand** role (e.g. brand viewer is read-only even if tenant member has asset.upload).
     */
    private function userMayUploadForActiveBrand(User $user, Tenant $tenant, ?Request $request = null, ?UploadSession $uploadSession = null): bool
    {
        $brand = $this->brandForUploadGate($tenant, $request, $uploadSession);
        if (! $brand instanceof Brand) {
            return false;
        }

        return $user->canForContext('asset.upload', $tenant, $brand);
    }

    /**
     * Initiate an upload session.
     *
     * POST /uploads/initiate
     */
    public function initiate(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if (! $user || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission — brand-scoped (brand viewer cannot upload)
        if (! $this->userMayUploadForActiveBrand($user, $tenant, $request)) {
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
            'builder_staged' => 'nullable|boolean',
            'builder_context' => 'nullable|string|max:64', // e.g. logo_primary, photo_reference, texture_reference
        ]);

        // Filename hardening + Gate 2 (single-file path) — same allowlist decision
        // as initiate-batch so this route can never be used to bypass the gate.
        $sanitizedName = $this->fileTypeService->sanitizeFilename((string) $validated['file_name']);
        if ($sanitizedName === '' || $sanitizedName !== $validated['file_name']) {
            UploadAuditLogger::warning([
                'gate' => 'initiate',
                'reason' => 'invalid_filename',
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'name' => $validated['file_name'],
            ]);

            return response()->json([
                'error' => 'invalid_filename',
                'code' => 'invalid_filename',
                'message' => 'File name contains characters that are not allowed. Please rename the file and try again.',
            ], 422);
        }
        $validated['file_name'] = $sanitizedName;

        $double = $this->fileTypeService->detectDoubleExtensionAttack($validated['file_name']);
        if ($double !== null) {
            UploadAuditLogger::warning([
                'gate' => 'initiate',
                'reason' => 'double_extension',
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'name' => $validated['file_name'],
                'hit_extension' => $double['hit_extension'],
                'group' => $double['group'],
            ]);

            return response()->json([
                'error' => 'upload_not_allowed',
                'code' => 'blocked_double_extension',
                'message' => $double['message'],
            ], 422);
        }

        $extForGate = strtolower((string) pathinfo($validated['file_name'], PATHINFO_EXTENSION));
        $decision = $this->fileTypeService->isUploadAllowed($validated['mime_type'] ?? null, $extForGate);
        if (! $decision['allowed']) {
            UploadAuditLogger::log(
                $decision['log_severity'] === 'warning' ? 'warning' : 'info',
                [
                    'gate' => 'initiate',
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'name' => $validated['file_name'],
                    'mime_type' => $validated['mime_type'] ?? null,
                    'extension' => $extForGate,
                    'code' => $decision['code'],
                    'blocked_group' => $decision['blocked_group'],
                    'file_type' => $decision['file_type'],
                ]
            );

            return response()->json([
                'error' => 'upload_not_allowed',
                'code' => $decision['code'],
                'message' => $decision['message'],
            ], 422);
        }

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

        $builderOptions = [];
        if (! empty($validated['builder_staged']) && ! empty($validated['builder_context'])) {
            $builderOptions = [
                'builder_staged' => true,
                'builder_context' => $validated['builder_context'],
            ];
        }

        try {
            $result = $this->uploadService->initiate(
                $tenant,
                $brand,
                $validated['file_name'],
                $validated['file_size'],
                $validated['mime_type'] ?? null,
                $validated['client_reference'] ?? null,
                $builderOptions
            );

            return response()->json([
                'upload_session_id' => $result['upload_session_id'],
                'upload_key' => $result['upload_key'] ?? null,
                'client_reference' => $result['client_reference'],
                'upload_session_status' => $result['upload_session_status'],
                'upload_type' => $result['upload_type'],
                'upload_url' => $result['upload_url'],
                'multipart_upload_id' => $result['multipart_upload_id'],
                'chunk_size' => $result['chunk_size'],
                'expires_at' => $result['expires_at'],
            ], 201);
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            if ($e->limitType === 'storage') {
                return UploadErrorResponse::storageLimitExceeded($tenant);
            }

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
     */
    public function checkStorageLimits(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        if (! $user || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (! $this->userMayUploadForActiveBrand($user, $tenant, $request)) {
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
     */
    public function validateUpload(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        if (! $user || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (! $this->userMayUploadForActiveBrand($user, $tenant, $request)) {
            return response()->json([
                'error' => 'You do not have permission to upload assets.',
            ], 403);
        }

        $validated = $request->validate([
            'files' => 'required|array|min:1|max:'.UploadPreflightService::maxFilesPerBatch(),
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
                        'message' => 'File size ('.round($fileSize / 1024 / 1024, 2).' MB) exceeds maximum upload size ('.round($maxUploadSizeBytes / 1024 / 1024, 2).' MB) for your plan.',
                        'plan_limit' => PlanLimitUpgradePayload::buildForUploadSizeExceeded($tenant, $fileSize),
                    ];
                }

                // Check if this file alone would exceed storage
                if (! $this->planService->canAddFile($tenant, $fileSize)) {
                    $canUpload = false;
                    $errors[] = [
                        'type' => 'storage_limit',
                        'message' => 'This file would exceed your storage limit.',
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
            $batchStorageExceeded = ! $this->planService->canAddFile($tenant, $totalSize);

            return response()->json([
                'files' => $results,
                'batch_summary' => [
                    'total_files' => count($validated['files']),
                    'total_size_bytes' => $totalSize,
                    'total_size_mb' => round($totalSize / 1024 / 1024, 2),
                    'can_upload_batch' => ! $batchStorageExceeded && collect($results)->every('can_upload'),
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
     * Metadata-only upload preflight (no bytes, no assets, no AI billing).
     *
     * POST /uploads/preflight
     */
    public function preflight(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $user || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (! $this->userMayUploadForActiveBrand($user, $tenant, $request)) {
            return response()->json([
                'error' => 'You do not have permission to upload assets.',
            ], 403);
        }

        $validated = $request->validate([
            'brand_id' => 'required|integer|exists:brands,id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'collection_ids' => 'nullable|array',
            'collection_ids.*' => 'integer|exists:collections,id',
            'assume_auto_ai_metadata' => 'nullable|boolean',
            'files' => 'required|array|min:1|max:'.UploadPreflightService::maxFilesPerBatch(),
            'files.*.client_file_id' => 'required|uuid',
            'files.*.name' => 'required|string|max:255',
            'files.*.size' => 'required|integer|min:1',
            'files.*.mime_type' => 'nullable|string|max:255',
            'files.*.extension' => 'nullable|string|max:32',
            'files.*.last_modified' => 'nullable|numeric',
            'files.*.relative_path' => 'nullable|string|max:2048',
        ]);

        $brand = Brand::where('id', $validated['brand_id'])
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $category = null;
        if (! empty($validated['category_id'])) {
            $category = Category::where('id', $validated['category_id'])
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->first();
            if (! $category) {
                return response()->json([
                    'error' => 'invalid_category',
                    'message' => 'Invalid category. Category must belong to this brand.',
                ], 422);
            }
        }

        $payload = $this->uploadPreflightService->evaluate(
            $tenant,
            $user,
            $brand,
            $category,
            $validated['collection_ids'] ?? null,
            $validated['files'],
            (bool) ($validated['assume_auto_ai_metadata'] ?? false),
        );

        return response()->json($payload);
    }

    /**
     * Complete an upload session and create an asset.
     *
     * POST /assets/upload/complete
     */
    public function complete(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if (! $user || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
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

        if (! $this->userMayUploadForActiveBrand($user, $tenant, $request, $uploadSession)) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'You do not have permission to upload assets.',
                403,
                []
            );
        }

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
        } catch (CreatorModuleInactiveException $e) {
            return response()->json($e->clientPayload(), 403);
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
     */
    public function initiateBatch(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if (! $user || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        if (! $this->userMayUploadForActiveBrand($user, $tenant, $request)) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'You do not have permission to upload assets.',
                403,
                []
            );
        }

        // Validate request
        $validated = $request->validate([
            'files' => 'required|array|min:1|max:'.UploadPreflightService::maxFilesPerBatch(), // Same cap as preflight (large batches)
            'files.*.file_name' => 'required|string|max:255',
            'files.*.file_size' => 'required|integer|min:1',
            'files.*.mime_type' => 'nullable|string|max:255',
            'files.*.client_reference' => 'nullable|uuid', // Optional client reference for frontend mapping
            'brand_id' => 'nullable|exists:brands,id', // Shared brand for all files in batch
            'batch_reference' => 'nullable|uuid', // Optional batch-level correlation ID for grouping/debugging/analytics
            'category_id' => 'nullable|integer|exists:categories,id', // Category is optional for upload initiation (required for finalization)
            /** When set, each file must match a prior {@see preflight()} acceptance (tenant, user, brand, name, size). */
            'preflight_id' => 'nullable|uuid',
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

        if (! $brand) {
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

            if (! $category) {
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
            // Prepare files array for service — sanitize filename + harden against
            // path-traversal, NUL bytes, Windows-reserved names, and excessive length.
            $files = array_map(function ($file) {
                $name = $this->fileTypeService->sanitizeFilename((string) $file['file_name']);

                return [
                    'file_name' => $name !== '' ? $name : (string) $file['file_name'],
                    'file_size' => $file['file_size'],
                    'mime_type' => $file['mime_type'] ?? null,
                    'client_reference' => $file['client_reference'] ?? null,
                ];
            }, $validated['files']);

            // Gate 2: server-side allowlist enforcement at initiate-batch.
            // Runs unconditionally — even when the client omits preflight_id (the
            // historical Retry bypass). Any blocked / unsupported / coming-soon
            // file fails the entire batch with a 422 + structured error code.
            foreach ($files as $i => $f) {
                $rawName = (string) $f['file_name'];
                $ext = strtolower((string) pathinfo($rawName, PATHINFO_EXTENSION));

                if ($rawName === '' || $rawName !== $this->fileTypeService->sanitizeFilename($rawName)) {
                    UploadAuditLogger::warning([
                        'gate' => 'initiate_batch',
                        'reason' => 'invalid_filename',
                        'tenant_id' => $tenant->id,
                        'user_id' => $user->id,
                        'brand_id' => $brand->id,
                        'name' => $rawName,
                        'index' => $i,
                    ]);

                    return response()->json([
                        'error' => 'invalid_filename',
                        'code' => 'invalid_filename',
                        'message' => 'File name contains characters that are not allowed. Please rename the file and try again.',
                        'index' => $i,
                    ], 422);
                }

                $double = $this->fileTypeService->detectDoubleExtensionAttack($rawName);
                if ($double !== null) {
                    UploadAuditLogger::warning([
                        'gate' => 'initiate_batch',
                        'reason' => 'double_extension',
                        'tenant_id' => $tenant->id,
                        'user_id' => $user->id,
                        'brand_id' => $brand->id,
                        'name' => $rawName,
                        'hit_extension' => $double['hit_extension'],
                        'group' => $double['group'],
                        'index' => $i,
                    ]);

                    return response()->json([
                        'error' => 'upload_not_allowed',
                        'code' => 'blocked_double_extension',
                        'message' => $double['message'],
                        'index' => $i,
                    ], 422);
                }

                $decision = $this->fileTypeService->isUploadAllowed($f['mime_type'] ?? null, $ext);
                if (! $decision['allowed']) {
                    UploadAuditLogger::log(
                        $decision['log_severity'] === 'warning' ? 'warning' : 'info',
                        [
                            'gate' => 'initiate_batch',
                            'tenant_id' => $tenant->id,
                            'user_id' => $user->id,
                            'brand_id' => $brand->id,
                            'name' => $rawName,
                            'mime_type' => $f['mime_type'] ?? null,
                            'extension' => $ext,
                            'code' => $decision['code'],
                            'blocked_group' => $decision['blocked_group'],
                            'file_type' => $decision['file_type'],
                            'index' => $i,
                            'has_preflight' => ! empty($validated['preflight_id']),
                        ]
                    );

                    return response()->json([
                        'error' => 'upload_not_allowed',
                        'code' => $decision['code'],
                        'message' => $decision['message'],
                        'index' => $i,
                    ], 422);
                }
            }

            if (! empty($validated['preflight_id'])) {
                $preflightPayload = $this->uploadPreflightService->getCachedPayload($validated['preflight_id']);
                if (
                    ! is_array($preflightPayload)
                    || (int) ($preflightPayload['tenant_id'] ?? 0) !== (int) $tenant->id
                    || (int) ($preflightPayload['user_id'] ?? 0) !== (int) $user->id
                    || (int) ($preflightPayload['brand_id'] ?? 0) !== (int) $brand->id
                ) {
                    return response()->json([
                        'error' => 'preflight_invalid',
                        'message' => 'Preflight session is missing, expired, or does not match this upload. Run upload preflight again.',
                    ], 422);
                }
                $allowed = $preflightPayload['accepted'] ?? [];
                foreach ($files as $index => $fileRow) {
                    $ref = $fileRow['client_reference'] ?? null;
                    if (! $ref || ! is_string($ref) || ! isset($allowed[$ref])) {
                        return response()->json([
                            'error' => 'preflight_mismatch',
                            'message' => 'One or more files were not in the accepted preflight list.',
                            'index' => $index,
                        ], 422);
                    }
                    $slot = $allowed[$ref];
                    if ((string) ($slot['file_name'] ?? '') !== (string) $fileRow['file_name']
                        || (int) ($slot['file_size'] ?? 0) !== (int) $fileRow['file_size']) {
                        return response()->json([
                            'error' => 'preflight_tamper',
                            'message' => 'File name or size does not match the preflight manifest.',
                            'index' => $index,
                        ], 422);
                    }
                }
            }

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
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            if ($e->limitType === 'storage') {
                return UploadErrorResponse::storageLimitExceeded($tenant);
            }

            return UploadErrorResponse::fromException($e, 403, [
                'pipeline_stage' => UploadErrorResponse::STAGE_UPLOAD,
            ]);
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
     */
    public function cancel(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if (! $user || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
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

            if ($isTerminal || ! $wasCancelled) {
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
     */
    public function resume(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if (! $user || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission — brand-scoped (brand viewer cannot upload)
        if (! $this->userMayUploadForActiveBrand($user, $tenant, $request, $uploadSession)) {
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

            if (! $metadata['can_resume'] && $metadata['error']) {
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
     */
    public function updateActivity(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if (! $user || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission — brand-scoped (brand viewer cannot upload)
        if (! $this->userMayUploadForActiveBrand($user, $tenant, $request, $uploadSession)) {
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
                'message' => 'Failed to update activity: '.$e->getMessage(),
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
     */
    public function markAsUploading(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if (! $user || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission — brand-scoped (brand viewer cannot upload)
        if (! $this->userMayUploadForActiveBrand($user, $tenant, $request, $uploadSession)) {
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
     */
    public function getMultipartPartUrl(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if (! $user || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission — brand-scoped (brand viewer cannot upload)
        if (! $this->userMayUploadForActiveBrand($user, $tenant, $request, $uploadSession)) {
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
                    'has_multipart_upload_id' => ! empty($uploadSession->multipart_upload_id),
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
     */
    public function initMultipart(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if (! $user || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission — brand-scoped (brand viewer cannot upload)
        if (! $this->userMayUploadForActiveBrand($user, $tenant, $request, $uploadSession)) {
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
     */
    public function signMultipartPart(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if (! $user || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission — brand-scoped (brand viewer cannot upload)
        if (! $this->userMayUploadForActiveBrand($user, $tenant, $request, $uploadSession)) {
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
     */
    public function completeMultipart(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if (! $user || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission — brand-scoped (brand viewer cannot upload)
        if (! $this->userMayUploadForActiveBrand($user, $tenant, $request, $uploadSession)) {
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
     */
    public function abortMultipart(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        // Phase 2.5 Step 2: Use normalized error response
        if (! $user || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        // Check upload permission — brand-scoped (brand viewer cannot upload)
        if (! $this->userMayUploadForActiveBrand($user, $tenant, $request, $uploadSession)) {
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
     */
    public function finalize(Request $request): JsonResponse
    {
        $this->finalizeS3Client = null;

        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // CRITICAL: Brand context must be available (bound by ResolveTenant middleware)
        // This ensures assets are created with the same brand_id used by UI queries
        if (! $brand) {
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
        if (! $user || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return UploadErrorResponse::error(
                UploadErrorResponse::CODE_PERMISSION_DENIED,
                'Unauthorized. Please check your account permissions.',
                403,
                []
            );
        }

        if (! $this->userMayUploadForActiveBrand($user, $tenant, $request)) {
            return response()->json([
                'message' => 'You do not have permission to upload assets.',
            ], 403);
        }

        // Validate request structure
        // Phase J.3.1: For replace mode, category_id and metadata are not required
        $validated = $request->validate([
            'manifest' => 'required|array|min:1',
            'manifest.*.upload_key' => 'required|string',
            'manifest.*.expected_size' => 'required|integer|min:1',
            'manifest.*.category_id' => 'nullable|integer|exists:categories,id', // Phase J.3.1: Optional for replace mode
            'manifest.*.category_slug' => 'nullable|string|max:100', // Fallback: resolve slug → category (auto-creates from system template if needed)
            'manifest.*.metadata' => 'nullable|array',
            'manifest.*.title' => 'nullable|string|max:255',
            'manifest.*.resolved_filename' => 'nullable|string|max:255',
            'manifest.*.comment' => 'nullable|string|max:1000', // Phase J.3.1: Optional comment for replace mode
            'manifest.*.collection_ids' => 'nullable|array', // C7: Attach asset to collections post-upload
            'manifest.*.collection_ids.*' => 'integer|exists:collections,id',
            // Client-generated UUID so the browser can map finalize results to local blob previews
            'manifest.*.client_file_id' => 'nullable|string|max:128',
            // C9.2: Upload-time AI skip controls (Admin/Brand Manager only)
            'skip_ai_tagging' => 'nullable|boolean',
            'skip_ai_metadata' => 'nullable|boolean',
        ]);

        $manifest = $validated['manifest'];
        // C9.2: Extract upload-time AI skip flags (upload-level, applies to all assets in this batch).
        // Non-privileged users cannot opt out via a forged request (matches UploadAssetDialog visibility).
        $canSetAiSkip = $this->userCanSetUploadAiSkipFlags($user, $tenant, $brand);
        $requestSkipAiTagging = $validated['skip_ai_tagging'] ?? null;
        $requestSkipAiMetadata = $validated['skip_ai_metadata'] ?? null;
        $skipAiTagging = $canSetAiSkip ? (bool) ($requestSkipAiTagging ?? false) : false;
        $skipAiMetadata = $canSetAiSkip ? (bool) ($requestSkipAiMetadata ?? false) : false;

        Log::info('[UploadController::finalize] AI skip flags resolved for finalize batch', [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'can_set_ai_skip' => $canSetAiSkip,
            'brand_role' => strtolower((string) ($user->getRoleForBrand($brand) ?? '')),
            'tenant_role' => strtolower((string) ($user->getRoleForTenant($tenant) ?? '')),
            'request_skip_ai_tagging' => $requestSkipAiTagging,
            'request_skip_ai_metadata' => $requestSkipAiMetadata,
            'effective_skip_ai_tagging' => $skipAiTagging,
            'effective_skip_ai_metadata' => $skipAiMetadata,
            'manifest_count' => count($manifest),
            'tenant_incubated_at' => $tenant->incubated_at?->toIso8601String(),
            'tenant_incubated_by_agency_id' => $tenant->incubated_by_agency_id,
        ]);

        $results = [];

        // Process each manifest item independently
        foreach ($manifest as $item) {
            $uploadKey = $item['upload_key'];
            $expectedSize = $item['expected_size'];
            $categoryId = $item['category_id'] ?? null; // Phase J.3.1: Optional for replace mode
            $clientFileId = isset($item['client_file_id']) ? trim((string) $item['client_file_id']) : '';
            $clientFileId = ($clientFileId !== '') ? $clientFileId : null;

            // C9.1: DEBUG - Log category_id extraction
            Log::info('[UploadController::finalize] Extracted category_id from manifest', [
                'upload_key' => $uploadKey,
                'category_id' => $categoryId,
                'has_category_id_key' => array_key_exists('category_id', $item),
                'item_keys' => array_keys($item),
            ]);
            // CRITICAL: Extract metadata - handle both array and object formats from JSON
            $metadata = $item['metadata'] ?? [];
            // If metadata is an object (stdClass from JSON), convert to array
            if (is_object($metadata)) {
                $metadata = (array) $metadata;
            }
            $title = $item['title'] ?? null;
            $resolvedFilename = $item['resolved_filename'] ?? null;
            $editorPublishDescription = null;
            $jackpotAiProvenance = null;

            $uploadSession = null; // Initialize for use in catch blocks

            try {
                // Extract upload_session_id from upload_key
                // Format: temp/uploads/{upload_session_id}/original
                if (! preg_match('#^temp/uploads/([^/]+)/original$#', $uploadKey, $matches)) {
                    throw new \RuntimeException("Invalid upload_key format: {$uploadKey}");
                }

                $uploadSessionId = $matches[1];

                // Find upload session (tenant-scoped)
                $uploadSession = UploadSession::where('id', $uploadSessionId)
                    ->where('tenant_id', $tenant->id)
                    ->first();

                if (! $uploadSession) {
                    throw new \RuntimeException("Upload session not found: {$uploadSessionId}");
                }

                // IDEMPOTENCY CHECK: Check if asset already exists for this upload_session_id
                // Uses upload_session_id only (unique constraint) - brand_id is determined by active brand context
                // This makes finalize safe under retries, refreshes, and race conditions
                // C9.1: Check idempotency FIRST to avoid unnecessary category validation for existing assets
                $existingAsset = Asset::where('upload_session_id', $uploadSessionId)
                    ->where('tenant_id', $tenant->id)
                    ->first();

                // Phase J.3.1: For replace mode, skip category validation and metadata persistence
                $isReplaceMode = $uploadSession->mode === 'replace' && $uploadSession->asset_id;
                // Brand Guidelines Builder: staged uploads (logo, photo refs, textures) don't require category
                $isBuilderStaged = ! empty($uploadSession->upload_options['builder_staged'] ?? null)
                    && ! empty($uploadSession->upload_options['builder_context'] ?? null);

                // CRITICAL: Verify category belongs to tenant and ACTIVE BRAND (not upload_session->brand_id)
                // The user is selecting a category in the UI, which is scoped to the active brand
                // Assets are created with the active brand_id to match UI queries
                // Phase J.3.1: Skip category validation for replace mode, builder staged, or existing assets
                $category = null;
                $categorySlug = $item['category_slug'] ?? null;
                if (! $isReplaceMode && ! $isBuilderStaged && ! $existingAsset) {
                    Log::info('[UploadController::finalize] Category validation check', [
                        'upload_key' => $uploadKey,
                        'category_id_provided' => $categoryId,
                        'category_slug_provided' => $categorySlug,
                        'is_replace_mode' => $isReplaceMode,
                        'existing_asset' => $existingAsset ? $existingAsset->id : null,
                        'brand_id' => $brand->id ?? null,
                        'tenant_id' => $tenant->id ?? null,
                    ]);

                    if ($categoryId) {
                        $category = Category::where('id', $categoryId)
                            ->where('tenant_id', $tenant->id)
                            ->where('brand_id', $brand->id)
                            ->first();
                    }

                    // Fallback: resolve by slug and auto-create from system template if needed
                    if (! $category && $categorySlug) {
                        $category = Category::where('tenant_id', $tenant->id)
                            ->where('brand_id', $brand->id)
                            ->where('slug', $categorySlug)
                            ->where('asset_type', \App\Enums\AssetType::ASSET)
                            ->first();

                        if (! $category) {
                            $template = \App\Models\SystemCategory::where('slug', $categorySlug)
                                ->where('asset_type', \App\Enums\AssetType::ASSET)
                                ->orderByDesc('version')
                                ->first();
                            if ($template) {
                                $systemCategoryService = app(\App\Services\SystemCategoryService::class);
                                $created = $systemCategoryService->addTemplateToBrand($brand, $template);
                                $category = $created ?? Category::where('tenant_id', $tenant->id)
                                    ->where('brand_id', $brand->id)
                                    ->where('slug', $categorySlug)
                                    ->where('asset_type', \App\Enums\AssetType::ASSET)
                                    ->first();
                            }
                        }
                    }

                    if (! $category) {
                        throw new \RuntimeException('Category ID is required for new asset uploads. Please select a category before finalizing.');
                    }
                }

                if ($existingAsset) {
                    // Asset already exists - return existing asset (idempotent)
                    // C9.1: Still process collection assignment even for existing assets
                    $asset = $existingAsset;

                    // C9.1: Sync asset to collections post-upload (collection_ids from manifest)
                    // Handles empty arrays (deselection) and returns clear errors on failure
                    $collectionIds = $item['collection_ids'] ?? [];
                    $collectionErrors = [];

                    // C9.1: DEBUG - Log collection assignment attempt (existing asset path)
                    Log::info('[UploadController::finalize] Collection assignment check (EXISTING ASSET PATH)', [
                        'upload_key' => $uploadKey,
                        'asset_id' => $asset->id ?? null,
                        'collection_ids_provided' => $collectionIds,
                        'has_collection_ids_key' => isset($item['collection_ids']),
                        'brand_id' => $brand->id ?? null,
                        'tenant_id' => $tenant->id ?? null,
                        'user_id' => $user->id ?? null,
                    ]);

                    // C9.1: Process collections if provided (empty array = deselect all, non-empty = sync)
                    // C9.1: Always process if collection_ids key exists (even if empty array)
                    if ($asset && $asset->id && array_key_exists('collection_ids', $item)) {
                        // Get current collections for this asset (brand-scoped)
                        $currentCollectionIds = $asset->collections()
                            ->where('tenant_id', $tenant->id)
                            ->where('brand_id', $brand->id)
                            ->pluck('collections.id')
                            ->toArray();

                        // Determine what to add and remove
                        $toAdd = array_diff($collectionIds, $currentCollectionIds);
                        $toRemove = array_diff($currentCollectionIds, $collectionIds);

                        // Process removals first
                        foreach ($toRemove as $collectionId) {
                            $targetCollection = Collection::query()
                                ->where('id', $collectionId)
                                ->where('tenant_id', $tenant->id)
                                ->where('brand_id', $brand->id)
                                ->first();

                            if (! $targetCollection) {
                                $collectionErrors[] = "Collection {$collectionId} not found or does not belong to this brand.";

                                continue;
                            }

                            if (! Gate::forUser($user)->allows('removeAsset', $targetCollection)) {
                                $collectionErrors[] = "You do not have permission to remove assets from collection: {$targetCollection->name}.";

                                continue;
                            }

                            try {
                                $this->collectionAssetService->detach($targetCollection, $asset);
                            } catch (\Throwable $e) {
                                $collectionErrors[] = "Failed to remove from collection {$targetCollection->name}: {$e->getMessage()}";
                                Log::warning('[UploadController::finalize] Failed to detach asset from collection', [
                                    'asset_id' => $asset->id,
                                    'collection_id' => $collectionId,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }

                        // D6.1: Asset eligibility (published, non-archived) is enforced here. Do not bypass this for collections or downloads.
                        if (! empty($toAdd) && ! $this->assetEligibilityService->isEligibleForCollections($asset)) {
                            return response()->json([
                                'message' => 'Some selected assets are not published and cannot be added to collections.',
                            ], 422);
                        }

                        // Process additions
                        foreach ($toAdd as $collectionId) {
                            // C9.1: DEBUG - Log each collection addition attempt
                            Log::info('[UploadController::finalize] Attempting to add collection', [
                                'asset_id' => $asset->id,
                                'collection_id' => $collectionId,
                                'brand_id' => $brand->id,
                                'tenant_id' => $tenant->id,
                                'user_id' => $user->id,
                            ]);

                            $targetCollection = Collection::query()
                                ->where('id', $collectionId)
                                ->where('tenant_id', $tenant->id)
                                ->where('brand_id', $brand->id)
                                ->first();

                            if (! $targetCollection) {
                                $errorMsg = "Collection {$collectionId} not found or does not belong to this brand.";
                                $collectionErrors[] = $errorMsg;
                                Log::warning('[UploadController::finalize] Collection not found', [
                                    'asset_id' => $asset->id,
                                    'collection_id' => $collectionId,
                                    'brand_id' => $brand->id,
                                    'tenant_id' => $tenant->id,
                                ]);

                                continue;
                            }

                            $canAdd = Gate::forUser($user)->allows('addAsset', $targetCollection);
                            Log::info('[UploadController::finalize] Permission check result', [
                                'asset_id' => $asset->id,
                                'collection_id' => $collectionId,
                                'collection_name' => $targetCollection->name,
                                'user_id' => $user->id,
                                'can_add' => $canAdd,
                            ]);

                            if (! $canAdd) {
                                $errorMsg = "You do not have permission to add assets to collection: {$targetCollection->name}.";
                                $collectionErrors[] = $errorMsg;
                                Log::warning('[UploadController::finalize] Permission denied for collection', [
                                    'asset_id' => $asset->id,
                                    'collection_id' => $collectionId,
                                    'collection_name' => $targetCollection->name,
                                    'user_id' => $user->id,
                                ]);

                                continue;
                            }

                            try {
                                $this->collectionAssetService->attach($targetCollection, $asset);
                                Log::info('[UploadController::finalize] Successfully attached collection', [
                                    'asset_id' => $asset->id,
                                    'collection_id' => $collectionId,
                                    'collection_name' => $targetCollection->name,
                                ]);
                            } catch (\Throwable $e) {
                                $collectionErrors[] = "Failed to add to collection {$targetCollection->name}: {$e->getMessage()}";
                                Log::warning('[UploadController::finalize] Failed to attach asset to collection', [
                                    'asset_id' => $asset->id,
                                    'collection_id' => $collectionId,
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString(),
                                ]);
                            }
                        }

                        // Log collection errors but don't fail entire finalize (asset is already created)
                        if (! empty($collectionErrors)) {
                            Log::warning('[UploadController::finalize] Collection assignment errors (existing asset)', [
                                'asset_id' => $asset->id,
                                'errors' => $collectionErrors,
                            ]);
                        }
                    }

                    // TASK 1: Check approval info for existing asset (UI-only, read-only)
                    $approvalRequired = false;
                    $pendingMetadataCount = 0;

                    $canApprove = $this->approvalResolver->canApprove($user, $tenant);
                    if (! $canApprove) {
                        $approvalEnabled = $this->approvalResolver->isApprovalEnabledForBrand($tenant, $brand);

                        if ($approvalEnabled) {
                            $metadataState = $this->metadataStateResolver->resolve($existingAsset);

                            $automaticFieldIds = \Illuminate\Support\Facades\DB::table('metadata_fields')
                                ->where('population_mode', 'automatic')
                                ->pluck('id')
                                ->toArray();

                            foreach ($metadataState as $fieldId => $state) {
                                if (! $state['has_pending']) {
                                    continue;
                                }

                                if (in_array($fieldId, $automaticFieldIds)) {
                                    continue;
                                }

                                $pendingRow = $state['pending'];
                                if ($pendingRow && in_array($pendingRow->source, ['ai', 'user'])) {
                                    $pendingMetadataCount++;
                                    $approvalRequired = true;
                                }
                            }
                        }
                    }

                    $successRow = [
                        'upload_key' => $uploadKey,
                        'status' => 'success',
                        'asset_id' => $existingAsset->id,
                        // TASK 1: Approval information for UI (read-only, no logic changes)
                        'approval_required' => $approvalRequired,
                        'pending_metadata_count' => $pendingMetadataCount,
                    ];
                    if ($clientFileId !== null) {
                        $successRow['client_file_id'] = $clientFileId;
                    }
                    $results[] = $successRow;

                    continue; // Skip to next manifest item
                }

                // Verify S3 object exists and size matches
                $this->verifyS3Upload($uploadSession, $uploadKey, $expectedSize);

                // Validate resolved_filename extension matches original file extension
                if ($resolvedFilename !== null) {
                    $bucket = $uploadSession->storageBucket;
                    if (! $bucket) {
                        throw new \RuntimeException('Storage bucket not found for upload session');
                    }

                    $s3Client = $this->getS3ClientForFinalize();

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

                // Phase J.3.1: For replace mode, skip category/asset type and metadata handling
                if ($isReplaceMode) {
                    // Replace mode: Pass comment via metadata array (hacky but works with existing complete method signature)
                    $comment = $item['comment'] ?? null;
                    $asset = $this->completionService->complete(
                        $uploadSession,
                        null, // assetType not needed for replace
                        $resolvedFilename, // filename
                        null, // title not needed for replace (keep existing)
                        $uploadKey, // s3Key
                        null, // categoryId not needed for replace (keep existing)
                        $comment ? ['comment' => $comment] : null, // Pass comment via metadata array
                        $user->id,
                        $skipAiTagging,
                        $skipAiMetadata
                    );
                } else {
                    // Create mode: Normal asset creation flow
                    // Builder-staged uploads: never use category (no category_id, no metadata schema)
                    if ($isBuilderStaged) {
                        $categoryId = null;
                    }
                    // Determine asset type from category (builder-staged: $category is null → use ASSET)
                    $assetType = ($category !== null) ? $category->asset_type->value : AssetType::ASSET->value;

                    // Phase 2 – Step 4: Extract and validate metadata fields BEFORE asset creation
                    // Frontend sends: { fieldKey: value } (only valid fields, no empty values)
                    $metadataFields = [];
                    // Handle both array and object formats
                    if (! empty($metadata)) {
                        // Convert object to array if needed
                        $metadataArray = is_object($metadata) ? (array) $metadata : $metadata;

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

                    // Reserved keys (EditorAssetBridge / internal) — never upload-schema fields
                    if (array_key_exists('editor_publish_description', $metadataFields)) {
                        $editorPublishDescription = $metadataFields['editor_publish_description'];
                        unset($metadataFields['editor_publish_description']);
                    }
                    if (array_key_exists('jackpot_ai_provenance', $metadataFields)) {
                        $jp = $metadataFields['jackpot_ai_provenance'];
                        unset($metadataFields['jackpot_ai_provenance']);
                        if (is_array($jp)) {
                            $jackpotAiProvenance = $jp;
                        } elseif (is_object($jp)) {
                            $jackpotAiProvenance = (array) $jp;
                        }
                    }

                    // Phase 2 – Step 4: Validate metadata against schema BEFORE asset creation
                    // Skip when no category (builder-staged uploads have no category)
                    if (! empty($metadataFields) && $category) {
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

                            // Drop keys not in upload schema (e.g. legacy editor composition: source, document, …)
                            $metadataFields = array_intersect_key($metadataFields, array_flip($allowedFieldKeys));

                            // Validate remaining keys (subset of allowlist)
                            $invalidKeys = array_diff(array_keys($metadataFields), $allowedFieldKeys);
                            if (! empty($invalidKeys)) {
                                throw new \InvalidArgumentException(
                                    'Invalid metadata fields: '.implode(', ', $invalidKeys).'. '.
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
                                'error' => 'Metadata validation failed: '.$e->getMessage(),
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
                        $user->id,
                        $skipAiTagging,
                        $skipAiMetadata
                    );

                    // Generative editor publish: description + IPTC-style AI provenance on asset JSON metadata.
                    if ($asset && $asset->id) {
                        $note = '';
                        if ($editorPublishDescription !== null && $editorPublishDescription !== '') {
                            $note = is_string($editorPublishDescription)
                                ? $editorPublishDescription
                                : (is_scalar($editorPublishDescription) ? (string) $editorPublishDescription : '');
                        }
                        $merge = [];
                        if ($note !== '') {
                            $merge['editor_publish_description'] = $note;
                        }
                        if (is_array($jackpotAiProvenance) && $jackpotAiProvenance !== []) {
                            $merge['jackpot_ai_provenance'] = $jackpotAiProvenance;
                        }
                        if ($merge !== []) {
                            $asset->refresh();
                            $currentMetadata = $asset->metadata ?? [];
                            if (! is_array($currentMetadata)) {
                                $currentMetadata = [];
                            }
                            $asset->update(['metadata' => array_merge($currentMetadata, $merge)]);
                        }
                    }

                    // IMPORTANT:
                    // Field metadata MUST be persisted exactly once during upload (below).
                    // UploadController::finalize() is the single source of truth for
                    // schema metadata so that approval logic is always enforced.
                    // AI skip flags (_skip_ai_*) are merged inside UploadCompletionService::complete()
                    // before AssetUploaded so ProcessAssetJob sees them.
                    // Phase 2 – Step 4: Persist metadata to asset_metadata table (after asset creation)
                    // UX-2: CRITICAL - Metadata persistence happens AFTER asset creation
                    // This ensures approval logic runs only after asset exists, not during upload
                    // Skip when no category (builder-staged uploads have no category)
                    if (! empty($metadataFields) && $category) {
                        try {
                            // UX-2: Assertion - Asset must exist before metadata persistence
                            // This guard ensures approval logic runs only after asset creation
                            if (! $asset || ! $asset->id) {
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

                    // C9.1: Sync asset to collections post-upload (collection_ids from manifest)
                    // Handles empty arrays (deselection) and returns clear errors on failure
                    $collectionIds = $item['collection_ids'] ?? [];
                    $collectionErrors = [];

                    // C9.1: DEBUG - Log collection assignment attempt (NEW ASSET PATH)
                    Log::info('[UploadController::finalize] Collection assignment check (NEW ASSET PATH)', [
                        'upload_key' => $uploadKey,
                        'asset_id' => $asset->id ?? null,
                        'collection_ids_provided' => $collectionIds,
                        'has_collection_ids_key' => isset($item['collection_ids']),
                        'brand_id' => $brand->id ?? null,
                        'tenant_id' => $tenant->id ?? null,
                        'user_id' => $user->id ?? null,
                    ]);

                    // C9.1: Process collections if provided (empty array = deselect all, non-empty = sync)
                    // C9.1: Always process if collection_ids key exists (even if empty array)
                    if ($asset && $asset->id && array_key_exists('collection_ids', $item)) {
                        // Get current collections for this asset (brand-scoped)
                        $currentCollectionIds = $asset->collections()
                            ->where('tenant_id', $tenant->id)
                            ->where('brand_id', $brand->id)
                            ->pluck('collections.id')
                            ->toArray();

                        // Determine what to add and remove
                        $toAdd = array_diff($collectionIds, $currentCollectionIds);
                        $toRemove = array_diff($currentCollectionIds, $collectionIds);

                        // C9.1: DEBUG - Log what will be added/removed (new asset)
                        Log::info('[UploadController::finalize] Collection sync calculation (NEW ASSET)', [
                            'asset_id' => $asset->id,
                            'requested_collection_ids' => $collectionIds,
                            'current_collection_ids' => $currentCollectionIds,
                            'to_add' => $toAdd,
                            'to_remove' => $toRemove,
                        ]);

                        // Process removals first
                        foreach ($toRemove as $collectionId) {
                            $targetCollection = Collection::query()
                                ->where('id', $collectionId)
                                ->where('tenant_id', $tenant->id)
                                ->where('brand_id', $brand->id)
                                ->first();

                            if (! $targetCollection) {
                                $collectionErrors[] = "Collection {$collectionId} not found or does not belong to this brand.";

                                continue;
                            }

                            if (! Gate::forUser($user)->allows('removeAsset', $targetCollection)) {
                                $collectionErrors[] = "You do not have permission to remove assets from collection: {$targetCollection->name}.";

                                continue;
                            }

                            try {
                                $this->collectionAssetService->detach($targetCollection, $asset);
                            } catch (\Throwable $e) {
                                $collectionErrors[] = "Failed to remove from collection {$targetCollection->name}: {$e->getMessage()}";
                                Log::warning('[UploadController::finalize] Failed to detach asset from collection', [
                                    'asset_id' => $asset->id,
                                    'collection_id' => $collectionId,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }

                        // D6.1: Asset eligibility (published, non-archived) is enforced here. Do not bypass this for collections or downloads.
                        if (! empty($toAdd) && ! $this->assetEligibilityService->isEligibleForCollections($asset)) {
                            return response()->json([
                                'message' => 'Some selected assets are not published and cannot be added to collections.',
                            ], 422);
                        }

                        // Process additions
                        foreach ($toAdd as $collectionId) {
                            // C9.1: DEBUG - Log each collection addition attempt
                            Log::info('[UploadController::finalize] Attempting to add collection', [
                                'asset_id' => $asset->id,
                                'collection_id' => $collectionId,
                                'brand_id' => $brand->id,
                                'tenant_id' => $tenant->id,
                                'user_id' => $user->id,
                            ]);

                            $targetCollection = Collection::query()
                                ->where('id', $collectionId)
                                ->where('tenant_id', $tenant->id)
                                ->where('brand_id', $brand->id)
                                ->first();

                            if (! $targetCollection) {
                                $errorMsg = "Collection {$collectionId} not found or does not belong to this brand.";
                                $collectionErrors[] = $errorMsg;
                                Log::warning('[UploadController::finalize] Collection not found', [
                                    'asset_id' => $asset->id,
                                    'collection_id' => $collectionId,
                                    'brand_id' => $brand->id,
                                    'tenant_id' => $tenant->id,
                                ]);

                                continue;
                            }

                            $canAdd = Gate::forUser($user)->allows('addAsset', $targetCollection);
                            Log::info('[UploadController::finalize] Permission check result', [
                                'asset_id' => $asset->id,
                                'collection_id' => $collectionId,
                                'collection_name' => $targetCollection->name,
                                'user_id' => $user->id,
                                'can_add' => $canAdd,
                            ]);

                            if (! $canAdd) {
                                $errorMsg = "You do not have permission to add assets to collection: {$targetCollection->name}.";
                                $collectionErrors[] = $errorMsg;
                                Log::warning('[UploadController::finalize] Permission denied for collection', [
                                    'asset_id' => $asset->id,
                                    'collection_id' => $collectionId,
                                    'collection_name' => $targetCollection->name,
                                    'user_id' => $user->id,
                                ]);

                                continue;
                            }

                            try {
                                $this->collectionAssetService->attach($targetCollection, $asset);
                                Log::info('[UploadController::finalize] Successfully attached collection', [
                                    'asset_id' => $asset->id,
                                    'collection_id' => $collectionId,
                                    'collection_name' => $targetCollection->name,
                                ]);
                            } catch (\Throwable $e) {
                                $collectionErrors[] = "Failed to add to collection {$targetCollection->name}: {$e->getMessage()}";
                                Log::warning('[UploadController::finalize] Failed to attach asset to collection', [
                                    'asset_id' => $asset->id,
                                    'collection_id' => $collectionId,
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString(),
                                ]);
                            }
                        }

                        // Log collection errors but don't fail entire finalize (asset is already created)
                        if (! empty($collectionErrors)) {
                            Log::warning('[UploadController::finalize] Collection assignment errors', [
                                'asset_id' => $asset->id,
                                'errors' => $collectionErrors,
                            ]);
                        }
                    }
                } // Phase J.3.1: Close else block for create mode

                // TASK 1: Check if metadata requires approval (UI-only, read-only information)
                // This does not modify approval logic or persistence - only exposes data for UI
                $approvalRequired = false;
                $pendingMetadataCount = 0;

                // Only check if approval is enabled and user is a contributor (not approver)
                $canApprove = $this->approvalResolver->canApprove($user, $tenant);
                if (! $canApprove) {
                    // Check if approval workflow is enabled for this brand
                    $approvalEnabled = $this->approvalResolver->isApprovalEnabledForBrand($tenant, $brand);

                    if ($approvalEnabled) {
                        // Resolve metadata state to count pending fields
                        $metadataState = $this->metadataStateResolver->resolve($asset);

                        // Count pending metadata fields (exclude automatic fields)
                        $automaticFieldIds = \Illuminate\Support\Facades\DB::table('metadata_fields')
                            ->where('population_mode', 'automatic')
                            ->pluck('id')
                            ->toArray();

                        foreach ($metadataState as $fieldId => $state) {
                            if (! $state['has_pending']) {
                                continue;
                            }

                            // Skip automatic fields (they don't require approval)
                            if (in_array($fieldId, $automaticFieldIds)) {
                                continue;
                            }

                            // Check if pending row is from user or AI (requires approval)
                            $pendingRow = $state['pending'];
                            if ($pendingRow && in_array($pendingRow->source, ['ai', 'user'])) {
                                $pendingMetadataCount++;
                                $approvalRequired = true;
                            }
                        }
                    }
                }

                // Success result
                $successRow = [
                    'upload_key' => $uploadKey,
                    'status' => 'success',
                    'asset_id' => $asset->id,
                    // TASK 1: Approval information for UI (read-only, no logic changes)
                    'approval_required' => $approvalRequired,
                    'pending_metadata_count' => $pendingMetadataCount,
                ];
                if ($clientFileId !== null) {
                    $successRow['client_file_id'] = $clientFileId;
                }
                $results[] = $successRow;

                // Note: Activity logging is handled by UploadCompletionService::complete()
                // which logs ASSET_UPLOAD_FINALIZED (the canonical event for processing start)
            } catch (CreatorModuleInactiveException $e) {
                $results[] = array_merge(
                    [
                        'upload_key' => $uploadKey,
                        'status' => 'failed',
                    ],
                    $e->clientPayload()
                );
            } catch (UploadContentRejectedException $e) {
                // Gate 3 rejection (real-content sniff at finalize). The temp S3
                // object has already been deleted and the UploadSession marked
                // failed by UploadCompletionService. We just surface a clean
                // structured error so the queue row shows the right message.
                UploadAuditLogger::warning([
                    'gate' => 'finalize_response',
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'brand_id' => $uploadSession->brand_id ?? null,
                    'upload_session_id' => $uploadSession->id ?? null,
                    'upload_key' => $uploadKey,
                    'code' => $e->errorCode,
                    'blocked_group' => $e->blockedGroup,
                    'declared_mime' => $e->declaredMime,
                    'detected_mime' => $e->detectedMime,
                    'extension' => $e->extension,
                ]);

                $errorRow = [
                    'upload_key' => $uploadKey,
                    'status' => 'failed',
                    'error' => [
                        'code' => UploadErrorResponse::CODE_VALIDATION_FAILED,
                        'message' => $e->getMessage(),
                        'error_code' => $e->errorCode,
                        'category' => UploadErrorResponse::getCategoryFromErrorCode(
                            UploadErrorResponse::CODE_VALIDATION_FAILED
                        ),
                        'context' => [
                            'upload_session_id' => $uploadSession?->id,
                            'pipeline_stage' => UploadErrorResponse::STAGE_FINALIZE,
                            'detected_mime' => $e->detectedMime,
                            'declared_mime' => $e->declaredMime,
                            'extension' => $e->extension,
                            'blocked_group' => $e->blockedGroup,
                        ],
                    ],
                ];
                if ($clientFileId !== null) {
                    $errorRow['client_file_id'] = $clientFileId;
                }
                $results[] = $errorRow;
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
                } elseif (str_contains($errorMessage, 'no longer exists') ||
                    str_contains($errorMessage, 'was deleted while') ||
                    str_contains($errorMessage, 'was removed while')) {
                    // Replace flow: target asset hard-deleted or soft-deleted mid-upload
                    $errorCode = UploadErrorResponse::CODE_PIPELINE_CONFLICT;
                } elseif (str_contains($errorMessage, 'not found') ||
                         str_contains($errorMessage, 'does not exist')) {
                    $errorCode = UploadErrorResponse::CODE_SESSION_NOT_FOUND;
                }

                $category = UploadErrorResponse::getCategoryFromErrorCode($errorCode);
                $isFileMissing = $errorCode === UploadErrorResponse::CODE_FILE_MISSING;

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
                if (! $isFileMissing && $uploadSession) {
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
                    'upload_session_id' => $uploadSession?->id,
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                    'trace' => $e->getTraceAsString(),
                ]);

                $errorCode = 'server_error';
                // Include actual error message for debugging (can be made more user-friendly later)
                $errorMessage = config('app.debug')
                    ? $e->getMessage()
                    : 'An unexpected error occurred';

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
     * @throws \RuntimeException If verification fails
     */
    protected function verifyS3Upload(UploadSession $uploadSession, string $uploadKey, int $expectedSize): void
    {
        $bucket = $uploadSession->storageBucket;
        if (! $bucket) {
            throw new \RuntimeException('Storage bucket not found for upload session');
        }

        // Create S3 client using reflection to access protected method
        // Or create our own instance using the same configuration
        $s3Client = $this->getS3ClientForFinalize();

        try {
            // Verify object exists in S3
            $exists = $s3Client->doesObjectExist($bucket->name, $uploadKey);

            if (! $exists) {
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
     * S3 client reused for all verify/head calls within a single finalize request.
     */
    protected function getS3ClientForFinalize(): S3Client
    {
        if ($this->finalizeS3Client instanceof S3Client) {
            return $this->finalizeS3Client;
        }

        $this->finalizeS3Client = $this->createS3ClientForFinalize();

        return $this->finalizeS3Client;
    }

    /**
     * Create S3 client instance for finalize verification.
     *
     * @throws \RuntimeException
     */
    protected function createS3ClientForFinalize(): S3Client
    {
        if (! class_exists(S3Client::class)) {
            throw new \RuntimeException(
                'AWS SDK for PHP is required for upload finalization. '.
                'Install it via: composer require aws/aws-sdk-php'
            );
        }

        $config = [
            'version' => 'latest',
            'region' => config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1')),
        ];

        // Endpoint for MinIO/local S3; credentials via SDK default chain
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
     */
    public function getMetadataSchema(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app()->bound('brand') ? app('brand') : null;
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found', 'message' => 'Tenant context is required.'], 404);
        }

        // Verify user belongs to tenant
        if (! $user || ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Unauthorized. Please check your account permissions.',
            ], 403);
        }

        $validated = $request->validate([
            'category_id' => 'required|integer|exists:categories,id',
            'asset_type' => 'nullable|string|in:image,video,document',
            'context' => 'nullable|string|in:upload,edit', // C9.2: edit = quick view / drawer visibility
        ]);

        // C9.2: Resolve category first; if brand is missing (e.g. fetch from drawer), resolve brand from category
        $category = Category::where('id', $validated['category_id'])
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $category) {
            return response()->json([
                'error' => 'Invalid category',
                'message' => 'Category not found or does not belong to this tenant.',
            ], 422);
        }

        if (! $brand) {
            $brand = $category->brand;
            if (! $brand) {
                return response()->json([
                    'error' => 'Brand not found',
                    'message' => 'Could not resolve brand for this category.',
                ], 422);
            }
        } else {
            if ($category->brand_id !== $brand->id) {
                return response()->json([
                    'error' => 'Invalid category',
                    'message' => 'Category must belong to this brand.',
                ], 422);
            }
        }

        // File kind for schema (image|video|document): upload forms MUST follow the folder’s type field applies_to
        // (Manage → Categories), not a client default. Edit/quick-view uses the asset’s file kind from the request.
        $context = $validated['context'] ?? 'upload';
        if ($context === 'edit') {
            $assetType = $validated['asset_type'] ?? 'image';
        } else {
            $assetType = CategoryTypeResolver::metadataSchemaAssetTypeForSlug((string) ($category->slug ?? ''));
        }

        try {
            $userRole = $user->getRoleForBrand($brand) ?? $user->getRoleForTenant($tenant) ?? 'member';

            // C9.2: When context=edit, return fields visible in quick view (drawer) so Collection shows when Quick View is checked
            if ($context === 'edit') {
                $schema = $this->uploadMetadataSchemaResolver->resolveForEdit(
                    $tenant->id,
                    $brand->id,
                    $category->id,
                    $assetType,
                    $userRole
                );
            } else {
                $schema = $this->uploadMetadataSchemaResolver->resolve(
                    $tenant->id,
                    $brand->id,
                    $category->id,
                    $assetType,
                    $userRole
                );
            }

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

    /**
     * GET /app/uploads/sessions/active
     *
     * Intended for restoring compact upload tray after refresh/navigation when durable async finalize
     * is enabled. Today Jackpot has one `upload_sessions` row per S3 upload (not a batch parent row);
     * batch orchestration tables/jobs are still to be added — this endpoint returns a stable contract
     * for the frontend to poll without error.
     */
    public function activeFinalizeSessions(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        if (! $user || ! $tenant || ! $brand) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! $this->userMayUploadForActiveBrand($user, $tenant, $request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $enabled = (bool) config('features.async_upload_finalize', false);

        return response()->json([
            'feature_enabled' => $enabled,
            'sessions' => [],
            'message' => $enabled
                ? 'No active finalize sessions (batch layer not yet wired).'
                : 'Async finalize disabled; tray uses in-tab state only.',
        ]);
    }

    /**
     * GET /app/uploads/sessions/{batchSessionId}/status
     *
     * Placeholder for per-batch progress, client_file_id → asset_id mappings, and failure summaries.
     */
    public function finalizeSessionStatus(Request $request, string $batchSessionId): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        if (! $user || ! $tenant || ! $brand) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! $this->userMayUploadForActiveBrand($user, $tenant, $request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $enabled = (bool) config('features.async_upload_finalize', false);

        return response()->json([
            'feature_enabled' => $enabled,
            'upload_session_id' => $batchSessionId,
            'status' => 'unknown',
            'message' => 'Batch finalize status API not implemented for this session id.',
            'items' => [],
            'client_file_id_to_asset_id' => new \stdClass,
        ]);
    }

    /**
     * Only Admin, Brand Manager, or tenant Owner/Admin may opt out of upload-time AI (matches UploadAssetDialog).
     */
    protected function userCanSetUploadAiSkipFlags(User $user, Tenant $tenant, Brand $brand): bool
    {
        $brandRole = strtolower((string) ($user->getRoleForBrand($brand) ?? ''));
        $tenantRole = strtolower((string) ($user->getRoleForTenant($tenant) ?? ''));

        if (in_array($brandRole, ['admin', 'brand_manager'], true)) {
            return true;
        }

        return in_array($tenantRole, ['owner', 'admin'], true);
    }
}
