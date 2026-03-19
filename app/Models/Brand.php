<?php

namespace App\Models;

use App\Enums\EventType;
use App\Traits\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'owning_tenant_id', // Phase AG-2: Brand ownership tracking
        'name',
        'slug',
        'logo_path',
        'logo_id',
        'icon_path',
        'icon_id',
        'icon',
        'icon_bg_color',
        'is_default',
        'show_in_selector',
        'primary_color',
        'primary_color_user_defined',
        'secondary_color',
        'secondary_color_user_defined',
        'accent_color',
        'accent_color_user_defined',
        'nav_color',
        'workspace_button_style',
        'logo_filter',
        'logo_dark_path',
        'logo_dark_id',
        'settings',
        'download_landing_settings', // D10: JSON { enabled, logo_asset_id, color_role, background_asset_ids, default_headline, default_subtext }
        'portal_settings', // Brand Portal: JSON { entry, public, sharing, invite }
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
        'primary_color_user_defined' => 'boolean',
        'secondary_color_user_defined' => 'boolean',
        'accent_color_user_defined' => 'boolean',
        'settings' => 'array',
        'download_landing_settings' => 'array', // R3.2
        'portal_settings' => 'array',
    ];
    }

    /**
     * Resolve logo_path from logo_id when logo_path is null.
     * When logo references an asset (logo_id), returns the thumbnail URL so the logo displays
     * in nav, brand selector, etc. For SVG assets without thumbnails, serves the original
     * file directly since SVGs render natively in browsers.
     */
    public function getLogoPathAttribute($value): ?string
    {
        if ($value !== null && $value !== '') {
            return $value;
        }
        $logoId = $this->attributes['logo_id'] ?? null;
        if ($logoId) {
            $asset = Asset::find($logoId);
            if (!$asset) {
                return null;
            }

            $isSvg = $asset->mime_type === 'image/svg+xml'
                || strtolower(pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION)) === 'svg';

            $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus
                ? $asset->thumbnail_status
                : \App\Enums\ThumbnailStatus::tryFrom($asset->thumbnail_status ?? '');

            if ($isSvg && $thumbnailStatus !== \App\Enums\ThumbnailStatus::COMPLETED) {
                return $asset->deliveryUrl(\App\Support\AssetVariant::ORIGINAL, \App\Support\DeliveryContext::AUTHENTICATED) ?: null;
            }

            return $asset->deliveryUrl(\App\Support\AssetVariant::THUMB_MEDIUM, \App\Support\DeliveryContext::AUTHENTICATED) ?: null;
        }
        return null;
    }

    /**
     * Resolve logo_dark_path from logo_dark_id when logo_dark_path is null.
     * Dark variant of the logo for use on dark backgrounds (cinematic hero, etc.).
     */
    public function getLogoDarkPathAttribute($value): ?string
    {
        if ($value !== null && $value !== '') {
            return $value;
        }
        $logoDarkId = $this->attributes['logo_dark_id'] ?? null;
        if ($logoDarkId) {
            $asset = Asset::find($logoDarkId);
            if (!$asset) {
                return null;
            }

            $isSvg = $asset->mime_type === 'image/svg+xml'
                || strtolower(pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION)) === 'svg';

            $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus
                ? $asset->thumbnail_status
                : \App\Enums\ThumbnailStatus::tryFrom($asset->thumbnail_status ?? '');

            if ($isSvg && $thumbnailStatus !== \App\Enums\ThumbnailStatus::COMPLETED) {
                return $asset->deliveryUrl(\App\Support\AssetVariant::ORIGINAL, \App\Support\DeliveryContext::AUTHENTICATED) ?: null;
            }

            return $asset->deliveryUrl(\App\Support\AssetVariant::THUMB_MEDIUM, \App\Support\DeliveryContext::AUTHENTICATED) ?: null;
        }
        return null;
    }

    /**
     * Resolve icon_path from icon_id when icon_path is null.
     * When icon references an asset (icon_id), returns the thumbnail URL for display.
     * For SVG assets without thumbnails, serves the original file directly.
     */
    public function getIconPathAttribute($value): ?string
    {
        if ($value !== null && $value !== '') {
            return $value;
        }
        $iconId = $this->attributes['icon_id'] ?? null;
        if ($iconId) {
            $asset = Asset::find($iconId);
            if (!$asset) {
                return null;
            }

            $isSvg = $asset->mime_type === 'image/svg+xml'
                || strtolower(pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION)) === 'svg';

            $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus
                ? $asset->thumbnail_status
                : \App\Enums\ThumbnailStatus::tryFrom($asset->thumbnail_status ?? '');

            if ($isSvg && $thumbnailStatus !== \App\Enums\ThumbnailStatus::COMPLETED) {
                return $asset->deliveryUrl(\App\Support\AssetVariant::ORIGINAL, \App\Support\DeliveryContext::AUTHENTICATED) ?: null;
            }

            return $asset->deliveryUrl(\App\Support\AssetVariant::THUMB_MEDIUM, \App\Support\DeliveryContext::AUTHENTICATED) ?: null;
        }
        return null;
    }

    /**
     * Whether the brand's download landing page template is enabled (branding only).
     * Controls use of this brand's template when a landing page is shown; does NOT force a landing page.
     * Single source of truth for download_landing_settings.enabled.
     *
     * Why landing pages are not policy-controlled here: This setting controls branding (which template to use),
     * not whether a landing page is shown. Landing page requirement is determined only by password (see
     * Download::isLandingPageRequired()). We do not add a "force landing page" flag here—intentional design.
     */
    public function isDownloadLandingPageEnabled(): bool
    {
        $settings = $this->download_landing_settings ?? [];

        return ($settings['enabled'] ?? true) !== false;
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

        // Brand DNA: auto-create BrandModel (one per brand, no versions yet)
        static::created(function ($brand) {
            $brand->brandModel()->create(['is_enabled' => false]);
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
     * Get the owning tenant for this brand (for future brand transfers).
     * 
     * Phase AG-2 — Incubation State & Tracking
     */
    public function owningTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'owning_tenant_id');
    }

    /**
     * Get the Brand DNA / Brand Guidelines model (one per brand).
     */
    public function brandModel(): HasOne
    {
        return $this->hasOne(BrandModel::class);
    }

    /**
     * Get compliance aggregates for execution alignment (Phase 8).
     */
    public function complianceAggregate(): HasOne
    {
        return $this->hasOne(BrandComplianceAggregate::class);
    }

    /**
     * Get bootstrap runs for this brand.
     */
    public function bootstrapRuns(): HasMany
    {
        return $this->hasMany(BrandBootstrapRun::class);
    }

    /**
     * Get visual references (logo, photography examples) for imagery similarity scoring.
     */
    public function visualReferences(): HasMany
    {
        return $this->hasMany(BrandVisualReference::class);
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
     * Get a portal setting using dot notation with a fallback default.
     * Example: $brand->getPortalSetting('entry.style', 'cinematic')
     */
    public function getPortalSetting(string $key, mixed $default = null): mixed
    {
        $settings = $this->portal_settings ?? [];

        return data_get($settings, $key, $default);
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
