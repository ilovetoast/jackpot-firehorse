<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles;

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
        'country',
        'timezone',
        'address',
        'city',
        'state',
        'zip',
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
     */
    public function setRoleForTenant(Tenant $tenant, string $role): void
    {
        if ($this->tenants()->where('tenants.id', $tenant->id)->exists()) {
            $this->tenants()->updateExistingPivot($tenant->id, ['role' => $role]);
        } else {
            $this->tenants()->attach($tenant->id, ['role' => $role]);
        }
    }

    /**
     * Get all site-wide roles (roles that contain 'site' in the name).
     */
    public function getSiteRoles(): array
    {
        return array_filter(
            $this->getRoleNames()->toArray(),
            fn($role) => str_contains(strtolower($role), 'site')
        );
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
        if ($role && $role->hasPermissionTo($permission)) {
            return true;
        }

        return false;
    }
}
