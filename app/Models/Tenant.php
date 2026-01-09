<?php

namespace App\Models;

use App\Traits\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
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
        'timezone',
        'plan_management_source',
        'manual_plan_override',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::created(function ($tenant) {
            // Automatically create a default brand when tenant is created
            $tenant->brands()->create([
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'is_default' => true,
                'show_in_selector' => true, // Default to showing in selector
            ]);
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
            $firstUser->setRoleForTenant($this, 'owner');
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
