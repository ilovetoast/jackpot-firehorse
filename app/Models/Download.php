<?php

namespace App\Models;

use App\Enums\DownloadAccessMode;
use App\Enums\DownloadSource;
use App\Enums\DownloadStatus;
use App\Enums\DownloadType;
use App\Enums\DownloadZipFailureReason;
use App\Enums\ZipStatus;
use App\Services\DownloadEventEmitter;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 🔒 Phase 3.1 — Downloader System (LOCKED)
 * 
 * Do not refactor or change behavior.
 * Future phases may consume outputs only.
 * 
 * Download Group Model
 * 
 * Represents a group of assets available for download as a ZIP file.
 * Supports both snapshot (immutable) and living (mutable) download types.
 * 
 * Lifecycle:
 * - Created with pending status
 * - Becomes ready when assets are attached
 * - ZIP generation can be triggered (separate phase)
 * - Soft deleted when user removes download
 * - Hard deleted based on plan rules and expiration
 * 
 * Deletion Strategy:
 * - Soft delete (deleted_at): User removes download, ZIP remains until cleanup
 * - Hard delete (hard_delete_at): Calculated at creation based on plan + type
 * - Lifecycle job (future phase) will delete ZIP from S3 when hard_delete_at reached
 * 
 * Analytics Support:
 * - version: Tracks regeneration/invalidation events
 * - source: Tracks where download was initiated
 * - download_type: Distinguishes snapshot vs living
 * - Asset relationships: Enable per-asset download count tracking
 */
