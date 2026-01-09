<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\EventType;
use App\Traits\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
     */
    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class)->withPivot('role')->withTimestamps();
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
     */
    public function setRoleForTenant(Tenant $tenant, string $role, bool $bypassOwnerCheck = false): void
    {
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
     * Get all site-wide roles (roles that contain 'site' in the name, plus 'compliance').
     * Site-wide roles are: site_owner, site_admin, site_support, site_engineering, compliance
     * Returns array_values() to ensure it's a proper array, not an object with numeric keys
     */
    public function getSiteRoles(): array
    {
        $allRoles = $this->getRoleNames()->toArray();
        $siteRoleNames = ['site_owner', 'site_admin', 'site_support', 'site_engineering', 'compliance'];
        
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
     * Get the user's role for a specific brand.
     */
    public function getRoleForBrand(Brand $brand): ?string
    {
        // Query the pivot table directly to get the role
        // This ensures we always get the latest role value from the database
        $pivot = DB::table('brand_user')
            ->where('user_id', $this->id)
            ->where('brand_id', $brand->id)
            ->first();
        
        $role = $pivot?->role ?? null;
        
        // Convert 'owner' to 'admin' for brand roles (owner is only for tenant-level)
        // This handles any legacy data that might have 'owner' as a brand role
        if ($role === 'owner') {
            // Update the database directly to avoid recursion
            DB::table('brand_user')
                ->where('user_id', $this->id)
                ->where('brand_id', $brand->id)
                ->update(['role' => 'admin']);
            return 'admin';
        }
        
        return $role;
    }

    /**
     * Set the user's role for a specific brand.
     */
    public function setRoleForBrand(Brand $brand, string $role): void
    {
        // Prevent 'owner' from being a brand role - convert to 'admin' instead
        // Owner is only valid at the tenant level, not brand level
        if ($role === 'owner') {
            $role = 'admin';
        }
        
        if ($this->brands()->where('brands.id', $brand->id)->exists()) {
            $this->brands()->updateExistingPivot($brand->id, ['role' => $role]);
        } else {
            $this->brands()->attach($brand->id, ['role' => $role]);
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
        
        // Check if user is assigned to this brand
        if (!$this->brands()->where('brands.id', $brand->id)->exists()) {
            return false;
        }
        
        // Get brand role and check permissions
        $brandRole = $this->getRoleForBrand($brand);
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
     * Check if user has a permission based on their tenant role.
     * For company-level permissions, ONLY checks tenant role permissions.
     * For site-level permissions, checks both Spatie permissions and tenant role permissions.
     */
    public function hasPermissionForTenant(Tenant $tenant, string $permission): bool
    {
        // Define site-specific permissions that should check Spatie permissions
        $siteSpecificPermissions = ['company.manage', 'permissions.manage'];
        $isSitePermission = in_array($permission, $siteSpecificPermissions) || str_starts_with($permission, 'site.');
        
        // For site permissions, check Spatie permissions first
        if ($isSitePermission && $this->can($permission)) {
            return true;
        }

        // For ALL permissions (both company and site), check tenant role permissions
        // This ensures company permissions are ONLY checked via tenant role
        $tenantRole = $this->getRoleForTenant($tenant);
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
}
