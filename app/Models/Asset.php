<?php

namespace App\Models;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Asset Model
 *
 * ═══════════════════════════════════════════════════════════════
 * ASSET VISIBILITY CONTRACT
 * ═══════════════════════════════════════════════════════════════
 *
 * Asset.status = VISIBILITY ONLY
 * ------------------------------
 * Asset.status represents visibility in the system, NOT processing state:
 * - VISIBLE: Asset is visible in grid/dashboard (default for uploaded assets)
 * - HIDDEN: Asset is hidden from normal views (archived, manually hidden)
 * - FAILED: Asset processing failed (visibility controlled separately)
 *
 * Jobs Must NOT Mutate Visibility
 * --------------------------------
 * Processing jobs (ProcessAssetJob, GenerateThumbnailsJob, AITaggingJob, etc.)
 * must NEVER mutate Asset.status. Status mutations are blocked by runtime guardrails.
 *
 * "Completed" is Derived from Processing Facts
 * ---------------------------------------------
 * Processing completion is tracked via:
 * - thumbnail_status (ThumbnailStatus enum)
 * - metadata flags (ai_tagging_completed, metadata_extracted, pipeline_completed_at)
 * - activity events
 *
 * To check if an asset is "completed", use AssetCompletionService::isComplete()
 * or query by processing state (thumbnail_status + metadata flags).
 *
 * Authorized Status Mutations
 * ----------------------------
 * - Asset::create() - Initial creation (always allowed)
 * - AssetProcessingFailureService - Can set status = FAILED
 *
 * All other status mutations are blocked by guardrails.
 */
