<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Collection extends Model
{
    protected static function booted(): void
    {
        static::creating(function (Collection $collection) {
            if ($collection->access_mode === null || $collection->access_mode === '') {
                $collection->access_mode = match ($collection->visibility ?? 'brand') {
                    'restricted' => 'role_limited',
                    'private' => 'invite_only',
                    default => 'all_brand',
                };
            }
            if (in_array($collection->access_mode, ['role_limited', 'invite_only'], true)
                && ! array_key_exists('allows_external_guests', $collection->getAttributes())) {
                $collection->allows_external_guests = true;
            }
        });
    }

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
        'access_mode',
        'allowed_brand_roles',
        'allows_external_guests',
        'is_public',
        'public_share_token',
        'public_password_hash',
        'public_password_set_at',
        'public_downloads_enabled',
        'created_by',
        'public_zip_path',
        'public_zip_built_at',
        'public_zip_asset_count',
    ];

    /**
     * @return array<string, string>
     */
    /**
     * @var list<string>
     */
    protected $hidden = [
        'public_password_hash',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'allows_external_guests' => 'boolean',
            'allowed_brand_roles' => 'array',
            'public_password_set_at' => 'datetime',
            'public_downloads_enabled' => 'boolean',
            'public_zip_built_at' => 'datetime',
            'public_zip_asset_count' => 'integer',
        ];
    }

    public function sessionUnlockKey(): string
    {
        return 'collection_share_unlocked.'.$this->id;
    }

    public function isUnlockedInShareSession(): bool
    {
        return session()->get($this->sessionUnlockKey()) === true;
    }

    public function hasPublicPassword(): bool
    {
        return $this->public_password_hash !== null && $this->public_password_hash !== '';
    }

    /**
     * V1: Anonymous share pages require a password. Legacy rows with is_public and no hash
     * are not served until the owner sets a password (see controllers).
     */
    public function isShareableByGuests(): bool
    {
        return (bool) $this->is_public && $this->hasPublicPassword();
    }

    public function ensurePublicShareToken(): string
    {
        if ($this->public_share_token) {
            return $this->public_share_token;
        }
        do {
            $token = Str::lower(Str::random(40));
        } while (static::query()
            ->where('public_share_token', $token)
            ->when($this->exists, fn ($q) => $q->where('id', '!=', $this->id))
            ->exists());

        $this->public_share_token = $token;

        return $token;
    }

    public function publicShareUrl(): ?string
    {
        if (! $this->is_public || ! $this->public_share_token) {
            return null;
        }

        return route('share.collections.show', ['token' => $this->public_share_token], absolute: true);
    }

    /**
     * Mark the cached public ZIP as stale (needs rebuild on next download).
     */
    public function invalidatePublicZip(): void
    {
        $this->update([
            'public_zip_path' => null,
            'public_zip_built_at' => null,
            'public_zip_asset_count' => null,
        ]);
    }

    /**
     * Whether this collection has a current cached ZIP ready for download.
     */
    public function hasPublicZipCached(): bool
    {
        return $this->is_public
            && $this->public_zip_path !== null
            && $this->public_zip_built_at !== null;
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
     * Optional campaign identity layer for this collection.
     */
    public function campaignIdentity(): HasOne
    {
        return $this->hasOne(CollectionCampaignIdentity::class);
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
