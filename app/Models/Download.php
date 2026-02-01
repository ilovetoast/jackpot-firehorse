<?php

namespace App\Models;

use App\Enums\DownloadAccessMode;
use App\Enums\DownloadSource;
use App\Enums\DownloadStatus;
use App\Enums\DownloadType;
use App\Enums\ZipStatus;
use App\Services\DownloadEventEmitter;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'zip_path',
        'zip_size_bytes',
        'expires_at',
        'hard_delete_at',
        'revoked_at', // Phase D2: when set, download is inaccessible
        'revoked_by_user_id',
        'download_options',
        'access_mode',
        'allow_reshare',
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
            'access_mode' => DownloadAccessMode::class,
            'expires_at' => 'datetime',
            'hard_delete_at' => 'datetime',
            'revoked_at' => 'datetime',
            'download_options' => 'array',
            'allow_reshare' => 'boolean',
            'version' => 'integer',
            'zip_size_bytes' => 'integer',
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
     * Phase D2: Get users allowed to access (when access_mode is users).
     */
    public function allowedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'download_user')
            ->withTimestamps();
    }

    /**
     * Phase D2: Check if download is revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
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
