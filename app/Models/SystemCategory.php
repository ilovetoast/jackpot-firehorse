<?php

namespace App\Models;

use App\Enums\AssetType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * SystemCategory Model
 *
 * Represents global system category templates that are copied to brands when they are created.
 * These templates are managed by site owners and define the default system categories
 * (e.g., "Logos", "Photography", "Graphics") that should exist for all brands.
 *
 * System categories are:
 * - Global (no tenant_id or brand_id)
 * - Copied to new brands when they are created
 * - Editable by site owners only
 * - Used as templates for creating brand-specific categories
 */
class SystemCategory extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'icon',
        'asset_type',
        'is_private',
        'is_hidden',
        'sort_order',
        'version',
        'change_summary',
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
            'is_private' => 'boolean',
            'is_hidden' => 'boolean',
            'sort_order' => 'integer',
            'version' => 'integer',
        ];
    }

    /**
     * Generate a slug from the name if not provided.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($systemCategory) {
            if (empty($systemCategory->slug)) {
                $systemCategory->slug = Str::slug($systemCategory->name);
            }
            // Set default version if not provided
            if (!isset($systemCategory->version)) {
                $systemCategory->version = 1;
            }
        });
    }

    /**
     * Scope a query to only include the latest version of each template.
     * Latest version is the highest version number for each slug/asset_type combination.
     */
    public function scopeLatestVersion(Builder $query): Builder
    {
        return $query->whereIn('id', function ($subquery) {
            $subquery->selectRaw('MAX(id) as id')
                ->from('system_categories as sc2')
                ->whereColumn('sc2.slug', 'system_categories.slug')
                ->whereColumn('sc2.asset_type', 'system_categories.asset_type')
                ->whereRaw('sc2.version = (SELECT MAX(version) FROM system_categories sc3 WHERE sc3.slug = sc2.slug AND sc3.asset_type = sc2.asset_type)');
        });
    }

    /**
     * Get all previous versions of this system category template.
     * Previous versions are other SystemCategory records with the same slug and asset_type
     * but lower version numbers.
     */
    public function previousVersions(): HasMany
    {
        return $this->hasMany(SystemCategory::class, 'slug', 'slug')
            ->where('asset_type', $this->asset_type)
            ->where('version', '<', $this->version)
            ->orderBy('version', 'desc');
    }

    /**
     * Get the latest version of this system category template.
     * Finds the SystemCategory with the same slug and asset_type but highest version.
     *
     * @return SystemCategory|null
     */
    public function getLatestVersion(): ?SystemCategory
    {
        return SystemCategory::where('slug', $this->slug)
            ->where('asset_type', $this->asset_type)
            ->orderBy('version', 'desc')
            ->first();
    }

    /**
     * Check if this is the latest version of the template.
     *
     * @return bool
     */
    public function isLatestVersion(): bool
    {
        $latest = $this->getLatestVersion();
        return $latest && $latest->id === $this->id;
    }
}
