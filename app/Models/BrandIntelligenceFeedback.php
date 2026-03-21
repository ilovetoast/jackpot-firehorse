<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandIntelligenceFeedback extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (BrandIntelligenceFeedback $m) {
            if ($m->created_at === null) {
                $m->created_at = now();
            }
        });
    }

    protected $fillable = [
        'asset_id',
        'tenant_id',
        'brand_id',
        'type',
        'rating',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
