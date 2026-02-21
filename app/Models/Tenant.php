<?php

namespace App\Models;

use App\Services\PlanService;
use App\Traits\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Cashier\Billable;

class Tenant extends Model
{
    use Billable, RecordsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'uuid', // Phase 5: Canonical storage path isolation (tenants/{uuid}/assets/...)
        'timezone',
        'plan_management_source',
        'manual_plan_override',
        'billing_status', // For accounting: null/'paid' (Stripe), 'trial', 'comped' (free account)
        'billing_status_expires_at', // Optional expiration for trial/comped accounts
        'equivalent_plan_value', // Sales insight only - NOT real revenue
        'settings', // Phase M-2: Company settings (JSON)
        'is_agency', // Phase AG-1: Agency identification
        'agency_tier_id', // Phase AG-1: Agency tier
        'agency_approved_at', // Phase AG-1: Agency approval timestamp
        'agency_approved_by', // Phase AG-1: Agency approval user ID
        'incubated_at', // Phase AG-2: Incubation start timestamp
        'incubation_expires_at', // Phase AG-2: Incubation expiration timestamp
        'incubated_by_agency_id', // Phase AG-2: Agency that incubated this tenant
        'activated_client_count', // Phase AG-4: Tier progress tracking
        'referred_by_agency_id', // Phase AG-10: Referral attribution
        'referral_source', // Phase AG-10: Referral source tracking
        'storage_addon_mb', // Storage add-on: additional MB from purchased add-on
        'storage_addon_stripe_price_id', // Stripe price ID for add-on subscription item
        'storage_addon_stripe_subscription_item_id', // Stripe subscription item ID
        'storage_mode', // Hybrid S3: 'shared' | 'dedicated'
        'storage_bucket', // Dedicated bucket name (Enterprise)
        'cdn_distribution_id', // CloudFront distribution (Enterprise)
        'infrastructure_tier', // 'shared' | 'dedicated' — decoupled from plan; local always shared
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_status_expires_at' => 'date',
            'equivalent_plan_value' => 'decimal:2',
            'settings' => 'array', // Phase M-2: Company settings (JSON)
            'is_agency' => 'boolean', // Phase AG-1: Agency identification
            'agency_approved_at' => 'datetime', // Phase AG-1: Agency approval timestamp
            'incubated_at' => 'datetime', // Phase AG-2: Incubation start timestamp
            'incubation_expires_at' => 'datetime', // Phase AG-2: Incubation expiration timestamp
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($tenant) {
            if (empty($tenant->uuid)) {
                $tenant->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });

