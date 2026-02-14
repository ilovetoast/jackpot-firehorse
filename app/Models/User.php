<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\EventType;
use App\Traits\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles, RecordsActivity;

    /**
     * Custom event names for activity logging.
     */
    protected static $activityEventNames = [
        'created' => EventType::USER_CREATED,
        'updated' => EventType::USER_UPDATED,
        'deleted' => EventType::USER_DELETED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'avatar_url',
        'country',
        'timezone',
        'address',
        'city',
        'state',
        'zip',
        'suspended_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'name',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'suspended_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's full name.
     */
    public function getNameAttribute(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
    }

    /**
     * Get the tenants that this user belongs to.
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)->withPivot('role')->withTimestamps();
    }

    /**
     * Get the brands that this user belongs to.
     * 
     * Phase MI-1: This relationship includes all pivots (active and removed).
     * Use activeBrandMembership() or filter by removed_at IS NULL for active memberships.
     */
    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class)
            ->withPivot('role', 'requires_approval', 'removed_at')
            ->withTimestamps();
    }

    /**
     * Phase C12.0: Collection access grants (collection-only access, NOT brand membership).
     */
    public function collectionAccessGrants(): HasMany
    {
        return $this->hasMany(CollectionUser::class, 'user_id');
    }

    /**
     * Phase C12.0: Collections this user can access via collection-only grants.
     */
    public function collectionAccessibleCollections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'collection_user')
            ->withPivot('invited_by_user_id', 'accepted_at')
            ->withTimestamps();
    }

    /**
     * Get the tickets created by this user.
     */
    public function createdTickets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Ticket::class, 'created_by_user_id');
    }

    /**
     * Get the tickets assigned to this user.
     */
    public function assignedTickets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_to_user_id');
    }

    /**
     * Get the ticket messages created by this user.
     */
    public function ticketMessages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    /**
     * Get the ticket attachments uploaded by this user.
     */
    public function ticketAttachments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    /**
     * Get the user's role for a specific tenant.
     */
    public function getRoleForTenant(Tenant $tenant): ?string
    {
        // Query the pivot table directly to get the role
        // This ensures we always get the latest role value from the database
        $pivot = DB::table('tenant_user')
            ->where('user_id', $this->id)
            ->where('tenant_id', $tenant->id)
            ->first();
        
        return $pivot?->role ?? null;
    }

    /**
     * Set the user's role for a specific tenant.
     * 
     * @param Tenant $tenant
     * @param string $role
     * @param bool $bypassOwnerCheck If true, allows owner role assignment (for ownership transfers)
     * @return void
     * @throws \App\Exceptions\CannotAssignOwnerRoleException If trying to assign owner role and current user is not platform super-owner
     * @throws \InvalidArgumentException If role is invalid
     */
    public function setRoleForTenant(Tenant $tenant, string $role, bool $bypassOwnerCheck = false): void
    {
        // Validate role using RoleRegistry
        if (!\App\Support\Roles\RoleRegistry::isValidTenantRole($role)) {
            throw new \InvalidArgumentException(
                "Invalid tenant role: {$role}. Valid roles are: " . implode(', ', \App\Support\Roles\RoleRegistry::tenantRoles())
            );
        }
        
        // Prevent direct owner role assignment - must use ownership transfer workflow
        if (strtolower($role) === 'owner' && !$bypassOwnerCheck) {
            // Allow if tenant has no owner (initial setup case)
            $currentOwner = $tenant->owner();
            if ($currentOwner) {
                $currentUser = \Illuminate\Support\Facades\Auth::user();
                
                // Only platform super-owner (user ID 1) can directly assign owner role (break-glass exception)
                if (!$currentUser || $currentUser->id !== 1) {
                    throw new \App\Exceptions\CannotAssignOwnerRoleException(
                        $tenant,
                        $this,
                        $currentUser,
                        'Please use the ownership transfer process in the Company settings.'
                    );
                }
            }
            // If no current owner exists, allow the assignment (initial setup)
        }

        if ($this->tenants()->where('tenants.id', $tenant->id)->exists()) {
            $this->tenants()->updateExistingPivot($tenant->id, ['role' => $role]);
        } else {
            $this->tenants()->attach($tenant->id, ['role' => $role]);
        }
    }

    /**
     * Get all site-wide roles (roles that contain 'site' in the name, plus 'site_compliance').
     * Site-wide roles are: site_owner, site_admin, site_support, site_engineering, site_compliance
     * Note: Tenant-level roles are: owner, admin, member. All other roles are brand-scoped.
     * Returns array_values() to ensure it's a proper array, not an object with numeric keys
     */
    public function getSiteRoles(): array
    {
        $allRoles = $this->getRoleNames()->toArray();
        $siteRoleNames = ['site_owner', 'site_admin', 'site_support', 'site_engineering', 'site_compliance'];
        
        $filtered = array_filter(
            $allRoles,
            fn($role) => in_array($role, $siteRoleNames)
        );
        
        // Use array_values to ensure it's a proper array, not an object with numeric keys
        return array_values($filtered);
    }

    /**
     * Get all tenant-specific roles from pivot table.
     */
    public function getTenantRoles(): array
    {
        return $this->tenants()
            ->whereNotNull('tenant_user.role')
            ->pluck('tenant_user.role', 'tenants.id')
            ->toArray();
    }

    /**
     * Get valid brand roles.
     * 
     * @deprecated Use RoleRegistry::brandRoles() instead
     * @return array<string> Valid brand role names
     */
    public static function getValidBrandRoles(): array
    {
        return \App\Support\Roles\RoleRegistry::brandRoles();
    }

    /**
     * Phase MI-1: Get active brand membership information.
     * 
     * Centralizes brand membership resolution with integrity checks.
     * Verifies tenant membership, brand_user existence, and active status.
     * 
     * @param Brand $brand
     * @return array{role: string|null, requires_approval: bool}|null Returns null if no active membership
     */
    public function activeBrandMembership(Brand $brand): ?array
    {
        $tenant = $brand->tenant;
        
        // Verify tenant membership first
        if (!$this->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return null;
        }
        
        // Query for active brand_user pivot (removed_at IS NULL)
        $pivot = DB::table('brand_user')
            ->where('user_id', $this->id)
            ->where('brand_id', $brand->id)
            ->whereNull('removed_at') // Phase MI-1: Only active memberships
            ->first();
        
        if (!$pivot) {
            return null;
        }
        
        $role = $pivot->role ?? null;
        
        // Validate role - if invalid, log warning and return null
        // NO automatic conversion - invalid roles should be fixed manually
        if ($role && !\App\Support\Roles\RoleRegistry::isValidBrandRole($role)) {
            \Log::warning('[User] Invalid brand role detected in database', [
                'user_id' => $this->id,
                'brand_id' => $brand->id,
                'invalid_role' => $role,
            ]);
            return null; // Return null for invalid roles
        }
        
        return [
            'role' => $role,
            'requires_approval' => (bool) ($pivot->requires_approval ?? false),
        ];
    }

    /**
     * Get the user's role for a specific brand.
     * 
     * Phase MI-1: Now uses activeBrandMembership for integrity.
     */
    public function getRoleForBrand(Brand $brand): ?string
    {
        $membership = $this->activeBrandMembership($brand);
        return $membership['role'] ?? null;
    }

    /**
     * Set the user's role for a specific brand.
     * 
     * Phase MI-1: Handles soft-deleted pivots by restoring them.
     * Prevents duplicate active memberships.
     * NO automatic conversion - invalid roles must be validated before calling this method.
     * Use RoleRegistry::validateBrandRoleAssignment() to validate before calling.
     */
    public function setRoleForBrand(Brand $brand, string $role): void
    {
        // Validate role using RoleRegistry - throw exception if invalid
        if (!\App\Support\Roles\RoleRegistry::isValidBrandRole($role)) {
            throw new \InvalidArgumentException(
                "Invalid brand role: {$role}. Valid roles are: " . implode(', ', \App\Support\Roles\RoleRegistry::brandRoles())
            );
        }
        
        // Phase MI-1: Check for existing pivot (including soft-deleted)
        $existingPivot = DB::table('brand_user')
            ->where('user_id', $this->id)
            ->where('brand_id', $brand->id)
            ->first();
        
        // Phase MI-1: Guard against duplicate active memberships
        // If another active membership exists (shouldn't happen, but defensive check)
        $activeCount = DB::table('brand_user')
            ->where('user_id', $this->id)
            ->where('brand_id', $brand->id)
            ->whereNull('removed_at')
            ->count();
        
        if ($activeCount > 0 && (!$existingPivot || $existingPivot->removed_at !== null)) {
            // Another active membership exists - this is a data integrity issue
            \Log::error('[User::setRoleForBrand] Duplicate active membership detected', [
                'user_id' => $this->id,
                'brand_id' => $brand->id,
                'active_count' => $activeCount,
            ]);
            throw new \RuntimeException('Duplicate active brand membership detected. Please run diagnostic command to fix.');
        }
        
        if ($existingPivot) {
            // Pivot exists - update it and clear removed_at if it was soft-deleted
            DB::table('brand_user')
                ->where('id', $existingPivot->id)
                ->update([
                    'role' => $role,
                    'removed_at' => null, // Phase MI-1: Restore if soft-deleted
                    'updated_at' => now(),
                ]);
        } else {
            // No pivot exists - create new one
            $this->brands()->attach($brand->id, [
                'role' => $role,
                'removed_at' => null, // Explicitly set to null for new pivots
            ]);
        }
    }

    /**
     * Check if user has a permission for a specific brand.
     * First checks tenant-level permission (admin/owner may have full access),
     * then checks brand-specific role permissions.
     */
    public function hasPermissionForBrand(Brand $brand, string $permission): bool
    {
        $tenant = $brand->tenant;
        
        // Check tenant-level access first
        $tenantRole = $this->getRoleForTenant($tenant);
        
        // Admin/Owner at tenant level have full access to all brands
        if (in_array($tenantRole, ['admin', 'owner'])) {
            return $this->hasPermissionForTenant($tenant, $permission);
        }
        
        // Phase MI-1: Check active brand membership
        $membership = $this->activeBrandMembership($brand);
        if (!$membership) {
            return false;
        }
        
        // Get brand role and check permissions
        $brandRole = $membership['role'];
        if (!$brandRole) {
            return false;
        }
        
        // Check if brand role has permission
        $role = \Spatie\Permission\Models\Role::where('name', $brandRole)->first();
        if ($role) {
            try {
                if ($role->hasPermissionTo($permission)) {
                    return true;
                }
            } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
                // Permission doesn't exist in database, return false
                return false;
            }
        }

        return false;
    }

    /**
     * Check if user has a permission in the given tenant/brand context.
     * Uses AuthPermissionService for unified backend permission checks.
     *
     * @param string $permission Permission string (e.g. 'team.manage', 'asset.view')
     * @param Tenant|null $tenant Tenant context (null for site-only checks)
     * @param Brand|null $brand Brand context (optional, for brand-scoped permissions)
     */
    public function canForContext(string $permission, ?Tenant $tenant = null, ?Brand $brand = null): bool
    {
        return app(\App\Services\AuthPermissionService::class)->can($this, $permission, $tenant, $brand);
    }

    /**
     * Check if user has a permission based on their tenant role.
     * For company-level permissions, ONLY checks tenant role permissions.
     * For site-level permissions, checks both Spatie permissions and tenant role permissions.
     */
    public function hasPermissionForTenant(Tenant $tenant, string $permission): bool
    {
        // CRITICAL: Check PermissionMap FIRST (owner/admin have all permissions)
        // This prevents the bug where owner/admin permissions were missed
        $tenantRole = $this->getRoleForTenant($tenant);
        if ($tenantRole) {
            $permissionMap = \App\Support\Roles\PermissionMap::tenantPermissions();
            $rolePermissions = $permissionMap[strtolower($tenantRole)] ?? [];
            
            // Owner/Admin have all permissions via PermissionMap
            if (in_array($permission, $rolePermissions)) {
                return true;
            }
        }

        // Define site-specific permissions that should check Spatie permissions
        $siteSpecificPermissions = ['company.manage', 'permissions.manage'];
        $isSitePermission = in_array($permission, $siteSpecificPermissions) || str_starts_with($permission, 'site.');
        
        // For site permissions, check Spatie permissions first
        if ($isSitePermission && $this->can($permission)) {
            return true;
        }

        // For ALL permissions (both company and site), check tenant role permissions
        // This ensures company permissions are ONLY checked via tenant role
        if (!$tenantRole) {
            return false;
        }

        // Get the role model and check if it has the permission
        $role = \Spatie\Permission\Models\Role::where('name', $tenantRole)->first();
        if ($role) {
            try {
                if ($role->hasPermissionTo($permission)) {
                    return true;
                }
            } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
                // Permission doesn't exist in database, return false
                return false;
            }
        }

        return false;
    }

    /**
     * Check if the user is suspended.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Suspend the user account.
     */
    public function suspend(): void
    {
        $this->update(['suspended_at' => now()]);
    }

    /**
     * Unsuspend the user account.
     */
    public function unsuspend(): void
    {
        $this->update(['suspended_at' => null]);
    }

    /**
     * Check if user is disabled due to plan limits for a specific tenant.
     * Owner is never disabled due to plan limits.
     * Owner is determined by having 'owner' role OR being the first user by created_at.
     * 
     * @param \App\Models\Tenant $tenant
     * @return bool
     */
    public function isDisabledByPlanLimit(Tenant $tenant): bool
    {
        // Owner is NEVER disabled - check this FIRST using tenant's isOwner method
        // This includes fallback logic (first user is owner even without 'owner' role)
        if ($tenant->isOwner($this)) {
            return false;
        }
        
        $planService = app(\App\Services\PlanService::class);
        $enabledUsers = $tenant->getEnabledUsers($planService);
        
        return in_array($this->id, $enabledUsers['disabled']);
    }

    /**
     * Send the password reset notification.
     *
     * @param  string  $token
     * @return void
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ResetPasswordNotification($token));
    }

    /**
     * Phase AF-3: Get notifications for this user.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(\App\Models\Notification::class);
    }
}
