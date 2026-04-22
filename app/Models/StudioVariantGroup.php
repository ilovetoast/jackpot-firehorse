<?php

namespace App\Models;

use App\Enums\StudioVariantGroupType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class StudioVariantGroup extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'brand_id',
        'source_composition_id',
        'source_composition_version_id',
        'creative_set_id',
        'type',
        'label',
        'status',
        'settings_json',
        'target_spec_json',
        'shared_mask_asset_id',
        'sort_order',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => StudioVariantGroupType::class,
            'settings_json' => 'array',
            'target_spec_json' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StudioVariantGroup $g): void {
            if (empty($g->uuid)) {
                $g->uuid = (string) Str::uuid();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function sourceComposition(): BelongsTo
    {
        return $this->belongsTo(Composition::class, 'source_composition_id');
    }

    public function sourceCompositionVersion(): BelongsTo
    {
        return $this->belongsTo(CompositionVersion::class, 'source_composition_version_id');
    }

    public function creativeSet(): BelongsTo
    {
        return $this->belongsTo(CreativeSet::class, 'creative_set_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(StudioVariantGroupMember::class, 'studio_variant_group_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