        static::created(function ($tenant) {
            // Automatically create a default brand when tenant is created
            $defaultBrand = $tenant->brands()->create([
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'is_default' => true,
                'show_in_selector' => true, // Default to showing in selector
            ]);
            
            // If tenant already has an owner (e.g., created via seeder or admin), connect them to default brand
            $owner = $tenant->owner();
            if ($owner && $defaultBrand) {
                // Connect owner to default brand with admin role (owners can't have brand roles)
                $defaultBrand->users()->syncWithoutDetaching([
                    $owner->id => ['role' => 'admin']
                ]);
            }

            // Provision storage bucket (shared or dedicated) so uploads work immediately.
            // Runs in queue worker; for shared strategy creates StorageBucket record only.
            \App\Jobs\ProvisionCompanyStorageJob::dispatch($tenant->id);
        });
    }

    /**
     * Get the users that belong to this tenant.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /**
     * Get the brands for this tenant.
     */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    /**
     * Get the default brand for this tenant.
     */
    public function defaultBrand(): HasOne
    {
        return $this->hasOne(Brand::class)->where('is_default', true);
    }

    /**
     * Get the categories for this tenant.
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * Get the tickets for this tenant.
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Get the ownership transfers for this tenant.
     */
    public function ownershipTransfers(): HasMany
    {
        return $this->hasMany(\App\Models\OwnershipTransfer::class);
    }

    /**
     * Get the downloads for this tenant.
     * 
     * Phase 3.1 — Downloader Foundations
     */
    public function downloads(): HasMany
    {
        return $this->hasMany(\App\Models\Download::class);
    }

    /**
     * Get the agency tier for this tenant.
     * 
     * Phase AG-1 — Agency Core Data Model
     */
    public function agencyTier(): BelongsTo
    {
        return $this->belongsTo(AgencyTier::class);
    }

    /**
     * Get the agency that incubated this tenant.
     * 
     * Phase AG-2 — Incubation State & Tracking
     */
    public function incubatedByAgency(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'incubated_by_agency_id');
    }

    /**
     * Get the partner rewards received by this agency.
     * 
     * Phase AG-4 — Partner Reward Attribution
     */
    public function agencyPartnerRewards(): HasMany
    {
        return $this->hasMany(AgencyPartnerReward::class, 'agency_tenant_id');
    }

    /**
     * Get the agency that referred this tenant.
     * 
     * Phase AG-10 — Partner Marketing & Referral Attribution
     * 
     * NOTE: This is SEPARATE from incubation. A tenant can be:
     * - Incubated only (built by agency, transferred)
     * - Referred only (signed up via referral, not built by agency)
     * - Both (incubated AND referred)
     */
    public function referredByAgency(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'referred_by_agency_id');
    }

    /**
     * Get the partner referrals made by this agency.
     * 
     * Phase AG-10 — Partner Marketing & Referral Attribution
     */
    public function agencyPartnerReferrals(): HasMany
    {
        return $this->hasMany(AgencyPartnerReferral::class, 'agency_tenant_id');
    }

    /**
     * Whether tenant uses dedicated infrastructure (separate S3 bucket, etc.).
     * Decoupled from Stripe plan. Local env always returns false.
     */
    public function hasDedicatedInfrastructure(): bool
    {
        if (app()->environment('local')) {
            return false;
        }

        return ($this->infrastructure_tier ?? 'shared') === 'dedicated';
    }

    /**
     * Check if the tenant's plan allows asset versioning.
     */
    public function getPlanAllowsVersionsAttribute(): bool
    {
        return app(PlanService::class)->planAllowsVersions($this);
    }

    /**
     * Get the owner user for this tenant.
     * The database role in tenant_user.role is the SINGLE source of truth.
     * If no user has 'owner' role in the database, automatically set the first user's role to 'owner'.
     * This ensures the database always reflects reality - no discrepancies.
     */
    public function owner(): ?User
    {
        // Database is the SINGLE source of truth - check role in tenant_user table
        $ownerWithRole = $this->users()
            ->wherePivot('role', 'owner')
            ->first();
        
        if ($ownerWithRole) {
            return $ownerWithRole;
        }
        
        // No owner exists in database - this is a data integrity issue
        // Fix it by setting the first user's role to 'owner' in the database
        $firstUser = $this->users()
            ->orderBy('tenant_user.created_at')
            ->first();
        
        if ($firstUser) {
            // Update the database to fix the discrepancy
            // Use bypassOwnerCheck=true since this is internal data integrity fix
            $firstUser->setRoleForTenant($this, 'owner', true);
            return $firstUser;
        }
        
        return null;
    }

    /**
     * Check if a user is the owner of this tenant.
     * Database role is the SINGLE source of truth - NO fallback logic.
     * If the role doesn't match, it's not the owner. Period.
     */
    public function isOwner(User $user): bool
    {
        // Database is the SINGLE source of truth
        $role = $user->getRoleForTenant($this);
        // Case-insensitive comparison, but database is still the source
        return $role && strtolower($role) === 'owner';
    }

    /**
     * Get enabled users for this tenant based on plan limits.
     * Users are ordered by users.created_at (user account creation) to match owner logic.
     * Owner is ALWAYS enabled, regardless of join order or limit.
     * Non-owners are enabled based on their position among non-owners only.
     * 
     * @return array{enabled: array<int>, disabled: array<int>}
     */
    public function getEnabledUsers(\App\Services\PlanService $planService): array
    {
        $limits = $planService->getPlanLimits($this);
        $maxUsers = $limits['max_users'] ?? PHP_INT_MAX;
        
        // Get all users ordered by users.created_at (user account creation) to match owner logic
        // This ensures consistency with isOwner() which uses users.created_at
        $allUsers = $this->users()
            ->orderBy('users.created_at')
            ->get();
        
        // Ensure owner exists in database (fixes any discrepancies)
        $this->owner();
        
        $enabledUserIds = [];
        $disabledUserIds = [];
        $nonOwnerIndex = 0; // Track position among non-owners only
        $maxNonOwners = max(0, $maxUsers - 1); // Reserve 1 slot for owner (owner always counts as 1)
        
        foreach ($allUsers as $user) {
            // Database role is the SINGLE source of truth - use isOwner() which checks database
            $isOwner = $this->isOwner($user);
            
            // Owner is ALWAYS enabled, regardless of join order or limit
            if ($isOwner) {
                $enabledUserIds[] = $user->id;
                // Don't increment nonOwnerIndex - owner counts as 1 user, so we reserve 1 slot
                continue;
            }
            
            // For non-owners: enable them if they're within the remaining limit
            // maxNonOwners = maxUsers - 1 (reserving 1 slot for owner)
            // nonOwnerIndex tracks position among non-owners only (owners excluded)
            if ($nonOwnerIndex < $maxNonOwners) {
                $enabledUserIds[] = $user->id;
            } else {
                $disabledUserIds[] = $user->id;
            }
            
            $nonOwnerIndex++;
        }
        
        return [
            'enabled' => $enabledUserIds,
            'disabled' => $disabledUserIds,
        ];
    }
}
