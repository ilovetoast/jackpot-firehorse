<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Cashier\Billable;

class Tenant extends Model
{
    use Billable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::created(function ($tenant) {
            // Automatically create a default brand when tenant is created
            $tenant->brands()->create([
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'is_default' => true,
            ]);
        });
    }

    /**
     * Get the users that belong to this tenant.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Get the brands for this tenant.
     */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    /**
     * Get the default brand for this tenant.
     */
    public function defaultBrand(): HasOne
    {
        return $this->hasOne(Brand::class)->where('is_default', true);
    }

    /**
     * Get the categories for this tenant.
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }
}
