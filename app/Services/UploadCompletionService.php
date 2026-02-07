<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Events\AssetProcessingCompleteEvent;
use App\Events\AssetUploaded;
use App\Jobs\ExtractMetadataJob;
use App\Models\Asset;
use App\Models\Category;
use App\Models\UploadSession;
use App\Services\MetadataPersistenceService;
use App\Services\UploadMetadataSchemaResolver;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Service for completing upload sessions and creating assets.
 * 
 * Phase 3 uploader — COMPLETE AND LOCKED
 * 
 * Persistence verified:
 * - Title normalization (never "Unknown", null if empty)
 * - Category ID stored in metadata->category_id
 * - Metadata fields stored in metadata->fields
 * - Extensive logging before/after save
 * - Guardrails prevent silent failures
 * 
 * Do not refactor further.
 *
 * TEMPORARY UPLOAD PATH CONTRACT (IMMUTABLE):
 *
 * All temporary upload objects must live at:
 *   temp/uploads/{upload_session_id}/original
 *
 * This path format is:
 *   - Deterministic: Same upload_session_id always produces the same path
 *   - Never reused: Each upload_session_id is a unique UUID, ensuring path uniqueness
 *   - Safe to delete independently: Temp uploads are separate from final asset storage
 *
 * Why this contract matters:
 *   - Cleanup: Can safely delete temp uploads without affecting assets
 *   - Resume: Can locate partial uploads deterministically for retry/resume
 *   - Isolation: Temporary uploads are clearly separated from permanent asset storage
 *   - Multi-service coordination: Different services can generate the same path independently
 *
 * The path MUST match UploadInitiationService::generateTempUploadPath() exactly.
 * This contract MUST be maintained across all services that interact with temporary uploads.
 */
class UploadCompletionService
{
    /**
     * Create a new instance.
     */
    public function __construct(
        protected ?S3Client $s3Client = null
    ) {
        $this->s3Client = $s3Client ?? $this->createS3Client();
    }

