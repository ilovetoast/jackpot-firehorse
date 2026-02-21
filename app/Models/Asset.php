<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
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
        /**
         * CRITICAL: Never allow metadata updates to wipe category_id.
         * Assets without category_id disappear from the grid. Any job or code path
         * that replaces metadata must preserve category_id.
         */
        static::updating(function ($asset) {
            if ($asset->isDirty('metadata')) {
                $oldMeta = $asset->getOriginal('metadata');
                $oldMeta = is_array($oldMeta) ? $oldMeta : (is_string($oldMeta) ? json_decode($oldMeta, true) ?? [] : []);
                $oldCategoryId = $oldMeta['category_id'] ?? null;
                $newMeta = $asset->metadata ?? [];
                $newCategoryId = $newMeta['category_id'] ?? null;

                if ($oldCategoryId !== null && $oldCategoryId !== '' && ($newCategoryId === null || $newCategoryId === '' || (is_string($newCategoryId) && strtolower(trim($newCategoryId)) === 'null'))) {
                    $newMeta['category_id'] = $oldCategoryId;
                    $asset->metadata = $newMeta;
                    Log::warning('[Asset Metadata Guard] Preserved category_id from being wiped', [
                        'asset_id' => $asset->id,
                        'category_id' => $oldCategoryId,
                    ]);
                }
            }
        });

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
        'width',
        'height',
        'size_bytes',
        'storage_root_path',
        'metadata',
        'thumbnail_status',
        'thumbnail_error',
        'thumbnail_started_at',
        'thumbnail_retry_count',
        'thumbnail_last_retry_at',
        'published_at',
        'published_by_id',
        'archived_at',
        'archived_by_id',
        'expires_at',
        'approval_status',
        'approved_at',
        'approved_by_user_id',
        'rejected_at',
        'rejection_reason',
        'approval_summary', // Phase AF-6
        'approval_summary_generated_at', // Phase AF-6
        'video_duration', // Phase V-1: Video duration in seconds
        'video_width', // Phase V-1: Video width in pixels
        'video_height', // Phase V-1: Video height in pixels
        'video_poster_url', // Phase V-1: URL to video poster thumbnail
        'video_preview_url', // Phase V-1: URL to hover preview video
        'dominant_color_bucket', // Deprecated - kept for safety
        'dominant_hue_group', // Perceptual hue cluster for filtering
        'analysis_status', // Pipeline progress: uploading, generating_thumbnails, extracting_metadata, generating_embedding, scoring, complete
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
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
            'expires_at' => 'datetime',
            'approval_status' => ApprovalStatus::class,
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'approval_summary_generated_at' => 'datetime', // Phase AF-6
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
     * Get delivery URL for an asset variant in the given context.
     *
     * All asset URLs flow through AssetDeliveryService. The model delegates only;
     * no URL logic, Storage, CdnUrl, or path construction exists here.
     *
     * @param string|\App\Support\AssetVariant $variant AssetVariant enum or value
     * @param string|\App\Support\DeliveryContext $context DeliveryContext enum or value
     * @param array $options Optional (e.g. ['page' => 1] for PDF_PAGE, ['download' => Download] for PUBLIC_DOWNLOAD)
     * @return string CDN URL (plain or signed depending on context)
     */
    public function deliveryUrl(string|\App\Support\AssetVariant $variant, string|\App\Support\DeliveryContext $context, array $options = []): string
    {
        $variantValue = $variant instanceof \App\Support\AssetVariant ? $variant->value : $variant;
        $contextValue = $context instanceof \App\Support\DeliveryContext ? $context->value : $context;

        return app(\App\Services\AssetDeliveryService::class)->url(
            $this,
            $variantValue,
            $contextValue,
            $options
        );
    }

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
     * Check if asset is published.
     *
     * An asset is considered published if published_at is not null.
     *
     * @return bool True if asset has been published
     */
    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * Check if asset is archived.
     *
     * An asset is considered archived if archived_at is not null.
     *
     * @return bool True if asset has been archived
     */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Check if asset is expired.
     *
     * Phase M: Asset expiration (time-based access control).
     * An asset is expired if expires_at is not null and is in the past.
     * This is derived state - no enum or workflow state required.
     *
     * @return bool True if asset has expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether this asset is visible in the default brand asset grid.
     *
     * CRITICAL: This is the single source of truth for grid visibility.
     * Must match the grid query exactly: lifecycle + category_id.
     * An asset is visible when ALL of: not deleted, not archived, published,
     * status != FAILED/HIDDEN, and metadata.category_id is set.
     *
     * Category is required because the grid filters by metadata->category_id.
     * If category_id is null (e.g. wiped by FinalizeAssetJob bug), the asset
     * is excluded from the grid despite passing lifecycle checks.
     *
     * Keep in sync with scopeVisibleInGrid() / scopeNotVisibleInGrid().
     */
    public function isVisibleInGrid(): bool
    {
        if ($this->deleted_at !== null) {
            return false;
        }
        if ($this->archived_at !== null) {
            return false;
        }
        if ($this->published_at === null) {
            return false;
        }
        if ($this->status === AssetStatus::FAILED || $this->status === AssetStatus::HIDDEN) {
            return false;
        }

        // Category is required for grid visibility (grid filters by metadata->category_id)
        $meta = $this->metadata ?? [];
        $categoryId = $meta['category_id'] ?? null;
        if ($categoryId === null || $categoryId === '') {
            return false;
        }
        if (is_string($categoryId) && strtolower(trim($categoryId)) === 'null') {
            return false;
        }

        return true;
    }

    /**
     * Scope: assets visible in the default brand asset grid.
     *
     * Must match isVisibleInGrid() logic. Used for filtering (e.g. Admin Operations).
     * Includes category_id check so visibility matches actual grid display.
     */
    public function scopeVisibleInGrid($query)
    {
        $q = $query->whereNull('deleted_at')
            ->whereNull('archived_at')
            ->whereNotNull('published_at')
            ->whereNotIn('status', [AssetStatus::FAILED, AssetStatus::HIDDEN]);

        return static::applyCategoryIdScope($q, true);
    }

    /**
     * Scope: assets NOT visible in the default brand asset grid.
     *
     * Inverse of scopeVisibleInGrid. Used for Admin Operations filter.
     */
    public function scopeNotVisibleInGrid($query)
    {
        $extract = static::categoryIdJsonExtract();
        $categoryMissing = "({$extract}) IS NULL OR ({$extract}) = '' OR ({$extract}) = 'null'";

        return $query->where(function ($qb) use ($categoryMissing) {
            $qb->whereNotNull('deleted_at')
                ->orWhereNotNull('archived_at')
                ->orWhereNull('published_at')
                ->orWhereIn('status', [AssetStatus::FAILED, AssetStatus::HIDDEN])
                ->orWhereRaw($categoryMissing);
        });
    }

    /**
     * JSON extract for category_id (driver-specific).
     */
    protected static function categoryIdJsonExtract(): string
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            return 'JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id"))';
        }
        if ($driver === 'pgsql') {
            return "metadata->>'category_id'";
        }
        return "json_extract(metadata, '$.category_id')";
    }

    /**
     * Apply category_id filter to scope (required for grid visibility).
     */
    protected static function applyCategoryIdScope($query, bool $requirePresent): \Illuminate\Database\Eloquent\Builder
    {
        $driver = DB::getDriverName();
        $extract = static::categoryIdJsonExtract();

        if ($requirePresent) {
            $query->whereNotNull('metadata')
                ->whereRaw("({$extract}) IS NOT NULL")
                ->whereRaw("({$extract}) != ''")
                ->whereRaw("({$extract}) != 'null'");
        }

        return $query;
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
     * Get the user who published this asset.
     */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_id');
    }

    /**
     * Get the user who archived this asset.
     */
    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_id');
    }

    /**
     * Get the user who approved this asset.
     * 
     * Phase AF-1: Asset approval workflow.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
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
     * Get the versions for this asset (Phase 1A).
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AssetVersion::class);
    }

    /**
     * Get the current version (is_current = true). Phase 3B.
     */
    public function currentVersion(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(AssetVersion::class)->where('is_current', true);
    }

    /**
     * Get the embedding for this asset (imagery similarity scoring).
     */
    public function embedding(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(AssetEmbedding::class);
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
     * Get the collections this asset belongs to (C5).
     */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'asset_collections')
            ->withTimestamps();
    }

    /**
     * Scope: join with brand_compliance_scores for compliance-based filtering/sorting.
     */
    public function scopeWithCompliance(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->leftJoin('brand_compliance_scores', function ($join) {
            $join->on('assets.id', '=', 'brand_compliance_scores.asset_id')
                ->whereColumn('assets.brand_id', 'brand_compliance_scores.brand_id');
        });
    }

    /** Scope: overall_score >= 85 */
    public function scopeHighAlignment(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->withCompliance()->where('brand_compliance_scores.overall_score', '>=', 85);
    }

    /** Scope: overall_score >= 75 */
    public function scopeStrong(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->withCompliance()->where('brand_compliance_scores.overall_score', '>=', 75);
    }

    /** Scope: overall_score < 60 */
    public function scopeNeedsReview(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->withCompliance()->where('brand_compliance_scores.overall_score', '<', 60);
    }

    /** Scope: overall_score < 40 */
    public function scopeFailing(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->withCompliance()->where('brand_compliance_scores.overall_score', '<', 40);
    }

    /** Scope: no compliance score row */
    public function scopeUnscored(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->whereNotExists(function ($q) {
            $q->select(DB::raw(1))
                ->from('brand_compliance_scores')
                ->whereColumn('brand_compliance_scores.asset_id', 'assets.id')
                ->whereColumn('brand_compliance_scores.brand_id', 'assets.brand_id');
        });
    }

    /**
     * Scope: assets that support thumbnail-derived metadata (hasRasterThumbnail + thumbnail_status=completed + medium path).
     * Matches supportsThumbnailMetadata() for SQL-based integrity metrics.
     */
    public function scopeWhereSupportsThumbnailMetadata(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where('thumbnail_status', ThumbnailStatus::COMPLETED)
            ->whereNotNull('metadata->thumbnails->medium->path')
            ->where(function ($q) {
                $q->where('mime_type', 'like', 'image/%')
                    ->orWhere('mime_type', 'application/pdf')
                    ->orWhere('mime_type', 'like', 'video/%');
            });
    }

    /**
     * Scope: of those supporting thumbnail metadata, where visualMetadataReady() would be false.
     * Invalid = thumbnail_timeout OR missing/invalid dimensions.
     */
    public function scopeWhereVisualMetadataInvalid(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where(function ($q) {
            $q->where('metadata->thumbnail_timeout', true)
                ->orWhereNull('metadata->thumbnail_dimensions->medium')
                ->orWhereRaw('COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.thumbnail_dimensions.medium.width")) AS UNSIGNED), 0) <= 0')
                ->orWhereRaw('COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.thumbnail_dimensions.medium.height")) AS UNSIGNED), 0) <= 0');
        });
    }

    /**
     * Check if asset type produces a raster thumbnail (image, SVG, PDF, video, AI/EPS).
     *
     * Used for capability-based logic: orientation, resolution_class, dominant_colors
     * can be derived from thumbnails for these types. Does NOT treat PDF/video as true images.
     * AI/EPS (Illustrator, Encapsulated PostScript) produce raster thumbnails via Imagick.
     *
     * @return bool
     */
    public function hasRasterThumbnail(): bool
    {
        $fileTypeService = app(\App\Services\FileTypeService::class);
        $fileType = $fileTypeService->detectFileTypeFromAsset($this);

        return in_array($fileType, ['image', 'tiff', 'avif', 'svg', 'pdf', 'video', 'ai'], true);
    }

    /**
     * Check if asset supports thumbnail-derived metadata (orientation, resolution_class, dominant_colors).
     *
     * Requires: hasRasterThumbnail() AND thumbnail_status === COMPLETED AND medium thumbnail path exists.
     * Used by ComputedMetadataService, ColorAnalysisService, DominantColorsExtractor, BrandComplianceService.
     *
     * @return bool
     */
    public function supportsThumbnailMetadata(): bool
    {
        return $this->hasRasterThumbnail()
            && $this->thumbnail_status === ThumbnailStatus::COMPLETED
            && isset($this->metadata['thumbnails']['medium']['path']);
    }

    /**
     * Get persisted thumbnail dimensions for a style (from metadata, no S3 download).
     *
     * @param string $style Thumbnail style (e.g. 'medium')
     * @return array{width: int|null, height: int|null}|null
     */
    public function thumbnailDimensions(string $style = 'medium'): ?array
    {
        $dimensions = $this->metadata['thumbnail_dimensions'][$style] ?? null;

        // Fallback: check current version metadata (version-aware uploads)
        if ((!is_array($dimensions) || !isset($dimensions['width'], $dimensions['height'])) && $this->relationLoaded('currentVersion')) {
            $version = $this->currentVersion;
            if ($version) {
                $dimensions = ($version->metadata ?? [])['thumbnail_dimensions'][$style] ?? null;
            }
        }

        if (!is_array($dimensions) || !isset($dimensions['width'], $dimensions['height'])) {
            return null;
        }

        return [
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
        ];
    }

    /**
     * Check if asset is ready for visual metadata (orientation, resolution_class, dominant_colors).
     *
     * Consolidates: supportsThumbnailMetadata, thumbnail_timeout, thumbnail_dimensions.
     * Use this instead of scattered checks to prevent drift.
     *
     * @return bool
     */
    public function visualMetadataReady(): bool
    {
        if (!$this->supportsThumbnailMetadata()) {
            return false;
        }

        if ($this->metadata['thumbnail_timeout'] ?? false) {
            return false;
        }

        $dims = $this->thumbnailDimensions('medium');

        return $dims
            && isset($dims['width'], $dims['height'])
            && $dims['width'] > 0
            && $dims['height'] > 0;
    }

    /**
     * Check if asset is DEAD — source file missing from storage (NoSuchKey).
     * Cannot be recovered without re-upload.
     */
    public function isStorageMissing(): bool
    {
        return (bool) ($this->metadata['storage_missing'] ?? false);
    }

    /**
     * Compute asset health status for Ops/support visibility.
     * Derived from: storage_missing (DEAD), open incidents, visualMetadataReady(), thumbnail_status.
     *
     * @param string|null $worstIncidentSeverity 'critical'|'error'|'warning' from unresolved incidents
     * @return 'healthy'|'warning'|'critical'
     */
    public function computeHealthStatus(?string $worstIncidentSeverity): string
    {
        // DEAD asset — source file missing — always critical
        if ($this->isStorageMissing()) {
            return 'critical';
        }

        $ts = $this->thumbnail_status instanceof ThumbnailStatus
            ? $this->thumbnail_status->value
            : (string) ($this->thumbnail_status ?? 'pending');

        if ($worstIncidentSeverity === 'critical' || $worstIncidentSeverity === 'error' || $ts === 'failed') {
            return 'critical';
        }

        if ($worstIncidentSeverity === 'warning'
            || ($this->supportsThumbnailMetadata() && ! $this->visualMetadataReady())
            || in_array($ts, ['pending', 'processing'], true)) {
            return 'warning';
        }

        return 'healthy';
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
        
        if (isset($metadata['thumbnails'][$style]['path'])) {
            return $metadata['thumbnails'][$style]['path'];
        }

        // Fallback: check current version metadata (version-aware uploads store thumbnails on version first)
        $version = $this->currentVersion;
        if ($version) {
            $versionMeta = $version->metadata ?? [];
            if (isset($versionMeta['thumbnails'][$style]['path'])) {
                return $versionMeta['thumbnails'][$style]['path'];
            }
        }

        return null;
    }

    /**
     * Get the category for this asset.
     *
     * Categories are stored in asset metadata as category_id (JSON field).
     * This accessor looks up the category based on the ID stored in metadata.
     *
     * @return Category|null
     */
    public function getCategoryAttribute(): ?Category
    {
        $metadata = $this->metadata ?? [];
        $categoryId = $metadata['category_id'] ?? null;
        
        if (!$categoryId) {
            return null;
        }
        
        // Look up category by ID, scoped to the asset's tenant and brand
        return Category::where('id', $categoryId)
            ->where('tenant_id', $this->tenant_id)
            ->where('brand_id', $this->brand_id)
            ->first();
    }

}
