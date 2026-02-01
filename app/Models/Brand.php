<?php

namespace App\Models;

use App\Enums\EventType;
use App\Traits\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    use RecordsActivity;

    /**
     * Custom event names for activity logging.
     */
    protected static $activityEventNames = [
        'created' => EventType::BRAND_CREATED,
        'updated' => EventType::BRAND_UPDATED,
        'deleted' => EventType::BRAND_DELETED,
    ];
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'logo_path',
        'icon_path',
        'icon',
        'icon_bg_color',
        'is_default',
        'show_in_selector',
        'primary_color',
        'secondary_color',
        'accent_color',
        'nav_color',
        'logo_filter',
        'settings',
        'download_landing_settings', // D10: JSON { enabled, logo_asset_id, color_role, background_asset_ids, default_headline, default_subtext }
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
        'is_default' => 'boolean',
        'show_in_selector' => 'boolean',
        'settings' => 'array',
        'download_landing_settings' => 'array', // R3.2
    ];
    }

    /**
     * Scope a query to only include default brands.
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($brand) {
            // If this is being set as default, ensure no other brand is default for this tenant
            if ($brand->is_default) {
                static::where('tenant_id', $brand->tenant_id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });

        static::updating(function ($brand) {
            // If this is being set as default, ensure no other brand is default for this tenant
            if ($brand->isDirty('is_default') && $brand->is_default) {
                static::where('tenant_id', $brand->tenant_id)
                    ->where('id', '!=', $brand->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });

        // Automatically create system categories when a brand is created
        static::created(function ($brand) {
            $seeder = app(\App\Services\SystemCategorySeeder::class);
            $seeder->seedForBrand($brand);
        });
    }

    /**
     * Get the tenant that owns this brand.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the categories for this brand.
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * Get the users that belong to this brand.
     * 
     * Phase MI-1: This relationship includes all pivots (active and removed).
     * Filter by removed_at IS NULL for active memberships only.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role', 'requires_approval', 'removed_at')
            ->withTimestamps();
    }

    /**
     * Get the invitations for this brand.
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(BrandInvitation::class);
    }

    /**
     * Get the tickets associated with this brand.
     */
    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class)->withTimestamps();
    }

    /**
     * Check if contributor uploads require approval for this brand.
     * 
     * Phase J: Brand-level setting for contributor upload approval.
     * When enabled, contributor uploads create assets with approval_status = pending.
     * 
     * @return bool True if contributor uploads require approval
     */
    public function requiresContributorApproval(): bool
    {
        $settings = $this->settings ?? [];
        return (bool) ($settings['contributor_upload_requires_approval'] ?? false);
    }
}