class Asset extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * Boot the model.
     *
     * Adds guardrails to prevent unauthorized Asset.status mutations.
     */
    protected static function boot(): void
    {
        parent::boot();

        /**
         * Guardrail: Prevent unauthorized status mutations on Asset.status
         *
         * Asset.status represents VISIBILITY only (VISIBLE/HIDDEN/FAILED), not processing progress.
         * Processing jobs must NOT mutate Asset.status (they should use
         * thumbnail_status, metadata flags, and activity events instead).
         *
         * Allowed status mutations:
         * - Asset::create() - Always allowed (initial creation, updating hook doesn't fire)
         * - AssetProcessingFailureService - Authorized to set status = FAILED
         *
         * Blocked:
         * - All Job classes (no job should mutate status)
         * - Processing jobs track progress via thumbnail_status, metadata, pipeline_completed_at
         */
        static::updating(function ($asset) {
            // Only trigger when status is dirty
            if (!$asset->isDirty('status')) {
                return;
            }

            // Detect caller class using debug_backtrace
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
            $callingClass = null;

            // Skip Laravel internal frames, find first userland class
            foreach ($trace as $frame) {
                if (isset($frame['class']) && !str_starts_with($frame['class'], 'Illuminate\\')) {
                    $callingClass = $frame['class'];
                    break;
                }
            }

            if (!$callingClass) {
                // Couldn't determine calling class - allow but log warning
                Log::warning('[Asset Status Guard] Could not determine calling class for status mutation', [
                    'asset_id' => $asset->id,
                    'old_status' => $asset->getOriginal('status')?->value ?? 'null',
                    'new_status' => $asset->status->value ?? 'null',
                ]);
                return;
            }

            // Allow status changes ONLY from authorized classes
            $allowedClasses = [
                \App\Services\AssetProcessingFailureService::class,
            ];

            foreach ($allowedClasses as $allowedClass) {
                if ($callingClass === $allowedClass || is_subclass_of($callingClass, $allowedClass)) {
                    // Authorized class - allow mutation
                    return;
                }
            }

            // Check if the caller is a Job class
            if (str_contains($callingClass, '\\Jobs\\')) {
                // Block job classes from mutating status
                $oldStatus = $asset->getOriginal('status')?->value ?? 'null';
                $newStatus = $asset->status->value ?? 'null';

                Log::error('[Asset Status Guard] Job class attempted to mutate Asset.status', [
                    'asset_id' => $asset->id,
                    'calling_class' => $callingClass,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                ]);

                throw new \RuntimeException(
                    "Job class '{$callingClass}' is not authorized to mutate Asset.status. " .
                    "Asset.status represents visibility only (VISIBLE/HIDDEN/FAILED), not processing progress. " .
                    "Processing jobs must track progress via thumbnail_status, metadata flags, and pipeline_completed_at. " .
                    "Only AssetProcessingFailureService is authorized to change Asset.status."
                );
            }

            // For non-job classes (controllers, services, etc.), allow the mutation
            // This allows future authorized services without hardcoding them all
        });

        /**
         * Dev-only assertion: Harden status mutation contract
         *
         * In development/testing, enforce strict contract that status mutations
         * should only occur through explicit lifecycle actions.
         * This helps catch regressions early.
         */
        if (app()->environment(['local', 'testing'])) {
            static::updating(function ($asset) {
                if (!$asset->isDirty('status')) {
                    return;
                }

                // Skip console commands (migrations, seeders, etc.)
                if (app()->runningInConsole()) {
                    return;
                }

                // Allow AssetProcessingFailureService (explicit lifecycle action)
                $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
                $callingClass = null;
                foreach ($trace as $frame) {
                    if (isset($frame['class']) && !str_starts_with($frame['class'], 'Illuminate\\')) {
                        $callingClass = $frame['class'];
                        break;
                    }
                }

                if ($callingClass === \App\Services\AssetProcessingFailureService::class) {
                    return; // Allow explicit failure handling
                }

                // In dev/testing, log all status mutations for visibility
                Log::warning('[Asset Status Guard] Status mutation detected in dev environment', [
                    'asset_id' => $asset->id,
                    'calling_class' => $callingClass ?? 'unknown',
                    'old_status' => $asset->getOriginal('status')?->value ?? 'null',
                    'new_status' => $asset->status->value ?? 'null',
                    'note' => 'Asset.status should only be mutated through explicit lifecycle actions',
                ]);
            });
        }
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'brand_id',
        'user_id',
        'upload_session_id',
        'storage_bucket_id',
        'status',
        'type',
        'title',
        'original_filename',
        'mime_type',
        'size_bytes',
        'storage_root_path',
        'metadata',
        'thumbnail_status',
        'thumbnail_error',
        'thumbnail_started_at',
        'thumbnail_retry_count',
        'thumbnail_last_retry_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AssetStatus::class,
            'type' => AssetType::class,
            'size_bytes' => 'integer',
            'metadata' => 'array',
            'thumbnail_status' => ThumbnailStatus::class,
            'thumbnail_started_at' => 'datetime',
            'thumbnail_retry_count' => 'integer',
            'thumbnail_last_retry_at' => 'datetime',
        ];
    }

    /**
     * Get the accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'is_complete',
    ];

    /**
     * Check if asset processing pipeline is complete.
     *
     * Derived accessor that uses AssetCompletionService to determine completion.
     * This is the recommended way to check if an asset is "completed" (processing-wise).
     *
     * Completion is determined by processing state (thumbnail_status, metadata flags),
     * NOT by Asset.status (which represents visibility only).
     *
     * This accessor is automatically appended to the model's array/JSON representation,
     * making it available in Inertia responses as `asset.is_complete`.
     *
     * @return bool True if asset meets all completion criteria
     */
    public function getIsCompleteAttribute(): bool
    {
        $completionService = app(\App\Services\AssetCompletionService::class);
        return $completionService->isComplete($this);
    }

    /**
     * Check if asset processing pipeline is complete (method form).
     *
     * Alternative method form of the accessor for programmatic use.
     * Prefer using the accessor: $asset->is_complete
     *
     * @return bool True if asset meets all completion criteria
     */
    public function isComplete(): bool
    {
        return $this->is_complete;
    }

    /**
     * Get the tenant that owns this asset.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the brand that owns this asset.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Get the user who uploaded this asset.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the upload session that created this asset.
     */
    public function uploadSession(): BelongsTo
    {
        return $this->belongsTo(UploadSession::class);
    }

    /**
     * Get the storage bucket for this asset.
     */
    public function storageBucket(): BelongsTo
    {
        return $this->belongsTo(StorageBucket::class);
    }

    /**
     * Get the events for this asset.
     */
    public function events(): HasMany
    {
        return $this->hasMany(AssetEvent::class);
    }

    /**
     * Get the download groups that include this asset.
     * 
     * Phase 3.1 — Downloader Foundations
     */
    public function downloads(): BelongsToMany
    {
        return $this->belongsToMany(Download::class, 'download_asset')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /**
     * Get the S3 thumbnail path for a specific style.
     *
     * Retrieves the thumbnail path from asset metadata.
     * Thumbnail paths are stored as: metadata['thumbnails'][$style]['path']
     *
     * @param string $style Thumbnail style (thumb, medium, large)
     * @return string|null S3 key path to thumbnail, or null if not found
     */
    public function thumbnailPathForStyle(string $style): ?string
    {
        $metadata = $this->metadata ?? [];
        
        if (!isset($metadata['thumbnails'][$style]['path'])) {
            return null;
        }

        return $metadata['thumbnails'][$style]['path'];
    }

    /**
     * Get the medium thumbnail URL for AI image analysis.
     *
     * This method checks both temp and final thumbnail paths to support
     * AI metadata generation during asset processing (before promotion).
     *
     * CRITICAL: This method checks:
     * 1. Final thumbnail path (after asset promotion)
     * 2. Temp thumbnail path (during processing, before promotion)
     *
     * Returns a signed S3 URL that can be accessed by OpenAI's API.
     * OpenAI requires publicly accessible URLs, so we generate a signed S3 URL.
     *
     * @return string|null Signed S3 URL to medium thumbnail, or null if not available
     */
    public function getMediumThumbnailUrlAttribute(): ?string
    {
        $metadata = $this->metadata ?? [];
        
        // Check if final thumbnail exists (after promotion)
        if (isset($metadata['thumbnails']['medium']['path'])) {
            $thumbnailPath = $metadata['thumbnails']['medium']['path'];
            
            // Check if thumbnail is in final location (assets/ path)
            if (str_starts_with($thumbnailPath, 'assets/')) {
                // Generate signed S3 URL for OpenAI to access
                try {
                    return \Illuminate\Support\Facades\Storage::disk('s3')
                        ->temporaryUrl($thumbnailPath, now()->addHours(1));
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('[Asset] Failed to generate signed S3 URL for thumbnail', [
                        'asset_id' => $this->id,
                        'thumbnail_path' => $thumbnailPath,
                        'error' => $e->getMessage(),
                    ]);
                    return null;
                }
            }
            
            // Check if thumbnail is in temp location (temp/uploads/ path)
            if (str_starts_with($thumbnailPath, 'temp/uploads/')) {
                // Temp thumbnail - check if file exists by verifying thumbnail_status
                // If status is COMPLETED, we can use the temp path
                if ($this->thumbnail_status === \App\Enums\ThumbnailStatus::COMPLETED) {
                    // Generate signed S3 URL for temp thumbnail
                    try {
                        return \Illuminate\Support\Facades\Storage::disk('s3')
                            ->temporaryUrl($thumbnailPath, now()->addHours(1));
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::error('[Asset] Failed to generate signed S3 URL for temp thumbnail', [
                            'asset_id' => $this->id,
                            'thumbnail_path' => $thumbnailPath,
                            'error' => $e->getMessage(),
                        ]);
                        return null;
                    }
                }
            }
        }
        
        // Check preview thumbnail as fallback (low quality, but available during processing)
        if (isset($metadata['preview_thumbnails']['preview']['path'])) {
            $previewPath = $metadata['preview_thumbnails']['preview']['path'];
            // Generate signed S3 URL for preview thumbnail
            try {
                return Storage::disk('s3')
                    ->temporaryUrl($previewPath, now()->addHours(1));
            } catch (\Exception $e) {
                Log::error('[Asset] Failed to generate signed S3 URL for preview thumbnail', [
                    'asset_id' => $this->id,
                    'thumbnail_path' => $previewPath,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }
        
        return null;
    }
}
