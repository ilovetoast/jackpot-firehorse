<?php

namespace App\Services;

use App\Enums\DownloadType;
use App\Models\Download;
use App\Models\Tenant;
use Carbon\Carbon;

/**
 * 🔒 Phase 3.1 — Downloader System (LOCKED)
 * 
 * Defines authoritative rules for download expiration and hard deletion.
 * This service contains DESIGN/STUB methods only — no actual implementation yet.
 * 
 * Do not refactor or change behavior.
 * Future phases may consume outputs only.
 * 
 * Responsibilities (documented, not executed):
 * - Determine expires_at based on plan and download_type
 * - Determine hard_delete_at based on expires_at and grace windows
 * - Define grace windows per plan tier
 * - Handle unlimited expiration for enterprise plans
 * 
 * Lifecycle Rules:
 * 
 * A. Snapshot Downloads
 * - expires_at is required (except top-tier overrides)
 * - hard_delete_at = expires_at + grace window
 * - ZIP is immutable once READY
 * 
 * B. Living Downloads
 * - expires_at may be null (top tiers)
 * - ZIP is invalidated on asset change
 * - hard_delete_at only set on soft delete
 */
class DownloadExpirationPolicy
{
    /**
     * Calculate expires_at for a download based on plan and type.
     * 
     * DESIGN ONLY — Returns null as placeholder.
     * 
     * Expected behavior (to be implemented in future phase):
     * 
     * Snapshot Downloads:
     * - Free: expires_at = now + 7 days
     * - Pro: expires_at = now + 30 days
     * - Enterprise: expires_at = null (unlimited) OR now + 365 days
     * 
     * Living Downloads:
     * - Free: expires_at = now + 7 days
     * - Pro: expires_at = now + 30 days
     * - Enterprise: expires_at = null (unlimited)
     * 
     * @param Tenant $tenant The tenant/company
     * @param DownloadType $downloadType Snapshot or living
     * @return Carbon|null Expiration timestamp or null for unlimited
     */
    public function calculateExpiresAt(Tenant $tenant, DownloadType $downloadType): ?Carbon
    {
        // TODO: Implement in future phase
        // This method will:
        // 1. Get tenant plan via PlanService
        // 2. Look up expiration rules from config or database
        // 3. Apply plan-specific expiration periods
        // 4. Return calculated expiration timestamp
        return null;
    }

    /**
     * Calculate hard_delete_at for a download.
     * 
     * DESIGN ONLY — Returns null as placeholder.
     * 
     * Expected behavior (to be implemented in future phase):
     * 
     * If expires_at is set:
     * - hard_delete_at = expires_at + grace_window
     * - Grace window: Free (3 days), Pro (7 days), Enterprise (14 days)
     * 
     * If expires_at is null (unlimited):
     * - hard_delete_at = null (manual deletion only)
     * 
     * Special case for soft-deleted downloads:
     * - If download is soft-deleted and expires_at is null:
     *   - hard_delete_at = deleted_at + grace_window
     * 
     * @param Download $download The download to calculate for
     * @param Carbon|null $expiresAt The expiration timestamp (if null, download is unlimited)
     * @return Carbon|null Hard delete timestamp or null for unlimited
     */
    public function calculateHardDeleteAt(Download $download, ?Carbon $expiresAt): ?Carbon
    {
        // TODO: Implement in future phase
        // This method will:
        // 1. If expires_at is null: return null (unlimited, manual deletion only)
        // 2. If expires_at is set: calculate grace window based on plan
        // 3. Return expires_at + grace_window
        // 4. Handle soft-deleted downloads with null expires_at
        return null;
    }

    /**
     * Get grace window in days for a tenant's plan.
     * 
     * DESIGN ONLY — Returns placeholder value.
     * 
     * Expected grace windows:
     * - Free: 3 days
     * - Pro: 7 days
     * - Enterprise: 14 days
     * 
     * @param Tenant $tenant The tenant/company
     * @return int Grace window in days
     */
    public function getGraceWindowDays(Tenant $tenant): int
    {
        // TODO: Implement in future phase
        // This method will:
        // 1. Get tenant plan via PlanService
        // 2. Look up grace window from config or database
        // 3. Return grace window in days
        return 7; // Placeholder
    }

    /**
     * Check if a download should have unlimited expiration.
     * 
     * DESIGN ONLY — Returns false as placeholder.
     * 
     * Expected behavior:
     * - Enterprise plan: May allow unlimited expiration
     * - Other plans: Always have expiration
     * 
     * @param Tenant $tenant The tenant/company
     * @param DownloadType $downloadType Snapshot or living
     * @return bool True if download should have unlimited expiration
     */
    public function shouldBeUnlimited(Tenant $tenant, DownloadType $downloadType): bool
    {
        // TODO: Implement in future phase
        // This method will:
        // 1. Get tenant plan via PlanService
        // 2. Check if plan allows unlimited downloads
        // 3. Consider download_type (living downloads more likely to be unlimited)
        // 4. Return true if unlimited, false otherwise
        return false; // Placeholder
    }

    /**
     * Determine if expires_at is required for a download type and plan.
     * 
     * DESIGN ONLY — Returns true as placeholder.
     * 
     * Expected behavior:
     * - Snapshot downloads: expires_at usually required (except enterprise)
     * - Living downloads: expires_at optional for top tiers
     * 
     * @param Tenant $tenant The tenant/company
     * @param DownloadType $downloadType Snapshot or living
     * @return bool True if expires_at is required
     */
    public function isExpiresAtRequired(Tenant $tenant, DownloadType $downloadType): bool
    {
        // TODO: Implement in future phase
        // This method will:
        // 1. Get tenant plan via PlanService
        // 2. Check plan rules for expiration requirements
        // 3. Consider download_type
        // 4. Return true if expires_at is required
        return true; // Placeholder
    }
}
