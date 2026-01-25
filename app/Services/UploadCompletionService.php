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
        // 🔍 DEBUG LOGGING: Log parameters received by service
        Log::info('[UploadCompletionService] complete() called with parameters', [
            'upload_session_id' => $uploadSession->id,
            'asset_type' => $assetType ?? 'null',
            'filename' => $filename ?? 'null',
            'title' => $title ?? 'null',
            's3_key' => $s3Key ?? 'null',
            'category_id' => $categoryId ?? 'null',
            'metadata' => $metadata ?? 'null',
            'metadata_type' => gettype($metadata),
            'metadata_is_array' => is_array($metadata),
            'metadata_keys' => is_array($metadata) ? array_keys($metadata) : 'not_array',
        ]);
        
        // Refresh to get latest state
        $uploadSession->refresh();

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

        // 🔍 DEBUG LOGGING: Log title normalization process
        Log::info('[UploadCompletionService] Starting title normalization', [
            'original_title' => $title ?? 'null',
            'final_filename' => $finalFilename ?? 'null',
        ]);
        
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
        
        Log::info('[UploadCompletionService] Title normalization complete', [
            'normalized_title' => $derivedTitle ?? 'null',
        ]);

        // 🔍 DEBUG LOGGING: Log metadata building process
        Log::info('[UploadCompletionService] Building metadata object', [
            'category_id_param' => $categoryId ?? 'null',
            'metadata_param' => $metadata ?? 'null',
            'metadata_is_array' => is_array($metadata),
            'metadata_empty' => is_array($metadata) ? empty($metadata) : 'not_array',
            'metadata_keys' => is_array($metadata) ? array_keys($metadata) : 'not_array',
        ]);
        
        // Build metadata object: category_id at top level, fields nested
        // Structure: { category_id: 123, fields: { photographer: "John", location: "NYC" } }
        $metadataArray = [];
        
        // Add category_id if provided (always at top level)
        if ($categoryId !== null) {
            $metadataArray['category_id'] = $categoryId;
            Log::info('[UploadCompletionService] Added category_id to metadata', [
                'category_id' => $categoryId,
            ]);
        } else {
            Log::warning('[UploadCompletionService] category_id is null - not adding to metadata');
        }
        
        // Merge provided metadata fields into metadata object
        if (is_array($metadata) && !empty($metadata)) {
            Log::info('[UploadCompletionService] Processing metadata fields', [
                'has_fields_key' => isset($metadata['fields']),
                'fields_is_array' => isset($metadata['fields']) && is_array($metadata['fields']),
                'fields_empty' => isset($metadata['fields']) && is_array($metadata['fields']) ? empty($metadata['fields']) : 'n/a',
            ]);
            
            // Frontend sends metadata as { fields: {...} } to separate fields from category_id
            if (isset($metadata['fields']) && is_array($metadata['fields']) && !empty($metadata['fields'])) {
                $metadataArray['fields'] = $metadata['fields'];
                Log::info('[UploadCompletionService] Added fields from metadata.fields', [
                    'fields_keys' => array_keys($metadata['fields']),
                ]);
            } elseif (!isset($metadata['category_id'])) {
                // If no 'fields' key and no category_id, treat entire array as fields
                // (Backward compatibility: metadata could be sent as flat object of fields)
                $metadataArray['fields'] = $metadata;
                Log::info('[UploadCompletionService] Treated entire metadata as fields', [
                    'fields_keys' => array_keys($metadata),
                ]);
            } else {
                // Metadata has category_id mixed in - extract fields only
                $fields = $metadata;
                unset($fields['category_id']);
                if (!empty($fields)) {
                    $metadataArray['fields'] = $fields;
                    Log::info('[UploadCompletionService] Extracted fields from mixed metadata', [
                        'fields_keys' => array_keys($fields),
                    ]);
                }
            }
        } else {
            Log::info('[UploadCompletionService] No metadata fields to process', [
                'metadata_is_array' => is_array($metadata),
                'metadata_empty' => is_array($metadata) ? empty($metadata) : 'not_array',
            ]);
        }
        
        Log::info('[UploadCompletionService] Metadata object built', [
            'metadata_array' => $metadataArray,
            'has_category_id' => isset($metadataArray['category_id']),
            'has_fields' => isset($metadataArray['fields']),
            'fields_count' => isset($metadataArray['fields']) && is_array($metadataArray['fields']) ? count($metadataArray['fields']) : 0,
        ]);

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
                Log::info('[UploadCompletionService] Derived asset type from category', [
                    'category_id' => $categoryId,
                    'asset_type' => $assetTypeEnum->value,
                ]);
            } else {
                // Category not found - fall back to provided assetType or default
                Log::warning('[UploadCompletionService] Category not found, using provided assetType or default', [
                    'category_id' => $categoryId,
                    'provided_asset_type' => $assetType ?? 'null',
                ]);
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

            // 🔍 DEBUG LOGGING: Log asset data before persisting
            Log::info('[UploadCompletionService] About to create Asset record', [
                'upload_session_id' => $uploadSession->id,
                'tenant_id' => $uploadSession->tenant_id,
                'session_brand_id' => $uploadSession->brand_id,
                'target_brand_id' => $targetBrandId,
                'title' => $derivedTitle ?? 'null',
                'title_type' => gettype($derivedTitle),
                'original_filename' => $fileInfo['original_filename'] ?? 'null',
                'metadata_array' => $metadataArray,
                'metadata_json' => json_encode($metadataArray),
                'has_category_id' => isset($metadataArray['category_id']),
                'category_id_value' => $metadataArray['category_id'] ?? 'not_set',
                'has_fields' => isset($metadataArray['fields']),
                'category_id_param' => $categoryId ?? 'null',
                'asset_type' => $assetTypeEnum->value,
            ]);

            // Create asset - unique constraint prevents duplicates
            try {
                // 🔍 DEBUG LOGGING: Log exact data being passed to Asset::create()
                Log::info('[UploadCompletionService] Asset::create() called with data', [
                    'tenant_id' => $uploadSession->tenant_id,
                    'target_brand_id' => $targetBrandId,
                    'session_brand_id' => $uploadSession->brand_id,
                    'upload_session_id' => $uploadSession->id,
                    'storage_bucket_id' => $uploadSession->storage_bucket_id,
                    'status' => AssetStatus::VISIBLE->value,
                    'type' => $assetTypeEnum->value,
                    'title' => $derivedTitle ?? 'null',
                    'original_filename' => $fileInfo['original_filename'] ?? 'null',
                    'mime_type' => $fileInfo['mime_type'] ?? 'null',
                    'size_bytes' => $fileInfo['size_bytes'] ?? 'null',
                    'storage_root_path' => $storagePath ?? 'null',
                    'metadata_array' => $metadataArray,
                    'metadata_json' => json_encode($metadataArray),
                    'metadata_type' => gettype($metadataArray),
                ]);
                
                // AUDIT: Log brand_id being stored on asset for comparison with query brand_ids
                Log::info('[ASSET_QUERY_AUDIT] UploadCompletionService::complete() storing asset', [
                    'stored_tenant_id' => $uploadSession->tenant_id,
                    'stored_brand_id' => $targetBrandId,
                    'session_brand_id' => $uploadSession->brand_id,
                    'stored_brand_id_type' => gettype($targetBrandId),
                    'upload_session_id' => $uploadSession->id,
                    'note' => 'Compare stored_brand_id (from active brand context) against query_brand_id in AssetController and DashboardController',
                ]);

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
                ]);
                
                Log::info('[UploadCompletionService] Asset::create() succeeded', [
                    'asset_id' => $asset->id,
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
            
            // 🔍 VERIFICATION: Refresh asset and verify it saved correctly (runs for both new and duplicate cases)
            $asset->refresh();
            
            // AUDIT: Log actual brand_id stored in database after asset creation
            Log::info('[ASSET_QUERY_AUDIT] UploadCompletionService::complete() asset created and verified', [
                'asset_id' => $asset->id,
                'stored_tenant_id' => $asset->tenant_id,
                'stored_brand_id' => $asset->brand_id,
                'stored_brand_id_type' => gettype($asset->brand_id),
                'target_brand_id' => $targetBrandId,
                'session_brand_id' => $uploadSession->brand_id,
                'brand_id_matches_target' => $asset->brand_id == $targetBrandId,
                'upload_session_id' => $uploadSession->id,
                'note' => 'Verify stored_brand_id matches target_brand_id (from active brand context)',
            ]);
            
            Log::info('[UploadCompletionService] Asset created and refreshed from database', [
                'asset_id' => $asset->id,
                'upload_session_id' => $uploadSession->id,
                'title' => $asset->title ?? 'null',
                'title_type' => gettype($asset->title),
                'metadata' => $asset->metadata ?? 'null',
                'metadata_type' => gettype($asset->metadata),
                'metadata_is_array' => is_array($asset->metadata),
                'metadata_json' => is_array($asset->metadata) ? json_encode($asset->metadata) : 'not_array',
                'has_category_id_in_metadata' => is_array($asset->metadata) && isset($asset->metadata['category_id']),
                'category_id_in_metadata' => is_array($asset->metadata) && isset($asset->metadata['category_id']) ? $asset->metadata['category_id'] : 'not_set',
                'has_fields_in_metadata' => is_array($asset->metadata) && isset($asset->metadata['fields']),
            ]);
            
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
                    // Category not found - asset remains unpublished
                    Log::warning('[UploadCompletionService] Category not found for approval check', [
                        'asset_id' => $asset->id,
                        'category_id' => $categoryId,
                    ]);
                }
            } else {
                // No category or user ID - asset remains unpublished
                Log::info('[UploadCompletionService] Asset not auto-published (no category or user ID)', [
                    'asset_id' => $asset->id,
                    'category_id' => $categoryId,
                    'user_id' => $userId,
                ]);
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

            // Emit AssetUploaded event (only emit once, even if called multiple times)
            // The event system should handle duplicate events gracefully
            event(new AssetUploaded($asset));

            // NOTE: Processing jobs are now handled by ProcessAssetJob chain via AssetUploaded event
            // Do NOT dispatch GenerateThumbnailsJob separately - it breaks the processing chain
            // The ProcessAssetJob chain handles: ExtractMetadata -> GenerateThumbnails -> GeneratePreview -> ComputedMetadata -> etc.
            // If thumbnails are generated separately, ProcessAssetJob sees completed status and skips the entire chain
            // This prevents ComputedMetadataJob and other automated metadata jobs from running
            // 
            // For unsupported formats, ProcessAssetJob will handle marking as skipped
            // No need to dispatch jobs here - let the chain handle everything
            
            // NOTE: ExtractMetadataJob is also part of the ProcessAssetJob chain
            // Do NOT dispatch it separately - it will run as part of the chain
            
            // Emit AssetProcessingCompleteEvent
            event(new AssetProcessingCompleteEvent($asset));

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
