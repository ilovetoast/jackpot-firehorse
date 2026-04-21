<?php

namespace App\Models;

use App\Enums\EventType;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
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
        'icon_bg_color',
        'icon_style',
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
        'logo_light_path',
        'logo_light_id',
        'logo_horizontal_path',
        'logo_horizontal_id',
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
     * Thumbnail/original URL for a logo image asset (light or dark column).
     * Used by accessors (AUTHENTICATED) and {@see logoUrlForGuest} (GATEWAY signed URLs).
     */
    protected function deliveryUrlForLogoAssetId(int|string|null $assetId, DeliveryContext $context): ?string
    {
        if ($assetId === null || $assetId === '') {
            return null;
        }

        // logo_id is a UUID column — never cast to int (would break lookups and hide logos).
        $asset = Asset::find($assetId);
        if (! $asset) {
            return null;
        }

        $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus
            ? $asset->thumbnail_status
            : \App\Enums\ThumbnailStatus::tryFrom($asset->thumbnail_status ?? '');

        // THUMB_MEDIUM URLs 404 until GenerateThumbnailsJob finishes (onboarding upload sets
        // thumbnail_status=PENDING). Serve the original file immediately so nav / Brand
        // essentials previews are not broken for the first seconds — not a browser CORS issue.
        if ($thumbnailStatus !== \App\Enums\ThumbnailStatus::COMPLETED) {
            return $asset->deliveryUrl(AssetVariant::ORIGINAL, $context) ?: null;
        }

        $medium = $asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, $context);

        return $medium !== '' ? $medium : ($asset->deliveryUrl(AssetVariant::ORIGINAL, $context) ?: null);
    }

    /**
     * Logo URL for unauthenticated pages (gateway, forgot password, public portal).
     * Also used when the viewer's session tenant does not own the brand (e.g. agency listing a client's brands):
     * authenticated CDN URLs rely on cookies scoped to /tenants/{uuid}/* for the active tenant only.
     * Manual logo_*_path overrides are returned as-is (may still require cookies).
     *
     * @param  bool|string  $surface  Legacy boolean: true = dark. String: 'primary' | 'light' | 'dark'.
     */
    public function logoUrlForGuest(bool|string $surface = 'primary'): ?string
    {
        if (is_bool($surface)) {
            $surface = $surface ? 'dark' : 'primary';
        }

        [$pathCol, $idCol, $fallbackPathCol, $fallbackIdCol] = match ($surface) {
            'dark' => ['logo_dark_path', 'logo_dark_id', 'logo_path', 'logo_id'],
            'light' => ['logo_light_path', 'logo_light_id', 'logo_path', 'logo_id'],
            default => ['logo_path', 'logo_id', 'logo_path', 'logo_id'],
        };

        $manual = $this->attributes[$pathCol] ?? null;
        if ($manual !== null && $manual !== '') {
            return $manual;
        }

        $logoId = $this->attributes[$idCol] ?? null;
        $resolved = $this->deliveryUrlForLogoAssetId($logoId, DeliveryContext::GATEWAY);
        if ($resolved !== null && $resolved !== '') {
            return $resolved;
        }

        if ($fallbackPathCol !== $pathCol) {
            $fallbackManual = $this->attributes[$fallbackPathCol] ?? null;
            if ($fallbackManual !== null && $fallbackManual !== '') {
                return $fallbackManual;
            }

            return $this->deliveryUrlForLogoAssetId(
                $this->attributes[$fallbackIdCol] ?? null,
                DeliveryContext::GATEWAY
            );
        }

        return null;
    }

    /**
     * Logo URL for light-background transactional email (invites, etc.).
     * Prefer the original asset file over medium thumbnails — those may be composited on a dark canvas for in-app contrast.
     */
    public function logoUrlForTransactionalEmail(): ?string
    {
        $manual = $this->attributes['logo_path'] ?? null;
        if ($manual !== null && $manual !== '') {
            return $manual;
        }

        $logoId = $this->attributes['logo_id'] ?? null;
        if ($logoId === null || $logoId === '') {
            return null;
        }

        $asset = Asset::find($logoId);
        if (! $asset) {
            return null;
        }

        $original = $asset->deliveryUrl(AssetVariant::ORIGINAL, DeliveryContext::GATEWAY);
        if ($original !== '') {
            return $original;
        }

        $fallback = $asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::GATEWAY);

        return $fallback !== '' ? $fallback : null;
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

        return $this->deliveryUrlForLogoAssetId($this->attributes['logo_id'] ?? null, DeliveryContext::AUTHENTICATED);
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

        return $this->deliveryUrlForLogoAssetId($this->attributes['logo_dark_id'] ?? null, DeliveryContext::AUTHENTICATED);
    }

    /**
     * Resolve logo_light_path from logo_light_id when logo_light_path is null.
     * Light variant of the logo for use on light/white backgrounds (nav bar light theme,
     * asset library, light Brand Guidelines surfaces). Falls back to primary when unset —
     * see {@see logoForSurface()}.
     */
    public function getLogoLightPathAttribute($value): ?string
    {
        if ($value !== null && $value !== '') {
            return $value;
        }

        return $this->deliveryUrlForLogoAssetId($this->attributes['logo_light_id'] ?? null, DeliveryContext::AUTHENTICATED);
    }

    /**
     * Resolve the correct logo URL for a given display surface, with fallback to primary.
     *
     * Call sites should prefer this over picking individual columns so variant rollout and
     * fallback semantics live in one place. Surfaces:
     *  - 'light'   → logo_light_path, falls back to logo_path
     *  - 'dark'    → logo_dark_path, falls back to logo_path
     *  - 'primary' → logo_path (source of truth; used by Studio/generative)
     *
     * Returns null if no logo is set at all.
     */
    public function logoForSurface(string $surface = 'primary'): ?string
    {
        $primary = $this->logo_path;

        return match ($surface) {
            'dark' => $this->logo_dark_path ?: $primary,
            'light' => $this->logo_light_path ?: $primary,
            default => $primary,
        };
    }

    /**
     * Asset-id analogue of {@see logoForSurface()}. Used when callers need to resolve
     * thumbnail/delivery URLs through the Asset pipeline rather than a direct path.
     */
    public function logoAssetIdForSurface(string $surface = 'primary'): ?string
    {
        $primaryId = $this->attributes['logo_id'] ?? null;

        return match ($surface) {
            'dark' => ($this->attributes['logo_dark_id'] ?? null) ?: $primaryId,
            'light' => ($this->attributes['logo_light_id'] ?? null) ?: $primaryId,
            default => $primaryId,
        };
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
     * Get the onboarding progress for this brand (one per brand).
     */
    public function onboardingProgress(): HasOne
    {
        return $this->hasOne(BrandOnboardingProgress::class);
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

    /**
     * Creator module: users who may approve prostaff submissions (configured in Brand Settings → Creators).
     *
     * @return list<int>
     */
    public function creatorModuleApproverUserIds(): array
    {
        $raw = ($this->settings ?? [])['creator_module_approver_user_ids'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $id = (int) $v;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    public function hasConfiguredCreatorApprovers(): bool
    {
        return $this->creatorModuleApproverUserIds() !== [];
    }
}
