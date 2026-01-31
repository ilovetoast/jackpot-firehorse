<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Collection extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'brand_id',
        'name',
        'slug',
        'description',
        'visibility',
        'is_public',
        'created_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    /**
     * Get the tenant that owns the collection.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the brand that owns the collection.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Get the user who created the collection.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the assets in the collection.
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_collections')
            ->using(AssetCollection::class)
            ->withTimestamps();
    }

    /**
     * Get the collection members (brand-related; see CollectionMember).
     */
    public function members(): HasMany
    {
        return $this->hasMany(CollectionMember::class);
    }

    /**
     * Phase C12.0: Collection access grants (collection-only access, NOT brand membership).
     */
    public function collectionAccessGrants(): HasMany
    {
        return $this->hasMany(CollectionUser::class, 'collection_id');
    }

    /**
     * Phase C12.0: Pending collection-only invites (by email).
     */
    public function collectionInvitations(): HasMany
    {
        return $this->hasMany(CollectionInvitation::class, 'collection_id');
    }
}
