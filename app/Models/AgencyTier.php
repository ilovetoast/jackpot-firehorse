<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgencyTier extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'tier_order',
        'activation_threshold', // Phase AG-6: Admin-configurable
        'reward_percentage', // Phase AG-6: Admin-configurable (not enforced yet)
        'max_incubated_companies', // Phase AG-6: Admin-configurable (not enforced yet)
        'max_incubated_brands', // Phase AG-6: Admin-configurable (not enforced yet)
        'incubation_window_days', // Phase AG-6: Admin-configurable (not enforced yet)
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activation_threshold' => 'integer',
            'reward_percentage' => 'decimal:2',
            'max_incubated_companies' => 'integer',
            'max_incubated_brands' => 'integer',
            'incubation_window_days' => 'integer',
        ];
    }

    /**
     * Get the tenants that belong to this agency tier.
     */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'agency_tier_id');
    }
}
