<?php

namespace App\Models;

use App\Enums\AssetType;
use App\Enums\EventType;
use App\Traits\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Category Model
 *
 * Categories are brand-centric and scoped by tenant_id, brand_id, and asset_type.
 * They are NEVER global or shared across brands/tenants.
 *
 * Scoping Rules:
 * - Each category MUST have tenant_id, brand_id, and asset_type
 * - Categories are isolated per brand (no cross-brand sharing)
 * - Categories are isolated per tenant (no cross-tenant sharing)
 *
 * Category Types:
 * - System Categories (is_system = true): Auto-created defaults (Logos, Photography, Graphics)
 *   - Cannot be deleted
 *   - Cannot be renamed or have icon changed (immutable)
 *   - Can be hidden (Enterprise/Pro plans only)
 *   - is_locked is site admin only (cannot be set/changed by tenants)
 *   - Exist for every brand
 * - Custom Categories (is_system = false): User-created categories
 *   - Subject to plan limits
 *   - Can be deleted/updated by authorized users
 *   - is_locked is site admin only (cannot be set/changed by tenants)
 *   - Deletions are soft-deleted (deleted_at timestamp) for versioning and potential restoration
 *
 * Visibility:
 * - Private (is_private = true): Only visible to authorized users
 * - Hidden (is_hidden = true): Filtered from default views, requires special permissions
 * - Public (is_private = false, is_hidden = false): Visible to all brand users
 *
 * Lock Status:
 * - is_locked (true): Prevents category updates/deletion (except is_hidden for system categories)
 *   - Site admin only: Only site administrators can set or change is_locked
 *   - Tenants cannot see or edit this field
 *   - Used to protect categories from accidental modification
 */
class Category extends Model
{
    use RecordsActivity, SoftDeletes;

