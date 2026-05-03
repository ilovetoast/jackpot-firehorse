<?php

namespace App\Services;

use App\Models\Collection;
use App\Models\Tenant;

/**
 * Guest (no-login) access for password-protected collection share links.
 * Session key is scoped per collection id.
 */
class CollectionPublicShareGuestAccess
{
    public function __construct(
        protected FeatureGate $featureGate
    ) {}

    public function tenantAllowsShareLinks(?Tenant $tenant): bool
    {
        return $tenant && $this->featureGate->publicCollectionsEnabled($tenant);
    }

    /**
     * Collection may appear on a share URL (feature + is_public). Does not require password or unlock.
     */
    public function collectionEligibleForShareRoute(Collection $collection): bool
    {
        $tenant = $collection->tenant;

        return $tenant
            && $collection->is_public
            && $this->tenantAllowsShareLinks($tenant);
    }

    /**
     * Whether anonymous visitors may see assets / thumbnails (unlocked + password set).
     * Legacy is_public without password: not viewable until owner sets a password (V1 safety).
     */
    public function guestMayViewUnlockedContent(Collection $collection): bool
    {
        if (! $this->collectionEligibleForShareRoute($collection)) {
            return false;
        }

        if (! $collection->hasPublicPassword()) {
            return false;
        }

        return $collection->isUnlockedInShareSession();
    }

    public function shouldShowPasswordGate(Collection $collection): bool
    {
        if (! $this->collectionEligibleForShareRoute($collection)) {
            return false;
        }

        if (! $collection->hasPublicPassword()) {
            return false;
        }

        return ! $collection->isUnlockedInShareSession();
    }

    public function unlock(Collection $collection, string $plainPassword): bool
    {
        if (! $collection->hasPublicPassword()) {
            return false;
        }

        if (! \Illuminate\Support\Facades\Hash::check($plainPassword, $collection->public_password_hash)) {
            return false;
        }

        session()->put($collection->sessionUnlockKey(), true);

        return true;
    }

    public function lock(Collection $collection): void
    {
        session()->forget($collection->sessionUnlockKey());
    }

    public function guestMayUseDownloads(Collection $collection): bool
    {
        if (! $this->guestMayViewUnlockedContent($collection)) {
            return false;
        }

        return (bool) ($collection->public_downloads_enabled ?? true);
    }

    /**
     * Tenant-level plan gate for ZIP / bulk public downloads (see config/plans.php per plan).
     */
    public function tenantAllowsPublicCollectionDownloads(?Tenant $tenant): bool
    {
        return $tenant && $this->featureGate->publicCollectionDownloadsEnabled($tenant);
    }
}