    /**
     * Complete an upload session and create an asset.
     *
     * This method is IDEMPOTENT - if an asset already exists for this upload session,
     * it returns the existing asset instead of creating a duplicate.
     *
     * This prevents duplicate assets from being created if /assets/upload/complete
     * is called multiple times (e.g., due to network retries or frontend issues).
     *
     * RULES:
     * - Do NOT modify Asset lifecycle or creation logic
     * - Do NOT create assets during resume
     * - Do NOT trust client state blindly
     * - UploadSession remains the source of truth
     *
     * @param UploadSession $uploadSession
     * @param string|null $assetType
     * @param string|null $filename Optional resolved filename (from client - derived from title + extension)
     * @param string|null $title Optional asset title (from client - human-facing, no extension)
     * @param string|null $s3Key Optional S3 key if known, otherwise will be determined
     * @param int|null $categoryId Optional category ID to store in metadata
     * @param array|null $metadata Optional metadata fields to store (will be merged with category_id)
     * @param int|null $userId Optional user ID who uploaded the asset
     * @return Asset
     * @throws \Exception
     */
    public function complete(
        UploadSession $uploadSession,
        ?string $assetType = null,
        ?string $filename = null,
        ?string $title = null,
        ?string $s3Key = null,
        ?int $categoryId = null,
        ?array $metadata = null,
        ?int $userId = null
    ): Asset {
        // NOTE:
        // Metadata persistence is intentionally NOT handled here.
        // UploadController::finalize() owns metadata writes to ensure
        // approval rules are consistently applied.
        
        // Refresh to get latest state
        $uploadSession->refresh();

        // Phase J.3.1: Handle replace mode (file-only replacement for rejected assets)
        if ($uploadSession->mode === 'replace' && $uploadSession->asset_id) {
            // For replace mode, comment is passed via metadata array (hacky but works with existing finalize flow)
            $comment = null;
            if (is_array($metadata) && isset($metadata['comment'])) {
                $comment = $metadata['comment'];
            }
            return $this->completeReplace($uploadSession, $s3Key, $userId, $comment);
        }

        // IDEMPOTENT CHECK: If upload session is already COMPLETED, check for existing asset
        if ($uploadSession->status === UploadStatus::COMPLETED) {
            $existingAsset = Asset::where('upload_session_id', $uploadSession->id)->first();
            
            if ($existingAsset) {
                Log::info('Upload completion called but asset already exists (idempotent)', [
                    'upload_session_id' => $uploadSession->id,
                    'asset_id' => $existingAsset->id,
                    'asset_status' => $existingAsset->status->value,
                ]);

                return $existingAsset;
            } else {
                // Status is COMPLETED but no asset found - this is a data inconsistency
                // Log warning but allow completion to proceed to fix the inconsistency
                Log::warning('Upload session marked as COMPLETED but no asset found - recreating asset', [
                    'upload_session_id' => $uploadSession->id,
                ]);

                // Reset status to allow completion (this should be rare)
                // Use force transition to fix data inconsistency
                $uploadSession->update(['status' => UploadStatus::UPLOADING]);
                $uploadSession->refresh();
            }
        }

        // Check for existing asset BEFORE attempting completion (race condition protection)
        // This handles the case where two requests complete simultaneously
        $existingAsset = Asset::where('upload_session_id', $uploadSession->id)->first();
        if ($existingAsset) {
            Log::info('Asset already exists for upload session (race condition detected)', [
                'upload_session_id' => $uploadSession->id,
                'asset_id' => $existingAsset->id,
            ]);

            // Update session status if not already COMPLETED
            if ($uploadSession->status !== UploadStatus::COMPLETED) {
                $uploadSession->update(['status' => UploadStatus::COMPLETED]);
            }

            return $existingAsset;
        }

        // Verify upload session can transition to COMPLETED (using guard method)
        if (!$uploadSession->canTransitionTo(UploadStatus::COMPLETED)) {
            throw new \RuntimeException(
                "Cannot transition upload session from {$uploadSession->status->value} to COMPLETED. " .
                "Upload session must be in INITIATING or UPLOADING status and not expired."
            );
        }

        // CRITICAL: For multipart (chunked) uploads, finalize the multipart upload in S3 BEFORE checking if object exists
        // For multipart uploads, all parts are uploaded but not assembled yet - the object doesn't exist until CompleteMultipartUpload is called
        if ($uploadSession->type === UploadType::CHUNKED && $uploadSession->multipart_upload_id) {
            Log::info('Finalizing multipart upload before completion', [
                'upload_session_id' => $uploadSession->id,
                'multipart_upload_id' => $uploadSession->multipart_upload_id,
            ]);
            
            try {
                $this->finalizeMultipartUpload($uploadSession);
            } catch (\Exception $e) {
                Log::error('Failed to finalize multipart upload', [
                    'upload_session_id' => $uploadSession->id,
                    'multipart_upload_id' => $uploadSession->multipart_upload_id,
                    'error' => $e->getMessage(),
                ]);
                
                throw new \RuntimeException(
                    "Failed to finalize multipart upload: {$e->getMessage()}",
                    0,
                    $e
                );
            }
        }

        // Get file info from S3 (never trust client metadata)
        // This is done outside transaction since it's an external operation
        // For multipart uploads, the object should now exist after finalization
        $fileInfo = $this->getFileInfoFromS3($uploadSession, $s3Key, $filename);

        // Use provided filename (resolvedFilename from frontend) or fall back to original filename from S3
        // Frontend derives resolvedFilename from title + extension, so this respects user's title
        $finalFilename = $filename ?? $fileInfo['original_filename'];
        
        // Final validation: Ensure resolved filename extension matches original file extension
        if ($filename !== null && $fileInfo['original_filename'] !== null) {
            $originalExt = strtolower(pathinfo($fileInfo['original_filename'], PATHINFO_EXTENSION));
            $resolvedExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if ($originalExt !== $resolvedExt) {
                Log::error('Resolved filename extension mismatch detected in completion service', [
                    'upload_session_id' => $uploadSession->id,
                    'original_filename' => $fileInfo['original_filename'],
                    'resolved_filename' => $filename,
                    'original_ext' => $originalExt,
                    'resolved_ext' => $resolvedExt,
                ]);
                
                throw new \RuntimeException(
                    "Resolved filename extension mismatch. Original file has extension '{$originalExt}', but resolved filename has '{$resolvedExt}'. File extensions cannot be changed."
                );
            }
        }

        // Enforce title normalization: Never save "Unknown" or "Untitled Asset", empty → null, derive from filename if missing
        $title = trim((string) ($title ?? ''));
        
        if ($title === '' || in_array($title, ['Unknown', 'Untitled Asset'], true)) {
            // Derive from filename without extension
            if ($finalFilename) {
                $pathInfo = pathinfo($finalFilename);
                $title = $pathInfo['filename'] ?? $finalFilename;
                // If still empty or "Unknown", set to null
                if (empty($title) || $title === 'Unknown') {
                    $title = null;
                } else {
                    // Normalize derived title
                    $title = trim($title);
                    if ($title === '' || $title === 'Unknown' || $title === 'Untitled Asset') {
                        $title = null;
                    }
                }
            } else {
                $title = null;
            }
        }
        
        // If title is still empty string after normalization, convert to null
        if ($title === '') {
            $title = null;
        }
        
        // Use normalized title (may be null)
        $derivedTitle = $title;
        
        // Build metadata object: category_id at top level, fields nested
        // Structure: { category_id: 123, fields: { photographer: "John", location: "NYC" } }
        $metadataArray = [];
        
        // Add category_id if provided (always at top level)
        if ($categoryId !== null) {
            $metadataArray['category_id'] = $categoryId;
        }
        
        // Merge provided metadata fields into metadata object
        if (is_array($metadata) && !empty($metadata)) {
            // Frontend sends metadata as { fields: {...} } to separate fields from category_id
            if (isset($metadata['fields']) && is_array($metadata['fields']) && !empty($metadata['fields'])) {
                $metadataArray['fields'] = $metadata['fields'];
            } elseif (!isset($metadata['category_id'])) {
                // If no 'fields' key and no category_id, treat entire array as fields
                // (Backward compatibility: metadata could be sent as flat object of fields)
                $metadataArray['fields'] = $metadata;
            } else {
                // Metadata has category_id mixed in - extract fields only
                $fields = $metadata;
                unset($fields['category_id']);
                if (!empty($fields)) {
                    $metadataArray['fields'] = $fields;
                }
            }
        }

        // Generate initial storage path for asset (temp path, will be promoted later)
        // Assets are initially stored in temp/ during upload, then promoted to canonical location
        $storagePath = $this->generateTempUploadPath($uploadSession);

        // Derive asset type from category if categoryId is provided, otherwise use provided assetType or default to ASSET
        // This ensures type always matches the category's asset_type
        if ($categoryId !== null) {
            // Look up category to get its asset_type
            $category = Category::find($categoryId);
            if ($category) {
                $assetTypeEnum = $category->asset_type; // Use category's asset_type enum directly
            } else {
                // Category not found - fall back to provided assetType or default
                $assetTypeEnum = $assetType ? AssetType::from($assetType) : AssetType::ASSET;
            }
        } else {
            // No categoryId provided - use provided assetType or default to ASSET
            $assetTypeEnum = $assetType ? AssetType::from($assetType) : AssetType::ASSET;
        }

        // Get active brand ID from context (set by ResolveTenant middleware)
        // CRITICAL: Brand context MUST be available - fail loudly if missing
        // The finalize route is inside the 'tenant' middleware group which binds the brand
        // Silent fallback to uploadSession->brand_id is incorrect and causes brand mismatch bugs
        if (!app()->bound('brand')) {
            Log::error('[UploadCompletionService] Brand context not bound - ResolveTenant middleware may not have run', [
                'upload_session_id' => $uploadSession->id,
                'tenant_id' => $uploadSession->tenant_id,
                'session_brand_id' => $uploadSession->brand_id,
                'note' => 'Brand context must be bound by ResolveTenant middleware. Ensure finalize route is in tenant middleware group.',
            ]);
            throw new \RuntimeException(
                'Brand context is not available. The finalize endpoint must be accessed through the tenant middleware which binds the active brand. ' .
                'This prevents assets from being created with incorrect brand_id values.'
            );
        }
        
        $activeBrand = app('brand');
        $targetBrandId = $activeBrand->id;
        
        // GUARD: Log warning if session brand_id differs from active brand_id
        // This helps identify when upload_session was created with a different brand than the current UI brand
        if ($uploadSession->brand_id !== $targetBrandId) {
            Log::warning('[UploadCompletionService] Brand mismatch detected - using active brand instead of session brand', [
                'upload_session_id' => $uploadSession->id,
                'session_brand_id' => $uploadSession->brand_id,
                'active_brand_id' => $targetBrandId,
                'tenant_id' => $uploadSession->tenant_id,
                'note' => 'Asset will be created under active brand context, not the brand used during upload initiation',
            ]);
        }
        
        // Wrap asset creation and status update in transaction for atomicity
        // This ensures that if asset creation fails, status doesn't change
        // The unique constraint on upload_session_id prevents duplicate assets at DB level
        return DB::transaction(function () use ($uploadSession, $fileInfo, $assetTypeEnum, $storagePath, $derivedTitle, $finalFilename, $filename, $metadataArray, $categoryId, $userId, $targetBrandId) {
            // Double-check for existing asset inside transaction (final race condition check)
            $existingAsset = Asset::where('upload_session_id', $uploadSession->id)->lockForUpdate()->first();
            if ($existingAsset) {
                Log::info('Asset already exists for upload session (race condition detected in transaction)', [
                    'upload_session_id' => $uploadSession->id,
                    'asset_id' => $existingAsset->id,
                ]);

                // Update session status if not already COMPLETED
                if ($uploadSession->status !== UploadStatus::COMPLETED) {
                    $uploadSession->update(['status' => UploadStatus::COMPLETED]);
                }

                return $existingAsset;
            }

            // Create asset - unique constraint prevents duplicates
            try {

                // Use resolvedFilename from frontend if provided, otherwise fall back to S3 extracted filename
                // This prevents "unknown" from appearing when S3 metadata doesn't contain filename
                $finalOriginalFilename = $finalFilename ?? $fileInfo['original_filename'];
                
                // Ensure we never save "unknown" as original_filename
                if ($finalOriginalFilename === 'unknown' && $filename) {
                    $finalOriginalFilename = $filename;
                }
                
                // Phase 3.1E: Determine initial thumbnail_status
                // ALL newly created image assets must start with thumbnail_status = 'pending'
                // Even if thumbnail jobs are dispatched immediately, status must be 'pending'
                // 'completed' may ONLY be set by GenerateThumbnailsJob AFTER file existence is verified
                // This prevents UI from rendering thumbnails before files exist
                // WHY: Prevents UI from skipping processing/icon states and showing green blocks
                // Ensures new uploads behave the same as existing assets
                $mimeType = $fileInfo['mime_type'] ?? '';
                $isImageFile = str_starts_with($mimeType, 'image/') && 
                               !in_array(strtolower($mimeType), ['image/avif']); // AVIF excluded (not supported yet)
                $initialThumbnailStatus = $isImageFile ? \App\Enums\ThumbnailStatus::PENDING : null;
                
                // Phase AF-1: Check if user requires approval for this brand
                // Default to not_required, will be updated after creation if needed
                $initialApprovalStatus = \App\Enums\ApprovalStatus::NOT_REQUIRED;
                
                $asset = Asset::create([
                    'tenant_id' => $uploadSession->tenant_id,
                    'brand_id' => $targetBrandId,
                    'user_id' => $userId, // User who uploaded the asset
                    'upload_session_id' => $uploadSession->id, // Unique constraint prevents duplicates
                    'storage_bucket_id' => $uploadSession->storage_bucket_id,
                    'status' => AssetStatus::VISIBLE, // Initial visibility state after upload completion
                    'type' => $assetTypeEnum,
                    'title' => $derivedTitle, // Persist human-facing title (never "Unknown", null if empty)
                    'original_filename' => $finalOriginalFilename, // Use resolvedFilename from frontend, fallback to S3 filename
                    'mime_type' => $fileInfo['mime_type'],
                    'size_bytes' => $fileInfo['size_bytes'],
                    'storage_root_path' => $storagePath, // Uses finalFilename (resolvedFilename from frontend if provided)
                    'metadata' => $metadataArray, // JSON object with category_id and fields
                    // Phase 3.1E: Explicitly set thumbnail_status = PENDING for image assets
                    // Prevents false "completed" states that cause UI to skip processing/icon states
                    // Ensures new uploads behave the same as existing assets
                    'thumbnail_status' => $initialThumbnailStatus,
                    // Phase AF-1: Set initial approval_status (will be updated after creation if user requires approval)
                    'approval_status' => $initialApprovalStatus,
                ]);
                
            } catch (\Illuminate\Database\QueryException $e) {
                // Handle unique constraint violation (duplicate asset detected)
                // Laravel/PDO throws code 23000 for unique constraint violations
                // Also check error message for additional safety
                $errorCode = $e->getCode();
                $errorMessage = $e->getMessage();
                
                if ($errorCode === 23000 || 
                    $errorCode === '23000' || 
                    str_contains($errorMessage, 'upload_session_id') ||
                    str_contains($errorMessage, 'UNIQUE constraint') ||
                    str_contains($errorMessage, 'Duplicate entry')) {
                    
                    Log::info('Duplicate asset creation prevented by unique constraint (race condition)', [
                        'upload_session_id' => $uploadSession->id,
                        'error_code' => $errorCode,
                        'error_message' => $errorMessage,
                    ]);

                    // Fetch the existing asset that was created by the other request
                    $asset = Asset::where('upload_session_id', $uploadSession->id)->firstOrFail();
                    // Refresh to get latest state
                    $asset->refresh();
                } else {
                    // Re-throw if it's a different error
                    Log::error('Unexpected database error during asset creation', [
                        'upload_session_id' => $uploadSession->id,
                        'error_code' => $errorCode,
                        'error_message' => $errorMessage,
                    ]);
                    throw $e;
                }
            }
            
            // Refresh asset to get latest state
            $asset->refresh();
            
            // 🚨 GUARDRAIL: Verify category_id persisted if it was provided
            // Note: In race condition (duplicate asset), the other request may have different metadata,
            // so we log but don't throw - the asset was already created successfully
            if ($categoryId !== null) {
                $persistedMetadata = $asset->metadata ?? [];
                if (empty($persistedMetadata['category_id']) || $persistedMetadata['category_id'] !== $categoryId) {
                    Log::warning('[UploadCompletionService] Category ID mismatch detected', [
                        'asset_id' => $asset->id,
                        'expected_category_id' => $categoryId,
                        'persisted_category_id' => $persistedMetadata['category_id'] ?? null,
                        'metadata' => $persistedMetadata,
                        'note' => 'This may occur in race conditions where another request created the asset first',
                    ]);
                    // Only throw if this is a new asset (not a duplicate from race condition)
                    // We can detect this by checking if the asset was just created (created_at is recent)
                    $assetAge = time() - strtotime($asset->created_at);
                    if ($assetAge < 5) { // Asset was created within last 5 seconds
                        throw new \RuntimeException(
                            "Asset category_id failed to persist. Expected: {$categoryId}, Got: " . 
                            ($persistedMetadata['category_id'] ?? 'null')
                        );
                    }
                }
            }

            // Phase L.5.1: Category-based approval rules
            if ($categoryId !== null && $userId !== null) {
                $category = Category::find($categoryId);
                $user = \App\Models\User::find($userId);
                
                if ($category && $category->requiresApproval()) {
                    // Category requires approval: Keep unpublished, set status to HIDDEN, fire event
                    $asset->published_at = null;
                    $asset->published_by_id = null;
                    $asset->status = AssetStatus::HIDDEN;
                    $asset->save();
                    
                    // Fire ASSET_PENDING_APPROVAL event
                    try {
                        \App\Services\ActivityRecorder::record(
                            tenant: $asset->tenant,
                            eventType: \App\Enums\EventType::ASSET_PENDING_APPROVAL,
                            subject: $asset,
                            actor: $user,
                            brand: $asset->brand,
                            metadata: [
                                'category_id' => $categoryId,
                                'category_name' => $category->name,
                            ]
                        );
                        
                        // Phase L.6.3: Dispatch event for email notifications
                        \App\Events\AssetPendingApproval::dispatch($asset, $user, $category->name);
                    } catch (\Exception $e) {
                        // Activity logging must never break processing
                        Log::error('Failed to log asset pending approval event', [
                            'asset_id' => $asset->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    
                    Log::info('[UploadCompletionService] Asset requires approval (category requires_approval=true)', [
                        'asset_id' => $asset->id,
                        'category_id' => $categoryId,
                        'category_name' => $category->name,
                        'status' => AssetStatus::HIDDEN->value,
                    ]);
                } elseif ($category && !$category->requiresApproval()) {
                    // Auto-publish: Category does not require approval
                    $asset->published_at = now();
                    $asset->published_by_id = $userId; // Use uploader as published_by
                    // Status remains VISIBLE (set during asset creation)
                    $asset->save();
                    
                    Log::info('[UploadCompletionService] Asset auto-published (category does not require approval)', [
                        'asset_id' => $asset->id,
                        'category_id' => $categoryId,
                        'category_name' => $category->name,
                        'published_by_id' => $userId,
                    ]);
                } else {
                    // Category not found - auto-publish if user ID is available (same as assets)
                    // Deliverables should be published by default, just like assets
                    if ($userId !== null) {
                        $asset->published_at = now();
                        $asset->published_by_id = $userId;
                        $asset->save();
                        
                        Log::info('[UploadCompletionService] Asset auto-published (category not found, defaulting to published)', [
                            'asset_id' => $asset->id,
                            'category_id' => $categoryId,
                            'published_by_id' => $userId,
                        ]);
                    } else {
                        Log::warning('[UploadCompletionService] Category not found and no user ID for approval check', [
                            'asset_id' => $asset->id,
                            'category_id' => $categoryId,
                        ]);
                    }
                }
            } else {
                // No category or user ID - auto-publish if user ID is available (same as assets)
                // Deliverables should be published by default, just like assets
                if ($userId !== null) {
                    $asset->published_at = now();
                    $asset->published_by_id = $userId;
                    $asset->save();
                    
                    Log::info('[UploadCompletionService] Asset auto-published (no category, defaulting to published)', [
                        'asset_id' => $asset->id,
                        'category_id' => $categoryId,
                        'user_id' => $userId,
                        'published_by_id' => $userId,
                    ]);
                } else {
                    Log::info('[UploadCompletionService] Asset not auto-published (no category or user ID)', [
                        'asset_id' => $asset->id,
                        'category_id' => $categoryId,
                        'user_id' => $userId,
                    ]);
                }
            }

            // Phase AF-1: Check brand_user.requires_approval flag
            // Phase MI-1: Only check active membership (removed_at IS NULL)
            // Phase AF-5: Gate approval workflow based on plan feature
            // This is separate from category-based approval (which is locked phase)
            // Approval is required ONLY if uploader.brand_user.requires_approval === true AND approvals.enabled = true
            // CRITICAL: Brand/user approval check runs AFTER category check, so it can override category publish decision
            // If category already published, brand/user approval can still unpublish if approval is required
            if ($userId !== null && $targetBrandId !== null) {
                $user = \App\Models\User::find($userId);
                $brand = \App\Models\Brand::find($targetBrandId);
                $tenant = $brand?->tenant;
                
                // Phase AF-5: Check if approvals are enabled for tenant plan
                $featureGate = app(\App\Services\FeatureGate::class);
                $approvalsEnabled = $tenant && $featureGate->approvalsEnabled($tenant);
                
                // Phase MI-1: Use activeBrandMembership to get requires_approval flag
                $membership = $user ? $user->activeBrandMembership($brand) : null;
                $userRequiresApproval = $approvalsEnabled && $membership && ($membership['requires_approval'] ?? false);
                
                // Phase J.3.1: Check company capability toggle first, then brand-level contributor approval setting
                // Company toggle must be enabled for brand toggle to take effect
                $companyAllowsContributorApproval = $tenant && ($tenant->settings['features']['contributor_asset_approval'] ?? false);
                $isContributor = $membership && ($membership['role'] ?? null) === 'contributor';
                $brandRequiresContributorApproval = $brand && $brand->requiresContributorApproval();
                // Only enforce brand-level setting if company capability is enabled
                $contributorRequiresApproval = $approvalsEnabled && $companyAllowsContributorApproval && $isContributor && $brandRequiresContributorApproval;
                
                // Approval required if either user-level flag OR brand-level contributor setting requires it
                $requiresApproval = $userRequiresApproval || $contributorRequiresApproval;
                
                if ($requiresApproval) {
                    // User requires approval - set approval_status to pending
                    $asset->approval_status = \App\Enums\ApprovalStatus::PENDING;
                    // Asset remains unpublished until approved (even if category check published it)
                    // Brand/user approval takes precedence over category auto-publish
                    $asset->published_at = null;
                    $asset->published_by_id = null;
                    // Status remains VISIBLE (approval doesn't change visibility status, just approval_status)
                    $asset->save();
                    
                    // Phase AF-2: Record submitted action
                    $commentService = app(\App\Services\AssetApprovalCommentService::class);
                    $commentService->recordSubmitted($asset, $user);
                    
                    // Phase AF-3: Notify approvers
                    $notificationService = app(\App\Services\ApprovalNotificationService::class);
                    $notificationService->notifyOnSubmitted($asset, $user);
                    
                    $approvalReason = $contributorRequiresApproval ? 'brand.contributor_upload_requires_approval' : 'brand_user.requires_approval';
                    Log::info('[UploadCompletionService] Asset requires approval', [
                        'asset_id' => $asset->id,
                        'user_id' => $userId,
                        'brand_id' => $targetBrandId,
                        'approval_status' => 'pending',
                        'approval_reason' => $approvalReason,
                        'is_contributor' => $isContributor,
                        'brand_requires_contributor_approval' => $brandRequiresContributorApproval,
                        'asset_type' => $asset->type->value,
                    ]);
                } else {
                    // Approval not required - publish if asset is still unpublished
                    // This ensures deliverables (and assets) are published by default when approval is not required
                    // This matches the same workflow as assets and handles cases where category check didn't publish
                    // Only publish if category doesn't require approval (check category first)
                    // CRITICAL: If category check already published the asset, don't republish (idempotent)
                    // If category check didn't publish (category not found, no categoryId, etc.), then publish here
                    if (!$asset->isPublished()) {
                        // Check if category requires approval (only if categoryId was provided)
                        $categoryRequiresApproval = false;
                        if ($categoryId !== null) {
                            // Re-fetch category to check approval requirement
                            // Note: Category was already fetched in category check block, but we need to check again
                            // in case categoryId was null in that block
                            $category = Category::find($categoryId);
                            if ($category) {
                                $categoryRequiresApproval = $category->requiresApproval();
                            }
                        }
                        
                        // Only auto-publish if category doesn't require approval
                        // If category requires approval, it was already set to unpublished above and should stay that way
                        if (!$categoryRequiresApproval) {
                            $asset->published_at = now();
                            $asset->published_by_id = $userId;
                            $asset->save();
                            
                            Log::info('[UploadCompletionService] Asset auto-published (approval not required, category does not require approval)', [
                                'asset_id' => $asset->id,
                                'user_id' => $userId,
                                'brand_id' => $targetBrandId,
                                'asset_type' => $asset->type->value,
                                'category_id' => $categoryId,
                                'published_by_id' => $userId,
                            ]);
                        } else {
                            Log::info('[UploadCompletionService] Asset not auto-published (category requires approval)', [
                                'asset_id' => $asset->id,
                                'category_id' => $categoryId,
                                'asset_type' => $asset->type->value,
                            ]);
                        }
                    } else {
                        // Asset already published (likely by category check) - no action needed
                        Log::debug('[UploadCompletionService] Asset already published, skipping brand/user approval check publish', [
                            'asset_id' => $asset->id,
                            'published_at' => $asset->published_at?->toIso8601String(),
                            'asset_type' => $asset->type->value,
                        ]);
                    }
                }
                // If not required, approval_status already set to NOT_REQUIRED during creation
            }

            // Update upload session status and uploaded size (transition already validated above)
            // This is inside the transaction so status only updates if asset creation succeeds
            $uploadSession->update([
                'status' => UploadStatus::COMPLETED,
                'uploaded_size' => $fileInfo['size_bytes'],
            ]);

            Log::info('Asset created from upload session', [
                'asset_id' => $asset->id,
                'upload_session_id' => $uploadSession->id,
                'tenant_id' => $uploadSession->tenant_id,
                'title' => $asset->title,
                'original_filename' => $asset->original_filename,
                'size_bytes' => $asset->size_bytes,
            ]);

            // Persist upload metadata fields to asset_metadata table
            // This ensures metadata entered during upload appears in the display UI
            // CRITICAL: Metadata persistence must succeed - this is user-entered data during upload
            if (isset($metadataArray['fields']) && !empty($metadataArray['fields'])) {
                if ($categoryId === null) {
                    // Log warning - metadata persistence requires a category
                    Log::warning('[UploadCompletionService] Metadata fields provided but category_id is null', [
                        'asset_id' => $asset->id,
                        'fields' => array_keys($metadataArray['fields']),
                        'note' => 'Metadata persistence requires a category. Fields will be stored in JSON only.',
                    ]);
                } else {
                    try {
                        $category = Category::find($categoryId);
                        if (!$category) {
                            Log::error('[UploadCompletionService] Category not found for metadata persistence', [
                                'asset_id' => $asset->id,
                                'category_id' => $categoryId,
                                'fields' => array_keys($metadataArray['fields']),
                            ]);
                        } else {
                            // Pre-validate: Check if fields are in the upload schema before attempting persistence
                            // This prevents silent failures and provides better error messages
                            $uploadSchemaResolver = app(UploadMetadataSchemaResolver::class);
                            // Get user role for permission checks (same as during upload)
                            $user = $userId ? \App\Models\User::find($userId) : null;
                            $tenant = \App\Models\Tenant::find($asset->tenant_id);
                            $brand = \App\Models\Brand::find($asset->brand_id);
                            $userRole = $user && $brand ? ($user->getRoleForBrand($brand) ?? ($user && $tenant ? $user->getRoleForTenant($tenant) : null) ?? 'member') : 'member';
                            $schema = $uploadSchemaResolver->resolve(
                                $asset->tenant_id,
                                $asset->brand_id,
                                $category->id,
                                'image', // Default to 'image' for metadata schema resolution
                                $userRole // Pass user role for permission checks
                            );
                            
                            // Build allowlist of valid field keys and map to field IDs
                            $allowedFieldKeys = [];
                            $fieldKeyToIdMap = [];
                            foreach ($schema['groups'] ?? [] as $group) {
                                foreach ($group['fields'] ?? [] as $field) {
                                    $allowedFieldKeys[] = $field['key'];
                                    $fieldKeyToIdMap[$field['key']] = $field['field_id'];
                                }
                            }
                            
                            // Filter out invalid fields and log warnings
                            $invalidFields = array_diff(array_keys($metadataArray['fields']), $allowedFieldKeys);
                            if (!empty($invalidFields)) {
                                Log::warning('[UploadCompletionService] Some metadata fields are not in upload schema', [
                                    'asset_id' => $asset->id,
                                    'category_id' => $categoryId,
                                    'invalid_fields' => $invalidFields,
                                    'allowed_fields' => $allowedFieldKeys,
                                    'note' => 'These fields will be skipped during persistence but stored in JSON.',
                                ]);
                            }
                            
                            // Only persist valid fields
                            $validFields = array_intersect_key($metadataArray['fields'], array_flip($allowedFieldKeys));
                            
                            if (!empty($validFields)) {
                                $persistenceService = app(MetadataPersistenceService::class);
                                $persistenceService->persistMetadata(
                                    $asset,
                                    $category,
                                    $validFields,
                                    $userId ?? 0,
                                    'image', // Default to 'image' for metadata schema resolution
                                    true // Auto-approve upload-time metadata (user explicitly set it during upload)
                                );
                                // CRITICAL: Verify metadata was actually persisted
                                $expectedFieldIds = array_map(function($key) use ($fieldKeyToIdMap) {
                                    return $fieldKeyToIdMap[$key] ?? null;
                                }, array_keys($validFields));
                                $expectedFieldIds = array_filter($expectedFieldIds); // Remove nulls
                                
                                $persistedCount = DB::table('asset_metadata')
                                    ->where('asset_id', $asset->id)
                                    ->whereIn('metadata_field_id', $expectedFieldIds)
                                    ->whereNotNull('approved_at') // Must be approved (auto-approved for upload)
                                    ->count();
                                
                                if ($persistedCount < count($validFields)) {
                                    Log::error('[UploadCompletionService] CRITICAL: Metadata persistence verification failed', [
                                        'asset_id' => $asset->id,
                                        'expected_count' => count($validFields),
                                        'actual_count' => $persistedCount,
                                        'field_keys' => array_keys($validFields),
                                        'field_ids' => $expectedFieldIds,
                                    ]);
                                    throw new \RuntimeException(
                                        "CRITICAL: Metadata persistence verification failed. Expected " . count($validFields) . " rows, got {$persistedCount}. " .
                                        "User-entered metadata may not have been persisted correctly."
                                    );
                                }
                                
                                Log::info('[UploadCompletionService] Upload metadata persisted to asset_metadata table', [
                                    'asset_id' => $asset->id,
                                    'category_id' => $categoryId,
                                    'fields_persisted' => count($validFields),
                                    'fields_skipped' => count($invalidFields),
                                    'field_keys' => array_keys($validFields),
                                    'verification_passed' => true,
                                ]);
                            } else {
                                // CRITICAL: If fields were provided but none were valid, this is a failure
                                // This should never happen in normal operation - user selected fields that should be valid
                                Log::error('[UploadCompletionService] CRITICAL: All metadata fields were filtered out', [
                                    'asset_id' => $asset->id,
                                    'category_id' => $categoryId,
                                    'fields_provided' => array_keys($metadataArray['fields']),
                                    'allowed_fields' => $allowedFieldKeys,
                                    'note' => 'All provided fields were filtered out. This indicates a schema/visibility mismatch. User-entered data was lost.',
                                ]);
                                
                                // Throw exception to surface this critical failure
                                throw new \RuntimeException(
                                    'CRITICAL: All metadata fields were filtered out during persistence. ' .
                                    'Fields provided: ' . implode(', ', array_keys($metadataArray['fields'])) . '. ' .
                                    'Allowed fields: ' . implode(', ', $allowedFieldKeys) . '. ' .
                                    'This is a critical failure - user-entered metadata was not persisted.'
                                );
                            }
                        }
                    } catch (\RuntimeException $e) {
                        // Re-throw RuntimeExceptions (our critical failures)
                        throw $e;
                    } catch (\Exception $e) {
                        // Log error with full context - this is a critical failure
                        Log::error('[UploadCompletionService] CRITICAL: Failed to persist upload metadata', [
                            'asset_id' => $asset->id,
                            'category_id' => $categoryId,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                            'fields_provided' => array_keys($metadataArray['fields']),
                            'note' => 'User-entered metadata was not persisted. This is a critical failure that must be investigated.',
                        ]);
                        
                        // Throw exception to surface this critical failure
                        throw new \RuntimeException(
                            'CRITICAL: Failed to persist upload metadata: ' . $e->getMessage() . '. ' .
                            'User-entered data was not persisted. This must be fixed immediately.',
                            0,
                            $e
                        );
                    }
                }
            } else {
                // Log when metadata fields are expected but not provided
                if ($categoryId !== null) {
                    Log::info('[UploadCompletionService] No metadata fields provided for upload', [
                        'asset_id' => $asset->id,
                        'category_id' => $categoryId,
                        'has_fields_key' => isset($metadataArray['fields']),
                    ]);
                }
            }

            // Emit AssetUploaded event after transaction commits so queued listener (ProcessAssetOnUpload)
            // runs only when the asset is visible to workers. Prevents "asset not found" in Redis/staging.
            // Local (sync): afterCommit still runs after commit; listener runs in same request, behavior unchanged.
            $assetForEvent = $asset;
            DB::afterCommit(function () use ($assetForEvent) {
                // TEMPORARY: QUEUE_DEBUG to verify staging dispatch (remove after confirmation)
                Log::info('[QUEUE_DEBUG] Entered upload processing', [
                    'env' => app()->environment(),
                    'queue' => config('queue.default'),
                ]);
                Log::info('[QUEUE_DEBUG] About to fire AssetUploaded (queues ProcessAssetOnUpload)', [
                    'job' => \App\Listeners\ProcessAssetOnUpload::class,
                    'env' => app()->environment(),
                ]);
                \App\Support\Logging\PipelineLogger::info('[UploadCompletionService] Dispatching AssetUploaded event', [
                    'asset_id' => $assetForEvent->id,
                    'asset_type' => $assetForEvent->type?->value ?? 'unknown',
                    'tenant_id' => $assetForEvent->tenant_id,
                    'brand_id' => $assetForEvent->brand_id,
                ]);
                event(new AssetUploaded($assetForEvent));

                // NOTE: Processing jobs are now handled by ProcessAssetJob chain via AssetUploaded event
                // Do NOT dispatch GenerateThumbnailsJob separately - it breaks the processing chain
                event(new AssetProcessingCompleteEvent($assetForEvent));
            });

            // Log asset upload finalized (non-blocking)
            try {
                \App\Services\ActivityRecorder::logAsset(
                    $asset,
                    \App\Enums\EventType::ASSET_UPLOAD_FINALIZED,
                    [
                        'upload_session_id' => $uploadSession->id,
                        'size_bytes' => $asset->size_bytes,
                        'mime_type' => $asset->mime_type,
                    ]
                );
            } catch (\Exception $e) {
                // Activity logging must never break processing
                Log::error('Failed to log asset upload finalized event', [
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $asset;
        });
    }

    /**
     * Finalize a multipart upload in S3 by assembling all uploaded parts.
     * This must be called before checking if the object exists, as the object
     * doesn't exist until all parts are assembled.
     *
     * @param UploadSession $uploadSession
     * @return void
     * @throws \RuntimeException If finalization fails
     */
    protected function finalizeMultipartUpload(UploadSession $uploadSession): void
    {
        if (!$uploadSession->multipart_upload_id) {
            throw new \RuntimeException('Multipart upload ID is required to finalize multipart upload.');
        }

        $bucket = $uploadSession->storageBucket;
        if (!$bucket) {
            throw new \RuntimeException('Storage bucket not found for upload session.');
        }

        $s3Key = $this->generateTempUploadPath($uploadSession);

        try {
            // List all parts that have been uploaded for this multipart upload
            $partsList = $this->s3Client->listParts([
                'Bucket' => $bucket->name,
                'Key' => $s3Key,
                'UploadId' => $uploadSession->multipart_upload_id,
            ]);

            $parts = [];
            foreach ($partsList->get('Parts') ?? [] as $part) {
                $parts[] = [
                    'PartNumber' => (int) $part['PartNumber'],
                    'ETag' => $part['ETag'],
                ];
            }

            if (empty($parts)) {
                throw new \RuntimeException('No parts found for multipart upload. Cannot finalize empty upload.');
            }

            // Sort parts by part number (required by S3)
            usort($parts, fn($a, $b) => $a['PartNumber'] <=> $b['PartNumber']);

            Log::info('Finalizing multipart upload with parts', [
                'upload_session_id' => $uploadSession->id,
                'multipart_upload_id' => $uploadSession->multipart_upload_id,
                'parts_count' => count($parts),
                'bucket' => $bucket->name,
                's3_key' => $s3Key,
            ]);

            // Complete the multipart upload by assembling all parts
            $this->s3Client->completeMultipartUpload([
                'Bucket' => $bucket->name,
                'Key' => $s3Key,
                'UploadId' => $uploadSession->multipart_upload_id,
                'MultipartUpload' => [
                    'Parts' => $parts,
                ],
            ]);

            Log::info('Multipart upload finalized successfully', [
                'upload_session_id' => $uploadSession->id,
                'multipart_upload_id' => $uploadSession->multipart_upload_id,
                'parts_count' => count($parts),
                'bucket' => $bucket->name,
                's3_key' => $s3Key,
            ]);
        } catch (S3Exception $e) {
            Log::error('Failed to finalize multipart upload', [
                'upload_session_id' => $uploadSession->id,
                'multipart_upload_id' => $uploadSession->multipart_upload_id,
                'bucket' => $bucket->name,
                's3_key' => $s3Key,
                'error' => $e->getMessage(),
                'aws_error_code' => $e->getAwsErrorCode(),
            ]);

            // Check if multipart upload was already completed
            if ($e->getAwsErrorCode() === 'NoSuchUpload') {
                Log::warning('Multipart upload not found - may have been already finalized or aborted', [
                    'upload_session_id' => $uploadSession->id,
                    'multipart_upload_id' => $uploadSession->multipart_upload_id,
                ]);
                // If upload was already completed, verify object exists
                $exists = $this->s3Client->doesObjectExist($bucket->name, $s3Key);
                if ($exists) {
                    Log::info('Multipart upload was already finalized - object exists in S3', [
                        'upload_session_id' => $uploadSession->id,
                        's3_key' => $s3Key,
                    ]);
                    return; // Object exists, can proceed
                }
                // Object doesn't exist - this is an error
                throw new \RuntimeException(
                    "Multipart upload was not found and object does not exist in S3. The upload may have been aborted or expired."
                );
            }

            throw new \RuntimeException(
                "Failed to finalize multipart upload: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Get file information from S3.
     * Never trusts client metadata - always verifies storage.
     *
     * @param UploadSession $uploadSession
     * @param string|null $s3Key Optional S3 key if known
     * @return array{original_filename: string, mime_type: string|null, size_bytes: int}
     * @throws \RuntimeException If object does not exist or verification fails
     */
    protected function getFileInfoFromS3(UploadSession $uploadSession, ?string $s3Key = null, ?string $fallbackFilename = null): array
    {
        $bucket = $uploadSession->storageBucket;

        // Generate expected S3 key using immutable contract: temp/uploads/{upload_session_id}/original
        // This path is deterministic and based solely on upload_session_id
        if (!$s3Key) {
            $s3Key = $this->generateTempUploadPath($uploadSession);
        }

        try {
            // Verify object exists in S3
            $exists = $this->s3Client->doesObjectExist($bucket->name, $s3Key);

            if (!$exists) {
                Log::error('Upload completion failed: object does not exist in S3', [
                    'upload_session_id' => $uploadSession->id,
                    'bucket' => $bucket->name,
                    's3_key' => $s3Key,
                ]);

                throw new \RuntimeException(
                    "Uploaded object does not exist in S3. Bucket: {$bucket->name}, Key: {$s3Key}"
                );
            }

            // Get object metadata (never trust client metadata)
            $headResult = $this->s3Client->headObject([
                'Bucket' => $bucket->name,
                'Key' => $s3Key,
            ]);

            $actualSize = (int) $headResult->get('ContentLength');
            $mimeType = $headResult->get('ContentType');
            // Try to extract filename from S3 metadata, fall back to provided filename, then to 'unknown'
            $extractedFilename = $this->extractFilenameFromMetadata($headResult);
            $originalFilename = $extractedFilename ?? $fallbackFilename ?? 'unknown';

            // Verify file size matches expected size (fail loudly on mismatch)
            if ($actualSize !== $uploadSession->expected_size) {
                Log::error('Upload completion failed: file size mismatch', [
                    'upload_session_id' => $uploadSession->id,
                    'expected_size' => $uploadSession->expected_size,
                    'actual_size' => $actualSize,
                    'bucket' => $bucket->name,
                    's3_key' => $s3Key,
                ]);

                throw new \RuntimeException(
                    "File size mismatch. Expected: {$uploadSession->expected_size} bytes, Actual: {$actualSize} bytes"
                );
            }

            Log::info('S3 object verification successful', [
                'upload_session_id' => $uploadSession->id,
                'bucket' => $bucket->name,
                's3_key' => $s3Key,
                'size_bytes' => $actualSize,
                'mime_type' => $mimeType,
            ]);

            return [
                'original_filename' => $originalFilename,
                'mime_type' => $mimeType,
                'size_bytes' => $actualSize,
            ];
        } catch (S3Exception $e) {
            Log::error('S3 verification failed', [
                'upload_session_id' => $uploadSession->id,
                'bucket' => $bucket->name,
                's3_key' => $s3Key,
                'error' => $e->getMessage(),
                'code' => $e->getAwsErrorCode(),
            ]);

            throw new \RuntimeException(
                "Failed to verify uploaded object in S3: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Generate temporary upload path for S3 (used during upload).
     *
     * IMMUTABLE CONTRACT - This path format must never change:
     *
     * Temporary upload objects must live at:
     *   temp/uploads/{upload_session_id}/original
     *
     * This path is:
     *   - Deterministic: Same upload_session_id always produces the same path
     *   - Never reused: Each upload_session_id is unique (UUID), ensuring path uniqueness
     *   - Safe to delete independently: Temp uploads are separate from final asset storage
     *
     * Why this matters:
     *   - Cleanup: Can safely delete temp uploads without affecting assets
     *   - Resume: Can locate partial uploads deterministically for retry/resume
     *   - Isolation: Temporary uploads are clearly separated from permanent asset storage
     *
     * The path MUST match UploadInitiationService::generateTempUploadPath() exactly.
     *
     * @param UploadSession $uploadSession The upload session
     * @return string S3 key path: temp/uploads/{upload_session_id}/original
     */
    protected function generateTempUploadPath(UploadSession $uploadSession): string
    {
        // IMMUTABLE: This path format must never change
        // Path is deterministic and based solely on upload_session_id
        // MUST match UploadInitiationService::generateTempUploadPath() exactly
        return "temp/uploads/{$uploadSession->id}/original";
    }

    /**
     * Generate final storage path for asset.
     *
     * Uses the provided filename (which may be the resolvedFilename from frontend,
     * derived from title + extension) for storage path generation.
     *
     * @param int $tenantId
     * @param int|null $brandId
     * @param string $filename Filename to use for storage (resolvedFilename from frontend, or original_filename from S3)
     * @param string $uploadSessionId
     * @return string
     */
    protected function generateStoragePath(
        int $tenantId,
        ?int $brandId,
        string $filename,
        string $uploadSessionId
    ): string {
        $basePath = "assets/{$tenantId}";

        if ($brandId) {
            $basePath .= "/{$brandId}";
        }

        // Generate unique filename to avoid conflicts
        // Uses provided filename (which respects user's title-derived filename if provided)
        $uniqueFileName = \Illuminate\Support\Str::uuid()->toString() . '_' . $filename;

        return "{$basePath}/{$uniqueFileName}";
    }

    /**
     * Extract filename from S3 object metadata.
     *
     * @param \Aws\Result $headResult
     * @return string|null
     */
    protected function extractFilenameFromMetadata(\Aws\Result $headResult): ?string
    {
        // Try to get filename from Content-Disposition header
        $contentDisposition = $headResult->get('ContentDisposition');
        if ($contentDisposition && preg_match('/filename[^;=\n]*=(([\'"]).*?\2|[^;\n]*)/', $contentDisposition, $matches)) {
            return trim($matches[1], '"\'');
        }

        // Try to get from metadata
        $metadata = $headResult->get('Metadata');
        if (isset($metadata['original-filename'])) {
            return $metadata['original-filename'];
        }

        return null;
    }

    /**
     * Derive human-readable title from filename.
     * 
     * For backfill: strips extension and converts slug to human-readable form.
     * Example: "my-awesome-image.jpg" -> "My Awesome Image"
     * Example: "MY_FILE_NAME.PNG" -> "My File Name"
     * 
     * @param string $filename
     * @return string
     */
    protected function deriveTitleFromFilename(string $filename): string
    {
        // Strip extension
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $nameWithoutExtension = $extension ? substr($filename, 0, -(strlen($extension) + 1)) : $filename;
        
        // Replace hyphens and underscores with spaces
        $withSpaces = str_replace(['-', '_'], ' ', $nameWithoutExtension);
        
        // Trim and collapse multiple spaces
        $trimmed = trim(preg_replace('/\s+/', ' ', $withSpaces));
        
        // Capitalize first letter of each word
        return ucwords(strtolower($trimmed));
    }

    /**
     * Check if thumbnail generation is supported for an asset.
     * 
     * Uses FileTypeService to determine support based on centralized configuration.
     * 
     * @param Asset $asset
     * @return bool True if thumbnail generation is supported
     */
    protected function supportsThumbnailGeneration(Asset $asset): bool
    {
        $fileTypeService = app(\App\Services\FileTypeService::class);
        $fileType = $fileTypeService->detectFileTypeFromAsset($asset);
        
        if (!$fileType) {
            return false;
        }
        
        // Check if requirements are met
        $requirements = $fileTypeService->checkRequirements($fileType);
        if (!$requirements['met']) {
            \Illuminate\Support\Facades\Log::warning('[UploadCompletionService] File type requirements not met', [
                'asset_id' => $asset->id,
                'file_type' => $fileType,
                'missing' => $requirements['missing'],
            ]);
            return false;
        }
        
        return $fileTypeService->supportsCapability($fileType, 'thumbnail');
    }

    /**
     * Create S3 client instance.
     *
     * @return S3Client
     * @throws \RuntimeException
     */
    protected function createS3Client(): S3Client
    {
        if (!class_exists(S3Client::class)) {
            throw new \RuntimeException(
                'AWS SDK for PHP is required for upload completion. ' .
                'Install it via: composer require aws/aws-sdk-php'
            );
        }

        $config = [
            'version' => 'latest',
            'region' => config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1')),
        ];

        // Endpoint for MinIO/local S3; credentials via SDK default chain (env vars or instance role)
        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }

        return new S3Client($config);
    }

    /**
     * Complete an upload session in replace mode.
     * 
     * Phase J.3.1: File-only replacement for rejected contributor assets
     * 
     * Replaces the S3 file for an existing asset without modifying metadata.
     * Updates asset file properties and resets approval status.
     * 
     * @param UploadSession $uploadSession Upload session with mode='replace' and asset_id set
     * @param string|null $s3Key Optional S3 key if known
     * @param int|null $userId User ID performing the replacement
     * @param string|null $comment Optional comment for resubmission
     * @return Asset The updated asset
     * @throws \Exception
     */
    protected function completeReplace(
        UploadSession $uploadSession,
        ?string $s3Key = null,
        ?int $userId = null,
        ?string $comment = null
    ): Asset {
        Log::info('[UploadCompletionService] Starting replace file completion', [
            'upload_session_id' => $uploadSession->id,
            'asset_id' => $uploadSession->asset_id,
            'user_id' => $userId,
        ]);

        // Load the asset being replaced
        $asset = Asset::findOrFail($uploadSession->asset_id);

        // Verify asset belongs to same tenant/brand as upload session
        if ($asset->tenant_id !== $uploadSession->tenant_id || $asset->brand_id !== $uploadSession->brand_id) {
            throw new \RuntimeException('Asset does not belong to the same tenant/brand as upload session.');
        }

        // Verify upload session can transition to COMPLETED
        if (!$uploadSession->canTransitionTo(UploadStatus::COMPLETED)) {
            throw new \RuntimeException(
                "Cannot transition upload session from {$uploadSession->status->value} to COMPLETED."
            );
        }

        // Finalize multipart upload if needed
        if ($uploadSession->type === UploadType::CHUNKED && $uploadSession->multipart_upload_id) {
            Log::info('[UploadCompletionService] Finalizing multipart upload for replace', [
                'upload_session_id' => $uploadSession->id,
                'multipart_upload_id' => $uploadSession->multipart_upload_id,
            ]);
            
            try {
                $this->finalizeMultipartUpload($uploadSession);
            } catch (\Exception $e) {
                Log::error('[UploadCompletionService] Failed to finalize multipart upload for replace', [
                    'upload_session_id' => $uploadSession->id,
                    'error' => $e->getMessage(),
                ]);
                throw new \RuntimeException("Failed to finalize multipart upload: {$e->getMessage()}", 0, $e);
            }
        }

        // Get file info from S3 (temp upload location)
        $tempS3Key = $s3Key ?? $this->generateTempUploadPath($uploadSession);
        $fileInfo = $this->getFileInfoFromS3($uploadSession, $tempS3Key, null);

        // Get S3 client and bucket
        $bucket = $uploadSession->storageBucket;
        if (!$bucket) {
            throw new \RuntimeException('Storage bucket not found for upload session');
        }
        $s3Client = $this->createS3Client();
        $bucketName = $bucket->name;

        // Get the asset's current S3 path
        $assetS3Key = $asset->storage_root_path;

        // Replace the file: Copy from temp location to asset's permanent location
        // This overwrites the existing file at the asset's storage path
        try {
            // CopySource must be a string in format "bucket/key" for AWS SDK
            $copySource = $bucketName . '/' . $tempS3Key;
            
            $s3Client->copyObject([
                'Bucket' => $bucketName,
                'CopySource' => $copySource,
                'Key' => $assetS3Key,
                'MetadataDirective' => 'REPLACE',
                'ContentType' => $fileInfo['mime_type'],
            ]);

            Log::info('[UploadCompletionService] File replaced in S3', [
                'asset_id' => $asset->id,
                'from_path' => $tempS3Key,
                'to_path' => $assetS3Key,
                'new_size' => $fileInfo['size_bytes'],
                'new_mime_type' => $fileInfo['mime_type'],
            ]);
        } catch (S3Exception $e) {
            Log::error('[UploadCompletionService] Failed to replace file in S3', [
                'asset_id' => $asset->id,
                'from_path' => $tempS3Key,
                'to_path' => $assetS3Key,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("Failed to replace file in S3: {$e->getMessage()}", 0, $e);
        }

        // Update asset file properties (file size, mime type, dimensions if available)
        // DO NOT touch metadata, title, category, or any other properties
        DB::transaction(function () use ($asset, $fileInfo, $uploadSession, $userId) {
            // Update file properties
            $asset->size_bytes = $fileInfo['size_bytes'];
            $asset->mime_type = $fileInfo['mime_type'];
            
            // Update dimensions if available in fileInfo
            if (isset($fileInfo['width']) && isset($fileInfo['height'])) {
                $asset->width = $fileInfo['width'];
                $asset->height = $fileInfo['height'];
            }

            // Reset approval status to pending (asset re-enters review queue)
            $asset->approval_status = \App\Enums\ApprovalStatus::PENDING;
            $asset->rejected_at = null;
            $asset->rejection_reason = null;
            
            // Keep unpublished until approved
            $asset->published_at = null;
            $asset->published_by_id = null;
            
            // Reset thumbnail status to pending (new file needs new thumbnails)
            // Also clear old thumbnail paths from metadata to prevent 404 errors
            // Phase J.3.1: Check if file type supports thumbnails (not just images)
            $fileTypeService = app(\App\Services\FileTypeService::class);
            $fileType = $fileTypeService->detectFileType($fileInfo['mime_type'] ?? '', pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION));
            $supportsThumbnails = $fileType && $fileTypeService->supportsCapability($fileType, 'thumbnail');
            
            if ($supportsThumbnails) {
                $asset->thumbnail_status = \App\Enums\ThumbnailStatus::PENDING;
                
                // Clear old thumbnail paths from metadata (prevents frontend from trying to load non-existent thumbnails)
                $metadata = $asset->metadata ?? [];
                if (isset($metadata['thumbnails'])) {
                    unset($metadata['thumbnails']);
                }
                if (isset($metadata['preview_thumbnails'])) {
                    unset($metadata['preview_thumbnails']);
                }
                
                // Phase J.3.1: Clear processing flags to allow ProcessAssetJob to run again
                // This is critical - without clearing these, ProcessAssetJob will skip the asset
                // and thumbnails will never be regenerated, causing infinite polling loops
                if (isset($metadata['processing_started'])) {
                    unset($metadata['processing_started']);
                }
                if (isset($metadata['processing_started_at'])) {
                    unset($metadata['processing_started_at']);
                }
                // Clear other processing flags that might prevent reprocessing
                if (isset($metadata['thumbnails_generated'])) {
                    unset($metadata['thumbnails_generated']);
                }
                if (isset($metadata['thumbnails_generated_at'])) {
                    unset($metadata['thumbnails_generated_at']);
                }
                if (isset($metadata['metadata_extracted'])) {
                    unset($metadata['metadata_extracted']);
                }
                
                $asset->metadata = $metadata;
                
                Log::info('[UploadCompletionService] Reset thumbnail status to PENDING for replaced file', [
                    'asset_id' => $asset->id,
                    'file_type' => $fileType,
                    'mime_type' => $fileInfo['mime_type'] ?? 'unknown',
                ]);
            }

            $asset->save();

            // Update upload session status
            $uploadSession->update([
                'status' => UploadStatus::COMPLETED,
                'uploaded_size' => $fileInfo['size_bytes'],
            ]);

            // Phase J.3.1: Record resubmission comment (optional comment from UI)
            if ($userId) {
                $user = \App\Models\User::find($userId);
                if ($user) {
                    $commentService = app(\App\Services\AssetApprovalCommentService::class);
                    $commentText = $comment ?? 'File replaced';
                    $commentService->recordResubmitted($asset, $user, $commentText);
                    
                    // Notify approvers
                    $notificationService = app(\App\Services\ApprovalNotificationService::class);
                    $notificationService->notifyOnResubmitted($asset, $user);
                }
            }

            Log::info('[UploadCompletionService] Asset file replaced successfully', [
                'asset_id' => $asset->id,
                'upload_session_id' => $uploadSession->id,
                'new_size' => $asset->size_bytes,
                'new_mime_type' => $asset->mime_type,
                'approval_status' => 'pending',
            ]);
        });

        // Refresh asset to get latest state
        $asset->refresh();

        // Phase J.3.1: Verify file exists at storage_root_path before dispatching events
        // This ensures thumbnail generation job can access the file
        $bucket = $uploadSession->storageBucket;
        if ($bucket) {
            $s3Client = $this->createS3Client();
            try {
                $s3Client->headObject([
                    'Bucket' => $bucket->name,
                    'Key' => $asset->storage_root_path,
                ]);
                Log::info('[UploadCompletionService] Verified replaced file exists in S3', [
                    'asset_id' => $asset->id,
                    'storage_path' => $asset->storage_root_path,
                ]);
            } catch (S3Exception $e) {
                Log::error('[UploadCompletionService] Replaced file not found in S3 - thumbnail generation may fail', [
                    'asset_id' => $asset->id,
                    'storage_path' => $asset->storage_root_path,
                    'error' => $e->getMessage(),
                ]);
                // Don't throw - let the job handle the error
            }
        }

        // Emit events for processing (thumbnails, metadata extraction, etc.)
        // The new file needs to be processed just like a new upload
        Log::info('[UploadCompletionService] Dispatching AssetUploaded event for replaced file', [
            'asset_id' => $asset->id,
            'thumbnail_status' => $asset->thumbnail_status?->value ?? 'null',
            'processing_started_cleared' => !isset($asset->metadata['processing_started']),
        ]);
        event(new AssetUploaded($asset));
        event(new AssetProcessingCompleteEvent($asset));

        return $asset;
    }
    
    /**
     * Determine skip reason for unsupported file types.
     * 
     * Uses FileTypeService to determine skip reasons from centralized configuration.
     * 
     * @param string $mimeType
     * @param string $extension
     * @return string Skip reason (e.g., "unsupported_format:tiff", "unsupported_format:avif")
     */
    protected function determineSkipReason(string $mimeType, string $extension): string
    {
        $fileTypeService = app(\App\Services\FileTypeService::class);
        
        // Check if explicitly unsupported
        $unsupported = $fileTypeService->getUnsupportedReason($mimeType, $extension);
        if ($unsupported) {
            return $unsupported['skip_reason'];
        }
        
        // Check if type is detected but doesn't support thumbnails
        $fileType = $fileTypeService->detectFileType($mimeType, $extension);
        if ($fileType && !$fileTypeService->supportsCapability($fileType, 'thumbnail')) {
            return "unsupported_format:{$fileType}";
        }
        
        // Generic fallback
        return 'unsupported_file_type';
    }
}