    /**
     * Custom event names for activity logging.
     */
    protected static $activityEventNames = [
        'created' => EventType::CATEGORY_CREATED,
        'updated' => EventType::CATEGORY_UPDATED,
        'deleted' => EventType::CATEGORY_DELETED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'brand_id',
        'asset_type',
        'name',
        'slug',
        'icon',
        'is_system',
        'is_private',
        'is_locked',
        'is_hidden',
        'requires_approval', // Phase L.5: Category-based approval rules
        'order',
        'system_category_id',
        'system_version',
        'upgrade_available',
        'deletion_available',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'asset_type' => AssetType::class,
            'is_system' => 'boolean',
            'is_private' => 'boolean',
            'is_locked' => 'boolean',
            'is_hidden' => 'boolean',
            'requires_approval' => 'boolean', // Phase L.5: Category-based approval rules
            'upgrade_available' => 'boolean',
            'deletion_available' => 'boolean',
        ];
    }

    /**
     * Get the tenant that owns this category.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the brand that owns this category.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Get the system category template this category was created from.
     */
    public function systemCategory(): BelongsTo
    {
        return $this->belongsTo(SystemCategory::class);
    }

    /**
     * Get the access rules for this category.
     */
    public function accessRules(): HasMany
    {
        return $this->hasMany(CategoryAccess::class);
    }

    /**
     * Check if a user has access to this private category.
     *
     * @param User $user
     * @return bool
     */
    public function userHasAccess(User $user): bool
    {
        // Public categories are accessible to all brand users
        if (!$this->is_private) {
            return true;
        }

        // System categories cannot be private
        if ($this->is_system) {
            return true;
        }

        // Check if user is tenant owner/admin or has 'view any restricted categories' permission
        // These users can bypass category access rules
        $tenant = $this->tenant;
        $tenantRole = $user->getRoleForTenant($tenant);
        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);
        
        // Also check brand-level owner/admin role
        $brand = $this->brand;
        $isBrandOwnerOrAdmin = false;
        if ($brand) {
            $brandRole = $user->getRoleForBrand($brand);
            $isBrandOwnerOrAdmin = in_array($brandRole, ['owner', 'admin']);
        }

        // Check for permission to view any restricted categories
        $canViewAnyRestricted = $user->hasPermissionForTenant($tenant, 'view.restricted.categories');

        // Owners/admins or users with permission can bypass access rules
        if ($isTenantOwnerOrAdmin || $isBrandOwnerOrAdmin || $canViewAnyRestricted) {
            return true;
        }

        // Check if user has access via role or direct user assignment
        $accessRules = $this->accessRules;

        foreach ($accessRules as $rule) {
            if ($rule->access_type === 'role') {
                // Check if user has this role for the brand
                $userBrandRole = $user->getRoleForBrand($this->brand);
                if ($userBrandRole === $rule->role) {
                    return true;
                }
            } elseif ($rule->access_type === 'user') {
                // Check if this is the user
                if ($rule->user_id === $user->id) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Scope a query to only include categories accessible to a specific user.
     *
     * Note: This scope provides basic filtering, but CategoryPolicy::view() provides
     * the authoritative access control including owner/admin bypass and permission checks.
     *
     * @param Builder $query
     * @param User $user
     * @return Builder
     */
    public function scopeAccessibleToUser(Builder $query, User $user): Builder
    {
        return $query->where(function ($q) use ($user) {
            // Include public categories
            $q->where('is_private', false)
                // Include private categories where user has access
                ->orWhere(function ($privateQuery) use ($user) {
                    $privateQuery->where('is_private', true)
                        ->where(function ($accessQuery) use ($user) {
                            // Check role-based access - user's brand role matches access rule
                            $accessQuery->whereHas('accessRules', function ($roleQuery) use ($user) {
                                $roleQuery->where('access_type', 'role')
                                    ->whereIn('role', function ($subQuery) use ($user) {
                                        $subQuery->select('role')
                                            ->from('brand_user')
                                            ->where('user_id', $user->id)
                                            ->whereColumn('brand_user.brand_id', 'category_access.brand_id');
                                    });
                            })
                            // Check user-based access
                            ->orWhereHas('accessRules', function ($userQuery) use ($user) {
                                $userQuery->where('access_type', 'user')
                                    ->where('user_id', $user->id);
                            });
                        });
                });
        });
    }

    /**
     * Scope a query to only include system categories.
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    /**
     * Scope a query to only include custom categories.
     */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }

    /**
     * Scope a query to only include private categories.
     */
    public function scopePrivate(Builder $query): Builder
    {
        return $query->where('is_private', true);
    }

    /**
     * Scope a query to only include categories for a specific brand.
     */
    public function scopeForBrand(Builder $query, Brand $brand): Builder
    {
        return $query->where('brand_id', $brand->id);
    }

    /**
     * Scope a query to only include categories for a specific asset type.
     */
    public function scopeForAssetType(Builder $query, AssetType $assetType): Builder
    {
        return $query->where('asset_type', $assetType);
    }

    /**
     * Scope a query to only include hidden categories.
     */
    public function scopeHidden(Builder $query): Builder
    {
        return $query->where('is_hidden', true);
    }

    /**
     * Scope a query to only include visible (non-hidden) categories.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false);
    }

    /**
     * Scope a query to only include categories with upgrades available.
     */
    public function scopeWithUpgradeAvailable(Builder $query): Builder
    {
        return $query->where('upgrade_available', true);
    }

    /**
     * Scope a query to only include active, selectable categories.
     * 
     * Filters out:
     * - Soft-deleted categories (deleted_at IS NOT NULL)
     * - Templates (categories without an ID - these are virtual system templates)
     * - Deleted system categories (where template no longer exists)
     * 
     * This scope should be used when fetching categories for dropdowns,
     * sidebars, or any UI where users can select categories.
     * 
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deleted_at')
            ->whereNotNull('id');
    }

    /**
     * Check if this category is active and selectable.
     * 
     * A category is considered active if:
     * - It's not soft-deleted (deleted_at IS NULL)
     * - It has an ID (not a template)
     * - If it's a system category, its template still exists
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        // Must have an ID (not a template)
        if (!$this->id) {
            return false;
        }

        // Must not be soft-deleted
        if ($this->deleted_at) {
            return false;
        }

        // System categories must have an existing template
        if ($this->is_system && !$this->systemTemplateExists()) {
            return false;
        }

        return true;
    }

    /**
     * Check if the system template for this category still exists.
     * Returns true if template exists, false if it's been deleted (orphaned).
     *
     * @return bool
     */
    public function systemTemplateExists(): bool
    {
        if (!$this->is_system) {
            return false; // Not a system category, so no template to check
        }

        // Check by system_category_id if available
        if ($this->system_category_id) {
            return \App\Models\SystemCategory::where('id', $this->system_category_id)->exists();
        }

        // Legacy category - check by slug/asset_type
        return \App\Models\SystemCategory::where('slug', $this->slug)
            ->where('asset_type', $this->asset_type->value)
            ->exists();
    }

    /**
     * Check if this category can be deleted.
     * Returns true if deletion is allowed, false otherwise.
     *
     * @return bool
     */
    public function canBeDeleted(): bool
    {
        // Custom categories can be deleted if not locked
        if (!$this->is_system) {
            return !$this->is_locked;
        }

        // System categories can only be deleted if their template is deleted
        // Even if locked, if the template is gone, the category can be deleted
        if ($this->is_system) {
            return !$this->systemTemplateExists();
        }

        return false;
    }

    /**
     * Phase L.5.1: Check if this category requires approval before publishing.
     *
     * @return bool
     */
    public function requiresApproval(): bool
    {
        return $this->requires_approval === true;
    }
}
