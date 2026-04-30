<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIStudioPlatformFeatureToggle extends Model
{
    protected $table = 'ai_studio_platform_feature_toggles';

    protected $fillable = [
        'feature_key',
        'environment',
        'enabled',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function scopeForFeature(Builder $query, string $featureKey): Builder
    {
        return $query->where('feature_key', $featureKey);
    }
}
