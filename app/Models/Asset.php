<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Jobs\FullPdfExtractionJob;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use App\Support\ThumbnailMetadata;
use DomainException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

                // Intake: when category_id is newly assigned to a staged asset, transition to normal
                $hadCategory = $oldCategoryId !== null && $oldCategoryId !== '' && (is_string($oldCategoryId) ? strtolower(trim($oldCategoryId)) !== 'null' : true);
                $hasCategory = $newCategoryId !== null && $newCategoryId !== '' && (is_string($newCategoryId) ? strtolower(trim($newCategoryId)) !== 'null' : true);
                if (! $hadCategory && $hasCategory && ($asset->intake_state ?? 'normal') === 'staged') {
                    $asset->intake_state = 'normal';
                }
            }
        });

        static::updating(function ($asset) {
            // Only trigger when status is dirty
            if (! $asset->isDirty('status')) {
                return;
            }

            // Detect caller class using debug_backtrace
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
            $callingClass = null;

            // Skip Laravel internal frames, find first userland class
            foreach ($trace as $frame) {
                if (isset($frame['class']) && ! str_starts_with($frame['class'], 'Illuminate\\')) {
                    $callingClass = $frame['class'];
                    break;
                }
            }

            if (! $callingClass) {
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
                    "Job class '{$callingClass}' is not authorized to mutate Asset.status. ".
                    'Asset.status represents visibility only (VISIBLE/HIDDEN/FAILED), not processing progress. '.
                    'Processing jobs must track progress via thumbnail_status, metadata flags, and pipeline_completed_at. '.
                    'Only AssetProcessingFailureService is authorized to change Asset.status.'
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
                if (! $asset->isDirty('status')) {
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
                    if (isset($frame['class']) && ! str_starts_with($frame['class'], 'Illuminate\\')) {
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

        // Soft-delete: FK cascade only runs on hard delete. Drop pending AI review rows so Insights/accept flows stay consistent.
        static::deleted(function (Asset $asset) {
            if ($asset->isForceDeleting()) {
                return;
            }
            $id = $asset->getKey();
            DB::table('asset_tag_candidates')->where('asset_id', $id)->delete();
            DB::table('asset_metadata_candidates')->where('asset_id', $id)->delete();
        });
    }

    /**
     * Prostaff attribution: {@see $prostaff_user_id} may be set once (null → id) in the upload service; never reassigned.
     */
    protected static function booted(): void
    {
        static::updating(function (Asset $asset): void {
            if (! $asset->isDirty('prostaff_user_id')) {
                return;
            }

            $original = $asset->getOriginal('prostaff_user_id');
            if ($original !== null && $original !== '') {
                throw new DomainException('prostaff_user_id is immutable once set');
            }
        });
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
        'lifecycle',
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
        'captured_at',
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
        'pdf_page_count',
        'pdf_pages_rendered',
        'dominant_hue_group', // Perceptual hue cluster for filtering
        'deleted_by_user_id', // Phase B2: User who soft-deleted the asset
        'analysis_status', // Pipeline progress: uploading, generating_thumbnails, extracting_metadata, generating_embedding, scoring, complete
        'pdf_page_count',
        'pdf_unsupported_large',
        'pdf_rendered_pages_count',
        'pdf_rendered_storage_bytes',
        'pdf_pages_rendered',
        'full_pdf_extraction_batch_id',
        'builder_staged', // Brand Guidelines Builder: hidden from grid until finalized
        'builder_context', // e.g. logo_primary, photo_reference, texture_reference
        'source', // e.g. builder, crawler, upload
        'intake_state', // normal | staged: staged = no category yet, shown on /assets/staged
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
            // Nullable: optional capture time from embedded EXIF when mapped (fill_if_empty). Safe null everywhere — no sorts assume non-null.
            'captured_at' => 'datetime',
            'approval_status' => ApprovalStatus::class,
            'submitted_by_prostaff' => 'boolean',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'approval_summary_generated_at' => 'datetime', // Phase AF-6
            'pdf_page_count' => 'integer',
            'pdf_unsupported_large' => 'boolean',
            'pdf_rendered_pages_count' => 'integer',
            'pdf_rendered_storage_bytes' => 'integer',
            'pdf_pages_rendered' => 'boolean',
            'builder_staged' => 'boolean',
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
     * @param  string|\App\Support\AssetVariant  $variant  AssetVariant enum or value
     * @param  string|\App\Support\DeliveryContext  $context  DeliveryContext enum or value
     * @param  array  $options  Optional (e.g. ['page' => 1] for PDF_PAGE, ['download' => Download] for PUBLIC_DOWNLOAD)
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
     * Determine whether the asset is publicly accessible through collection membership.
     *
     * Public eligibility is derived from collections, not from asset-level flags.
     * An asset is public when it belongs to at least one collection where
     * is_public = true and slug is set (slug is required for public URL generation).
     */
    public function isPublic(): bool
    {
        if ($this->deleted_at !== null) {
            return false;
        }

        return $this->collections()
            ->where('collections.is_public', true)
            ->whereNotNull('collections.slug')
            ->where('collections.slug', '!=', '')
            ->exists();
    }

    /**
     * Resolve implicit {asset} route binding including soft-deleted rows.
     *
     * Without this, trash/deleted lifecycle views and the asset drawer return 404 for any
     * API that uses route model binding, even when the user can legitimately open the asset
     * (e.g. restore from trash). Access is still enforced per action via {@see \App\Policies\AssetPolicy}.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $column = $field ?? $this->getRouteKeyName();

        return static::withTrashed()
            ->where($column, $value)
            ->firstOrFail();
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
     * SQL expression casting metadata JSON category_id to integer for WHERE IN / GROUP BY (driver-specific).
     * Matches countNonDeletedByCategoryForTenant() casting rules.
     */
    public static function categoryIdMetadataCastExpression(): string
    {
        $extract = static::categoryIdJsonExtract();
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            return "CAST({$extract} AS UNSIGNED)";
        }
        if ($driver === 'pgsql') {
            return "NULLIF(TRIM({$extract}), '')::bigint";
        }

        return "CAST({$extract} AS INTEGER)";
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
     * Single grouped query: non-deleted assets per metadata.category_id (insights jobs, admin batching).
     *
     * @return array<int, int> category_id => count
     */
    public static function countNonDeletedByCategoryForTenant(int $tenantId): array
    {
        $extract = static::categoryIdJsonExtract();
        $driver = DB::getDriverName();

        $base = DB::table('assets')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereNotNull('metadata');

        if ($driver === 'mysql') {
            $rows = $base
                ->selectRaw("CAST({$extract} AS UNSIGNED) as category_id, COUNT(*) as aggregate")
                ->whereRaw("({$extract}) IS NOT NULL")
                ->whereRaw("({$extract}) != ''")
                ->whereRaw("({$extract}) != 'null'")
                ->groupBy(DB::raw("CAST({$extract} AS UNSIGNED)"))
                ->get();
        } elseif ($driver === 'pgsql') {
            $rows = $base
                ->selectRaw("NULLIF(TRIM({$extract}), '')::bigint as category_id, COUNT(*) as aggregate")
                ->whereRaw("({$extract}) IS NOT NULL")
                ->whereRaw("TRIM({$extract}) != ''")
                ->groupBy(DB::raw("NULLIF(TRIM({$extract}), '')::bigint"))
                ->get();
        } else {
            $rows = $base
                ->selectRaw("CAST({$extract} AS INTEGER) as category_id, COUNT(*) as aggregate")
                ->whereRaw("({$extract}) IS NOT NULL")
                ->whereRaw("({$extract}) != ''")
                ->whereRaw("({$extract}) != 'null'")
                ->groupBy(DB::raw("CAST({$extract} AS INTEGER)"))
                ->get();
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row->category_id;
            if ($id > 0) {
                $out[$id] = (int) $row->aggregate;
            }
        }

        return $out;
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
     * Category id from metadata (not a real column). Enables Eloquent `with('category')`.
     */
    protected function categoryId(): Attribute
    {
        return Attribute::get(function (): ?string {
            $meta = $this->metadata ?? [];
            $id = $meta['category_id'] ?? null;
            if ($id === null || $id === '' || (is_string($id) && strtolower(trim($id)) === 'null')) {
                return null;
            }

            return is_string($id) ? $id : (string) $id;
        });
    }

    /**
     * Category for this asset (metadata.category_id → categories.id).
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id', 'id');
    }

    /**
     * Load category from metadata JSON with an explicit query (no lazy load on {@see category()}).
     * Use anywhere {@see Model::preventLazyLoading()} is enabled.
     */
    public function resolveCategoryForTenant(): ?Category
    {
        $meta = $this->metadata ?? [];
        $raw = $meta['category_id'] ?? null;
        if ($raw === null || $raw === '' || (is_string($raw) && strtolower(trim($raw)) === 'null')) {
            return null;
        }
        $id = (int) $raw;
        if ($id <= 0) {
            return null;
        }

        return Category::query()
            ->where('id', $id)
            ->where('tenant_id', $this->tenant_id)
            ->first();
    }

    /**
     * Get the user who uploaded this asset.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Prostaff member attributed on upload (Phase 3); may differ from {@see user()} in edge cases.
     */
    public function prostaffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prostaff_user_id');
    }

    public function isProstaffAsset(): bool
    {
        return $this->submitted_by_prostaff === true;
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
     * Raw embedded metadata payloads (Layer B), keyed by source.
     */
    public function metadataPayloads(): HasMany
    {
        return $this->hasMany(AssetMetadataPayload::class);
    }

    /**
     * Convenience: current embedded file-native payload (source = embedded).
     */
    public function embeddedMetadataPayload(): HasOne
    {
        return $this->hasOne(AssetMetadataPayload::class)->where('source', 'embedded');
    }

    /**
     * Derived searchable index rows (Layer C).
     */
    public function metadataIndexEntries(): HasMany
    {
        return $this->hasMany(AssetMetadataIndexEntry::class);
    }

    /**
     * Latest Execution-Based Brand Intelligence score for this asset (asset-targeted rows only).
     */
    public function latestBrandIntelligenceScore(): HasOne
    {
        return $this->hasOne(BrandIntelligenceScore::class)
            ->whereNull('execution_id')
            ->latestOfMany('updated_at');
    }

    /**
     * User-promoted style reference row for this asset (one per brand).
     */
    public function brandReferenceAsset(): HasOne
    {
        return $this->hasOne(BrandReferenceAsset::class);
    }

    /**
     * Brand Intelligence fields for the asset drawer / grid (excludes raw numeric overall score).
     *
     * @param  array<string, self>|null  $referenceAssetsById  null = legacy: resolve each top_reference via a query. Non-null (incl. []) = use only this id → Asset map (batch from controller; avoids N+1).
     * @return array{level: string|null, confidence: float|null, breakdown_json: array, alignment_state: ?string, signal_count: ?int, signal_breakdown: ?array, reference_tier_usage: ?array, debug: ?array}|null
     */
    public function brandIntelligencePayloadForFrontend(?array $referenceAssetsById = null): ?array
    {
        $row = $this->latestBrandIntelligenceScore;
        if (! $row) {
            return null;
        }

        $bj = $row->breakdown_json ?? [];

        $payload = [
            'level' => $row->level,
            'confidence' => $row->confidence,
            'breakdown_json' => $bj,
            'alignment_state' => $bj['alignment_state'] ?? null,
            'signal_count' => $bj['signal_count'] ?? null,
            'signal_breakdown' => $bj['signal_breakdown'] ?? null,
            'reference_tier_usage' => $bj['reference_tier_usage'] ?? null,
            'debug' => $this->hydrateBrandIntelligenceDebug(
                is_array($bj['debug'] ?? null) ? $bj['debug'] : null,
                $referenceAssetsById
            ),
        ];

        if (isset($bj['dimensions'])) {
            $payload['dimensions'] = $bj['dimensions'];
            $payload['evaluation_context'] = $bj['evaluation_context'] ?? null;
            $payload['dimension_weights'] = $bj['dimension_weights'] ?? null;
            $payload['weighted_score'] = $bj['weighted_score'] ?? null;
            $payload['overall_confidence'] = $bj['overall_confidence'] ?? null;
            $payload['evaluable_proportion'] = $bj['evaluable_proportion'] ?? null;
            $payload['rating'] = $bj['rating'] ?? null;
            $payload['rating_derivation'] = $bj['rating_derivation'] ?? null;
            $payload['v2_alignment_state'] = $bj['v2_alignment_state'] ?? null;
            $payload['v2_recommendations'] = $bj['v2_recommendations'] ?? null;
        }

        return $payload;
    }

    /**
     * Add signed thumbnail URLs to debug top references for the app UI.
     *
     * @param  array<string, mixed>|null  $debug
     * @param  array<string, self>|null  $referenceAssetsById  see {@see brandIntelligencePayloadForFrontend()}
     * @return array<string, mixed>|null
     */
    protected function hydrateBrandIntelligenceDebug(?array $debug, ?array $referenceAssetsById = null): ?array
    {
        if ($debug === null) {
            return null;
        }

        $refs = $debug['top_references'] ?? null;
        if (! is_array($refs) || $refs === []) {
            return $debug;
        }

        $hydrated = [];
        foreach ($refs as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $row['id'] ?? null;
            $thumbnail = null;
            if (is_string($id) && $id !== '') {
                $refAsset = null;
                if ($referenceAssetsById !== null) {
                    $refAsset = $referenceAssetsById[$id] ?? null;
                } else {
                    $refAsset = static::query()->whereKey($id)->first();
                }
                if ($refAsset !== null) {
                    $url = $refAsset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED);
                    $thumbnail = $url !== '' ? $url : null;
                }
            }
            $hydrated[] = array_merge($row, ['thumbnail' => $thumbnail]);
        }
        $debug['top_references'] = $hydrated;

        return $debug;
    }

    /**
     * Get the events for this asset.
     */
    public function events(): HasMany
    {
        return $this->hasMany(AssetEvent::class);
    }

    /**
     * Get rendered PDF page derivatives for this asset.
     */
    public function pdfPages(): HasMany
    {
        return $this->hasMany(AssetPdfPage::class);
    }

    /**
     * Get PDF text extraction records for this asset (OCR / pdftotext).
     */
    public function pdfTextExtractions(): HasMany
    {
        return $this->hasMany(PdfTextExtraction::class);
    }

    /**
     * Get the latest PDF text extraction for this asset (by created_at).
     * Prefer version-aware lookup via getLatestPdfTextExtractionForVersion().
     */
    public function latestPdfTextExtraction(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PdfTextExtraction::class)->latestOfMany('created_at');
    }

    /**
     * Get the latest PDF text extraction for this asset for a specific file version.
     * Prevents re-using OCR from an outdated PDF after replacement.
     *
     * @param  string|null  $versionId  asset_version_id (e.g. currentVersion->id); null = legacy/no version
     */
    public function getLatestPdfTextExtractionForVersion(?string $versionId = null): ?PdfTextExtraction
    {
        $query = PdfTextExtraction::query()
            ->where('asset_id', $this->id)
            ->orderByDesc('created_at');

        if ($versionId === null) {
            $query->whereNull('asset_version_id');
        } else {
            $query->where('asset_version_id', $versionId);
        }

        return $query->first();
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
     * Scope: join latest Brand Intelligence score row per asset for filtering/sorting.
     */
    public function scopeWithCompliance(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->leftJoin('brand_intelligence_scores as bis_scope', function ($join) {
            $join->on('bis_scope.asset_id', '=', 'assets.id')
                ->whereColumn('bis_scope.brand_id', 'assets.brand_id')
                ->whereNull('bis_scope.execution_id')
                ->whereRaw('bis_scope.id = (SELECT MAX(bis2.id) FROM brand_intelligence_scores bis2 WHERE bis2.asset_id = assets.id AND bis2.brand_id = assets.brand_id AND bis2.execution_id IS NULL)');
        });
    }

    /** Scope: overall_score >= 85 */
    public function scopeHighAlignment(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->withCompliance()->where('bis_scope.overall_score', '>=', 85);
    }

    /** Scope: overall_score >= 75 */
    public function scopeStrong(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->withCompliance()->where('bis_scope.overall_score', '>=', 75);
    }

    /** Scope: overall_score < 60 */
    public function scopeNeedsReview(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->withCompliance()->where('bis_scope.overall_score', '<', 60);
    }

    /** Scope: overall_score < 40 */
    public function scopeFailing(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->withCompliance()->where('bis_scope.overall_score', '<', 40);
    }

    /** Scope: no Brand Intelligence score row */
    public function scopeUnscored(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->whereNotExists(function ($q) {
            $q->select(DB::raw(1))
                ->from('brand_intelligence_scores')
                ->whereColumn('brand_intelligence_scores.asset_id', 'assets.id')
                ->whereColumn('brand_intelligence_scores.brand_id', 'assets.brand_id')
                ->whereNull('brand_intelligence_scores.execution_id');
        });
    }

    /**
     * Scope: exclude builder-staged assets (Brand Guidelines Builder uploads).
     * Staged assets are hidden from main library grid until finalized.
     */
    public function scopeExcludeBuilderStaged(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where(function ($q) {
            $q->where('builder_staged', false)->orWhereNull('builder_staged');
        });
    }

    /**
     * Scope: exclude editor composition preview / WIP assets (metadata flags).
     */
    public function scopeExcludeCompositionTagged(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->whereNot(function ($q) {
            $q->where('metadata->composition_wip', true)
                ->orWhere('metadata->composition_preview', true);
        });
    }

    /**
     * Scope: only editor composition WIP / preview assets (metadata flags).
     */
    public function scopeCompositionTaggedOnly(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where(function ($q) {
            $q->where('metadata->composition_wip', true)
                ->orWhere('metadata->composition_preview', true);
        });
    }

    /**
     * Scope: assets linked to a composition via metadata (layers, parts) but not canvas WIP/preview export rows.
     */
    public function scopeCompositionLayersOnly(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->whereNotNull('metadata')
            ->where(function ($q) {
                $q->whereNotNull('metadata->composition_id')
                    ->where('metadata->composition_id', '!=', '');
            })
            ->whereNot(function ($q) {
                $q->where('metadata->composition_wip', true)
                    ->orWhere('metadata->composition_preview', true);
            });
    }

    /**
     * Scope: main-library rows that lack category_id (standard assets + executions only).
     * Excludes generative, reference, and composition-tagged assets — they are not expected to use the same grid category model.
     */
    public function scopeMissingCategoryForGridLibrary(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->whereNotNull('metadata')
            ->whereNull('deleted_at')
            ->whereIn('type', [AssetType::ASSET, AssetType::DELIVERABLE])
            ->excludeCompositionTagged()
            ->whereRaw('(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) = "" OR JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) = "null")');
    }

    /**
     * Scope: only staged assets (no category yet, shown on /assets/staged).
     */
    public function scopeStagedOnly(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where('intake_state', 'staged');
    }

    /**
     * Scope: only normal intake assets (have category, shown in main grid).
     */
    public function scopeNormalIntakeOnly(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where(function ($q) {
            $q->where('intake_state', 'normal')->orWhereNull('intake_state');
        });
    }

    /**
     * Scope: assets rejected in the publication workflow for a given uploader (creator action: replace or delete).
     */
    public function scopeRejectedPublicationForUploader(
        \Illuminate\Database\Eloquent\Builder $query,
        User $user,
        Tenant $tenant,
        Brand $brand
    ): void {
        $query->normalIntakeOnly()
            ->excludeBuilderStaged()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('type', AssetType::ASSET)
            ->where('user_id', $user->id)
            ->where('approval_status', ApprovalStatus::REJECTED)
            ->whereNull('deleted_at');
    }

    /**
     * Scope: only builder-staged assets (Brand Guidelines reference materials).
     *
     * @deprecated Prefer scopeReferenceMaterialsOnly for new code.
     */
    public function scopeBuilderStagedOnly(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where('builder_staged', true);
    }

    /**
     * Scope: reference materials (type=REFERENCE or legacy builder_staged).
     * Used for Brand Builder PDFs, screenshots, ads, packaging.
     */
    public function scopeReferenceMaterialsOnly(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where(function ($q) {
            $q->where('type', AssetType::REFERENCE)
                ->orWhere('builder_staged', true);
        });
    }

    /**
     * Scope: assets that support thumbnail-derived metadata (hasRasterThumbnail + thumbnail_status=completed + medium path).
     * Matches supportsThumbnailMetadata() for SQL-based integrity metrics.
     */
    public function scopeWhereSupportsThumbnailMetadata(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where('thumbnail_status', ThumbnailStatus::COMPLETED)
            ->where(function ($q) {
                $q->whereNotNull('metadata->thumbnails->'.ThumbnailMetadata::DEFAULT_MODE.'->medium->path')
                    ->orWhereNotNull('metadata->thumbnails->medium->path');
            })
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
        $mode = ThumbnailMetadata::DEFAULT_MODE;
        $query->where(function ($q) use ($mode) {
            $q->where('metadata->thumbnail_timeout', true)
                ->orWhere(function ($q2) use ($mode) {
                    $q2->whereNull('metadata->thumbnail_dimensions->'.$mode.'->medium')
                        ->whereNull('metadata->thumbnail_dimensions->medium');
                })
                ->orWhereRaw('COALESCE(
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.thumbnail_dimensions.'.$mode.'.medium.width")) AS UNSIGNED),
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.thumbnail_dimensions.medium.width")) AS UNSIGNED),
                    0) <= 0')
                ->orWhereRaw('COALESCE(
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.thumbnail_dimensions.'.$mode.'.medium.height")) AS UNSIGNED),
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.thumbnail_dimensions.medium.height")) AS UNSIGNED),
                    0) <= 0');
        });
    }

    /**
     * Check if asset type produces a raster thumbnail (image, SVG, PDF, video, AI/EPS).
     *
     * Used for capability-based logic: orientation, resolution_class, dominant_colors
     * can be derived from thumbnails for these types. Does NOT treat PDF/video as true images.
     * AI/EPS (Illustrator, Encapsulated PostScript) produce raster thumbnails via Imagick.
     */
    public function hasRasterThumbnail(): bool
    {
        $fileTypeService = app(\App\Services\FileTypeService::class);
        $fileType = $fileTypeService->detectFileTypeFromAsset($this);

        return in_array($fileType, ['image', 'tiff', 'cr2', 'avif', 'svg', 'pdf', 'video', 'ai'], true);
    }

    /**
     * Check if asset supports thumbnail-derived metadata (orientation, resolution_class, dominant_colors).
     *
     * Requires: hasRasterThumbnail() AND thumbnail_status === COMPLETED AND medium thumbnail path exists.
     * Used by ComputedMetadataService, ColorAnalysisService, DominantColorsExtractor.
     */
    public function supportsThumbnailMetadata(): bool
    {
        return $this->hasRasterThumbnail()
            && $this->thumbnail_status === ThumbnailStatus::COMPLETED
            && ThumbnailMetadata::stylePath($this->metadata ?? [], 'medium') !== null;
    }

    /**
     * Get persisted thumbnail dimensions for a style (from metadata, no S3 download).
     *
     * @param  string  $style  Thumbnail style (e.g. 'medium')
     * @return array{width: int|null, height: int|null}|null
     */
    public function thumbnailDimensions(string $style = 'medium'): ?array
    {
        $dimensions = ThumbnailMetadata::dimensionsForStyle($this->metadata ?? [], $style);

        // Fallback: check current version metadata (version-aware uploads)
        if ((! is_array($dimensions) || ! isset($dimensions['width'], $dimensions['height'])) && $this->relationLoaded('currentVersion')) {
            $version = $this->currentVersion;
            if ($version) {
                $dimensions = ThumbnailMetadata::dimensionsForStyle($version->metadata ?? [], $style);
            }
        }

        if (! is_array($dimensions) || ! isset($dimensions['width'], $dimensions['height'])) {
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
     */
    public function visualMetadataReady(): bool
    {
        if (! $this->supportsThumbnailMetadata()) {
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
     * @param  string|null  $worstIncidentSeverity  'critical'|'error'|'warning' from unresolved incidents
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

        $meta = $this->metadata ?? [];
        // Legacy: exhausted preview retries may still show thumbnail_status=failed before backfill
        if ($ts === 'failed' && ! empty($meta['pipeline_preview_exhausted_at'])) {
            $ts = 'skipped';
        }

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
     * Thumbnail paths are stored as: metadata['thumbnails'][mode][style]['path'] (default mode {@see ThumbnailMetadata::DEFAULT_MODE})
     * with legacy fallback: metadata['thumbnails'][style]['path'].
     *
     * @param  string  $style  Thumbnail style (thumb, medium, large)
     * @return string|null S3 key path to thumbnail, or null if not found
     */
    public function thumbnailPathForStyle(string $style, ?string $mode = null): ?string
    {
        $metadata = $this->metadata ?? [];

        $path = ThumbnailMetadata::stylePath($metadata, $style, $mode);
        if ($path !== null) {
            return $path;
        }

        // Fallback: check current version metadata (version-aware uploads store thumbnails on version first)
        $version = $this->relationLoaded('currentVersion')
            ? $this->currentVersion
            : $this->currentVersion()->first();
        if ($version) {
            $path = ThumbnailMetadata::stylePath($version->metadata ?? [], $style, $mode);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Request full PDF page extraction (background).
     */
    public function requestFullPdfExtraction(?string $requestedBy = null): bool
    {
        $mime = strtolower((string) ($this->mime_type ?? ''));
        $extension = strtolower(pathinfo((string) ($this->original_filename ?? ''), PATHINFO_EXTENSION));
        $version = $this->relationLoaded('currentVersion')
            ? $this->currentVersion
            : $this->currentVersion()->first();
        $pathExtension = $version?->file_path
            ? strtolower(pathinfo($version->file_path, PATHINFO_EXTENSION))
            : '';
        $isPdf = str_contains($mime, 'pdf') || $extension === 'pdf' || $pathExtension === 'pdf';
        if (! $isPdf) {
            return false;
        }

        $metadata = $this->metadata ?? [];
        $metadata['pdf_full_extraction_requested'] = true;
        $metadata['pdf_full_extraction_requested_at'] = now()->toIso8601String();
        if ($requestedBy) {
            $metadata['pdf_full_extraction_requested_by'] = $requestedBy;
        }

        $this->forceFill([
            'metadata' => $metadata,
            'pdf_pages_rendered' => false,
        ])->save();

        FullPdfExtractionJob::dispatch($this->id, $version?->id);

        return true;
    }
}