class Download extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'brand_id', // Phase D1: nullable for collection-only users
        'created_by_user_id',
        'download_type',
        'source',
        'title',
        'slug',
        'version',
        'status',
        'zip_status',
        'zip_build_started_at',    // D9.2: observability only
        'zip_build_completed_at',  // D9.2: observability only
        'zip_build_duration_seconds', // D-UX: persisted for time estimate messaging
        'zip_build_failed_at',     // D9.2: observability only
        'zip_path',
        'access_count',        // Number of times this download was delivered (ZIP or single-asset)
        'direct_asset_path',   // UX-R2: S3 key for single-asset download (no ZIP)
        'zip_deleted_at',      // Phase D5: when artifact was deleted from storage
        'cleanup_verified_at',  // Phase D5: when we confirmed file absence
        'cleanup_failed_at',   // Phase D5: when verification failed
        'zip_size_bytes',
        'expires_at',
        'hard_delete_at',
        'revoked_at', // Phase D2: when set, download is inaccessible
        'revoked_by_user_id',
        'download_options',
        'access_mode',
        'allow_reshare',
        'password_hash',   // D7: bcrypt hash for public download link (optional)
        'branding_options', // D7: legacy; R3.1+ use landing_copy + brand settings
        'uses_landing_page', // R3.1: when true, show landing page; when false, go straight to ZIP
        'landing_copy',     // R3.1: JSON { headline?, subtext? } copy overrides
        'failure_reason',   // ZIP build failure classification
        'failure_count',    // Increment on each failure
        'last_failed_at',   // Timestamp of last failure
        'zip_build_chunk_index', // For resumable chunked ZIP creation
        'zip_total_chunks',      // D-Progress: total chunks when build started (observability)
        'zip_last_progress_at',  // D-Progress: last chunk completion timestamp (heartbeat)
        'escalation_ticket_id',  // Ticket created for failure escalation
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'download_type' => DownloadType::class,
            'source' => DownloadSource::class,
            'status' => DownloadStatus::class,
            'zip_status' => ZipStatus::class,
            'zip_build_started_at' => 'datetime',   // D9.2: observability only
            'zip_build_completed_at' => 'datetime', // D9.2: observability only
            'zip_build_failed_at' => 'datetime',    // D9.2: observability only
            'access_mode' => DownloadAccessMode::class,
            'expires_at' => 'datetime',
            'hard_delete_at' => 'datetime',
            'zip_deleted_at' => 'datetime',     // Phase D5
            'cleanup_verified_at' => 'datetime', // Phase D5
            'cleanup_failed_at' => 'datetime',  // Phase D5
            'revoked_at' => 'datetime',
            'download_options' => 'array',
            'allow_reshare' => 'boolean',
            'branding_options' => 'array', // D7: legacy
            'uses_landing_page' => 'boolean', // R3.1
            'landing_copy' => 'array',       // R3.1: headline, subtext
            'version' => 'integer',
            'zip_size_bytes' => 'integer',
            'access_count' => 'integer',
            'failure_reason' => DownloadZipFailureReason::class,
            'failure_count' => 'integer',
            'last_failed_at' => 'datetime',
            'zip_build_chunk_index' => 'integer',
            'zip_total_chunks' => 'integer',
            'zip_last_progress_at' => 'datetime',
        ];
    }

    /**
     * Get the tenant that owns this download.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the brand (nullable for collection-only users). Phase D1.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Brand::class);
    }

    /**
     * Get the user who created this download.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the user who revoked this download (Phase D2).
     */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    /**
     * Landing page view events (download.landing.page.viewed).
     */
    public function landingPageViewEvents(): MorphMany
    {
        return $this->morphMany(ActivityEvent::class, 'subject')
            ->where('event_type', 'download.landing.page.viewed');
    }

    /**
     * Phase D2: Get users allowed to access (when access_mode is users).
     */
    public function allowedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'download_user');
    }

    /**
     * Phase D2: Check if download is revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Canonical rule: whether this download requires a password to access.
     * True when password_hash is set (non-null, non-empty).
     */
    public function requiresPassword(): bool
    {
        return ! empty($this->password_hash);
    }

    /**
     * Canonical rule: landing page is required IF AND ONLY IF the download requires a password.
     * Password protection implicitly requires a landing page for entry. No other conditions may force a landing page.
     * Single source of truth for backend logic that depends on "must show landing page".
     *
     * Why landing pages are not policy-controlled: Landing pages are a UX/presentation concern (where to collect
     * password, show copy), not a delivery policy. We do not allow company-level or per-download toggles to "force"
     * a landing page—that would duplicate policy surface and create ambiguity. Only password requirement forces
     * a landing page. This is intentional design, not a shortcut.
     */
    public function isLandingPageRequired(): bool
    {
        return $this->requiresPassword();
    }

    /**
     * Whether brand-based access restriction is allowed for this download.
     * Hard constraint: only when all assets are from a single brand (getDistinctAssetBrandCount() === 1).
     *
     * Why multi-brand downloads cannot be brand-restricted: Restricting access "by brand" only makes sense when
     * there is exactly one brand in the download. With multiple brands we would have to guess which brand to
     * enforce (heuristic) or expose ambiguous UI. We disallow brand-based access for multi-brand downloads
     * everywhere (create, change access, settings). No heuristic brand selection; intentional design.
     */
    public function canRestrictToBrand(): bool
    {
        return $this->getDistinctAssetBrandCount() === 1;
    }

    /**
     * Number of distinct brands among this download's assets.
     * Used for landing page template selection: brand template only when count === 1 and brand has it enabled.
     */
    public function getDistinctAssetBrandCount(): int
    {
        return (int) $this->assets()->selectRaw('count(distinct assets.brand_id) as c')->value('c');
    }

    /**
     * Brand to use for the download landing page template, or null for default Jackpot template.
     * Only applies when a landing page is required (e.g. password-protected). No brand guessing; no fallbacks beyond default.
     * Rules: if landing page required and asset_brand_count === 1 and that brand has download_landing_page enabled → that brand; else null.
     *
     * Why multi-brand downloads cannot be branded: With assets from more than one brand we cannot pick a single
     * template without heuristics (e.g. "majority brand" or "primary asset brand"). We never guess—multi-brand
     * downloads always use the default Jackpot template. Intentional design; ensures deterministic, consistent behavior.
     */
    public function getLandingPageTemplateBrand(): ?Brand
    {
        if (! $this->isLandingPageRequired()) {
            return null;
        }
        if ($this->getDistinctAssetBrandCount() !== 1) {
            return null;
        }
        $brandId = $this->assets()->select('assets.brand_id')->distinct()->value('brand_id');
        if ($brandId === null) {
            return null;
        }
        $brand = Brand::find($brandId);
        if ($brand === null || ! $brand->isDownloadLandingPageEnabled()) {
            return null;
        }

        return $brand;
    }

    /**
     * Phase D4: Check if ZIP build failed (derived from zip_status, no new column).
     */
    public function hasFailed(): bool
    {
        return $this->zip_status === ZipStatus::FAILED;
    }

    /**
     * Phase D4: Check if download is ready (ZIP or single-asset file exists and not expired/revoked).
     * UX-R2: Single-asset downloads are ready when direct_asset_path is set.
     */
    public function isReady(): bool
    {
        if ($this->isExpired() || $this->isRevoked()) {
            return false;
        }
        return $this->hasZip() || ! empty($this->direct_asset_path);
    }

    /**
     * Phase D4: Check if download is processing (ZIP being built, not failed/expired/revoked).
     * UX-R2: Single-asset downloads are never "processing" (no ZIP build).
     */
    public function isProcessing(): bool
    {
        if ($this->isRevoked() || $this->isExpired() || $this->hasFailed()) {
            return false;
        }
        if (! empty($this->direct_asset_path)) {
            return false;
        }
        $noZipYet = empty($this->zip_path) || $this->zip_status === ZipStatus::NONE || $this->zip_status === ZipStatus::BUILDING;

        return $noZipYet;
    }

    /**
     * Phase D4: Detect if a "Preparing" download may have failed (stuck).
     * True when processing and either: job started >20 min ago, or created >25 min ago with no start.
     * Job timeout is 15 min; backoff can delay retries. Used for UX messaging only.
     */
    public function isPossiblyStuck(): bool
    {
        if (! $this->isProcessing()) {
            return false;
        }
        $buildStarted = $this->zip_build_started_at;
        $createdAt = $this->created_at ?? now();

        if ($buildStarted && $buildStarted->diffInMinutes(now(), false) > 20) {
            return true;
        }
        if (! $buildStarted && $createdAt->diffInMinutes(now(), false) > 25) {
            return true;
        }

        return false;
    }

    /**
     * Phase D-Progress: Get ZIP build progress percentage (observability only).
     * Returns null if zip_total_chunks is missing; else round((chunk_index / total_chunks) * 100).
     */
    public function getZipProgressPercentage(): ?int
    {
        $total = $this->zip_total_chunks;
        if ($total === null || $total <= 0) {
            return null;
        }
        $index = (int) ($this->zip_build_chunk_index ?? 0);

        return (int) round(($index / $total) * 100);
    }

    /**
     * Phase D-Progress: True if download is preparing and no chunk progress for longer than threshold (observability only).
     * Used for UI messaging: "This download is taking longer than usual."
     *
     * @param int $seconds Threshold in seconds (default 180)
     */
    public function isZipStalled(int $seconds = 180): bool
    {
        if (! $this->isProcessing()) {
            return false;
        }
        $lastProgress = $this->zip_last_progress_at;
        if (! $lastProgress) {
            return false;
        }

        return $lastProgress->diffInSeconds(now(), false) > $seconds;
    }

    /**
     * Phase D4: Get derived UI state (processing|ready|expired|revoked|failed). Not stored.
     */
    public function getState(): string
    {
        if ($this->isRevoked()) {
            return 'revoked';
        }
        if ($this->isExpired()) {
            return 'expired';
        }
        if ($this->hasFailed()) {
            return 'failed';
        }
        if ($this->isReady()) {
            return 'ready';
        }
        if ($this->isProcessing()) {
            return 'processing';
        }

        return 'processing';
    }

    /**
     * Get the assets in this download group.
     * 
     * For snapshot downloads, this list is immutable after creation.
     * For living downloads, assets can be added or removed.
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'download_asset')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /**
     * Get the primary asset (first asset) for this download.
     * Used for thumbnails and previews.
     */
    public function primaryAsset(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'download_asset')
            ->wherePivot('is_primary', true)
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /**
     * Check if this is a snapshot download.
     */
    public function isSnapshot(): bool
    {
        return $this->download_type === DownloadType::SNAPSHOT;
    }

    /**
     * Check if this is a living download.
     */
    public function isLiving(): bool
    {
        return $this->download_type === DownloadType::LIVING;
    }

    /**
     * Check if the download has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Check if the download is ready for hard delete.
     */
    public function isReadyForHardDelete(): bool
    {
        return $this->hard_delete_at && $this->hard_delete_at->isPast();
    }

    /**
     * Check if ZIP file exists.
     */
    public function hasZip(): bool
    {
        return $this->zip_status === ZipStatus::READY && !empty($this->zip_path);
    }

    /**
     * Phase D5: Check if download still has an artifact in storage (ZIP path set and not yet deleted).
     */
    public function hasArtifact(): bool
    {
        return !empty($this->zip_path) && $this->zip_deleted_at === null;
    }

    /**
     * Phase D5: Storage duration in seconds (created_at → zip_deleted_at or now if not yet deleted).
     * For metrics only; does not affect plan limits.
     */
    public function storageDurationSeconds(): ?int
    {
        $end = $this->zip_deleted_at ?? now();
        return $this->created_at->diffInSeconds($end);
    }

    /**
     * Phase D5: Total bytes of artifact (alias for zip_size_bytes). For metrics.
     */
    public function totalBytes(): ?int
    {
        return $this->zip_size_bytes;
    }

    /**
     * Phase D9.2: ZIP build duration in milliseconds (derived from timestamps, never persisted).
     * Returns null if started or completed timestamp is missing.
     */
    public function zipBuildDurationMs(): ?int
    {
        if (! $this->zip_build_started_at || ! $this->zip_build_completed_at) {
            return null;
        }

        return (int) $this->zip_build_started_at->diffInMilliseconds($this->zip_build_completed_at);
    }

    /**
     * Phase 3.1 Step 2: Check if ZIP needs regeneration.
     * 
     * Pure helper method - no side effects, no DB writes.
     * 
     * ZIP needs regeneration if:
     * - ZIP status is INVALIDATED
     * - Download is living and assets changed (detected via relationship changes)
     * 
     * Note: Asset changes are detected via relationship, not dirty attribute.
     * This method checks current state, not changes.
     */
    public function zipNeedsRegeneration(): bool
    {
        // ZIP status is explicitly invalidated
        if ($this->zip_status === ZipStatus::INVALIDATED) {
            return true;
        }

        // Living downloads with ready ZIPs may need regeneration if assets changed
        // (This would be checked when assets are actually modified)
        return false;
    }

    /**
     * Calculate hard_delete_at based on plan rules and download type.
     * 
     * Phase 3.1 Step 2: Delegates to DownloadExpirationPolicy service.
     * This is a design placeholder - actual calculation will be implemented
     * in DownloadExpirationPolicy in a future phase.
     * 
     * @return \Carbon\Carbon|null
     */
    public function calculateHardDeleteAt(): ?\Carbon\Carbon
    {
        // TODO: Implement in DownloadExpirationPolicy (future phase)
        $policy = app(\App\Services\DownloadExpirationPolicy::class);
        return $policy->calculateHardDeleteAt($this, $this->expires_at);
    }

    /**
     * Phase 3.1 Step 2: Check if download should expire based on plan rules.
     * 
     * Pure helper method - no side effects, no DB writes.
     * 
     * Snapshot downloads: Should expire (except top-tier overrides)
     * Living downloads: May not expire on top tiers
     * 
     * @return bool True if download should have expiration
     */
    public function shouldExpire(): bool
    {
        // If expires_at is already set, then yes, it should expire
        if ($this->expires_at) {
            return true;
        }

        // Check policy to see if expiration is required
        $policy = app(\App\Services\DownloadExpirationPolicy::class);
        return $policy->isExpiresAtRequired($this->tenant, $this->download_type);
    }

    /**
     * Phase 3.1 Step 2: Check if download should be hard deleted.
     * 
     * Pure helper method - no side effects, no DB writes.
     * 
     * Returns true if:
     * - hard_delete_at is set and in the past
     * - OR download is soft-deleted and past grace period
     * 
     * @return bool True if download should be hard deleted
     */
    public function shouldHardDelete(): bool
    {
        // Check hard_delete_at timestamp
        if ($this->hard_delete_at && $this->hard_delete_at->isPast()) {
            return true;
        }

        // If soft-deleted and past grace period, should be hard deleted
        if ($this->trashed() && $this->expires_at && $this->expires_at->isPast()) {
            // TODO: Check grace window via policy
            // For now, if expired and soft-deleted, ready for hard delete
            return true;
        }

        return false;
    }

    /**
     * Phase 3.1 Step 2: Check if ZIP can be regenerated.
     * 
     * Pure helper method - no side effects, no DB writes.
     * 
     * ZIP can be regenerated if:
     * - ZIP status is INVALIDATED
     * - Download is living and assets changed
     * - ZIP status is FAILED
     * 
     * ZIP cannot be regenerated if:
     * - Download is snapshot and ZIP is READY (immutable)
     * - Download status is FAILED
     * 
     * @return bool True if ZIP can be regenerated
     */
    public function canRegenerateZip(): bool
    {
        // If download itself failed, cannot regenerate ZIP
        if ($this->status === \App\Enums\DownloadStatus::FAILED) {
            return false;
        }

        // Regenerate guardrail: max 3 failures, then escalated to support
        if (($this->failure_count ?? 0) >= 3 || $this->escalation_ticket_id) {
            return false;
        }

        // Snapshot downloads with READY ZIP are immutable
        if ($this->isSnapshot() && $this->zip_status === \App\Enums\ZipStatus::READY) {
            return false;
        }

        // Can regenerate if ZIP is invalidated or failed
        if ($this->zip_status === \App\Enums\ZipStatus::INVALIDATED
            || $this->zip_status === \App\Enums\ZipStatus::FAILED) {
            return true;
        }

        // Living downloads can always regenerate if needed
        if ($this->isLiving()) {
            return true;
        }

        return false;
    }

    /**
     * Whether this download has been escalated to support (regenerate disabled).
     */
    public function isEscalatedToSupport(): bool
    {
        return ($this->failure_count ?? 0) >= 3 || $this->escalation_ticket_id !== null;
    }

    /**
     * Record ZIP build failure: increments failure_count, sets failure_reason, last_failed_at,
     * stores exception trace in download_options['zip_failure_trace'].
     */
    public function recordFailure(\Throwable $e, DownloadZipFailureReason $reason): void
    {
        $options = array_merge($this->download_options ?? [], [
            'zip_failure_trace' => substr($e->getTraceAsString(), 0, 5000),
        ]);

        $this->forceFill([
            'zip_build_failed_at' => now(),
            'failure_reason' => $reason,
            'failure_count' => ($this->failure_count ?? 0) + 1,
            'last_failed_at' => now(),
            'zip_status' => ZipStatus::FAILED,
            'download_options' => $options,
        ])->saveQuietly();
    }

    /**
     * Phase 3.1 Step 2: Check if ZIP should be invalidated on asset change.
     * 
     * Pure helper method - no side effects, no DB writes.
     * 
     * ZIP should be invalidated if:
     * - Download is living (mutable asset list)
     * - ZIP status is READY
     * 
     * ZIP should NOT be invalidated if:
     * - Download is snapshot (immutable asset list)
     * - ZIP status is already INVALIDATED or FAILED
     * 
     * @return bool True if ZIP should be invalidated on asset change
     */
    public function shouldInvalidateZipOnAssetChange(): bool
    {
        // Snapshot downloads are immutable - asset changes shouldn't invalidate ZIP
        // (though they also shouldn't allow asset changes)
        if ($this->isSnapshot()) {
            return false;
        }

        // Living downloads: invalidate ZIP if it exists and is ready
        if ($this->isLiving() && $this->zip_status === \App\Enums\ZipStatus::READY) {
            return true;
        }

        return false;
    }

    /**
     * Scope: Only snapshot downloads.
     */
    public function scopeSnapshots($query)
    {
        return $query->where('download_type', DownloadType::SNAPSHOT->value);
    }

    /**
     * Scope: Only living downloads.
     */
    public function scopeLiving($query)
    {
        return $query->where('download_type', DownloadType::LIVING->value);
    }

    /**
     * Scope: Ready for hard delete.
     */
    public function scopeReadyForHardDelete($query)
    {
        return $query->whereNotNull('hard_delete_at')
            ->where('hard_delete_at', '<=', now());
    }

    /**
     * Scope: Expired downloads.
     */
    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /**
     * Scope: Downloads with ready ZIP files.
     */
    public function scopeWithReadyZip($query)
    {
        return $query->where('zip_status', ZipStatus::READY->value);
    }

    /**
     * Scope: Downloads needing ZIP regeneration.
     */
    public function scopeNeedsZipRegeneration($query)
    {
        return $query->where('zip_status', ZipStatus::INVALIDATED->value);
    }

    /**
     * Phase 3.1 Step 3: Invalidate ZIP when assets change (living downloads only).
     * 
     * This method should be called when assets are added or removed from a living download.
     * For snapshot downloads, this is a no-op (asset list is immutable).
     * 
     * Side effects:
     * - Updates zip_status to INVALIDATED if conditions are met
     * - Increments version to track asset list changes
     * - Saves the model
     * 
     * @return bool True if ZIP was invalidated, false otherwise
     */
    public function invalidateZipIfNeeded(): bool
    {
        // Snapshot downloads have immutable asset lists - ZIP should not be invalidated
        // (though assets shouldn't change for snapshots in the first place)
        if ($this->isSnapshot()) {
            return false;
        }

        // Only invalidate if ZIP is currently READY
        // (don't invalidate if already INVALIDATED, BUILDING, or FAILED)
        if ($this->zip_status !== ZipStatus::READY) {
            return false;
        }

        // Invalidate ZIP
        $this->zip_status = ZipStatus::INVALIDATED;
        
        // Increment version to track asset list changes
        $this->version = ($this->version ?? 1) + 1;
        
        $this->save();

        // Phase 3.1 Step 5: Emit download group invalidated event
        DownloadEventEmitter::emitDownloadGroupInvalidated($this);

        \Illuminate\Support\Facades\Log::info('[Download] ZIP invalidated due to asset change', [
            'download_id' => $this->id,
            'download_type' => $this->download_type->value,
            'old_version' => $this->version - 1,
            'new_version' => $this->version,
        ]);

        return true;
    }
}
