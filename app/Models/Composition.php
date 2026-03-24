<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Composition extends Model
{
    protected $fillable = [
        'tenant_id',
        'brand_id',
        'user_id',
        'name',
        'document_json',
        'thumbnail_asset_id',
    ];

    protected function casts(): array
    {
        return [
            'document_json' => 'array',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CompositionVersion::class)->orderByDesc('id');
    }

    public function thumbnailAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'thumbnail_asset_id');
    }
}
