<?php

namespace App\Models;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'brand_id',
        'upload_session_id',
        'storage_bucket_id',
        'status',
        'type',
        'file_name',
        'file_size',
        'mime_type',
        'path',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AssetStatus::class,
            'type' => AssetType::class,
            'file_size' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * Get the tenant that owns this asset.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the brand that owns this asset.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Get the upload session that created this asset.
     */
    public function uploadSession(): BelongsTo
    {
        return $this->belongsTo(UploadSession::class);
    }

    /**
     * Get the storage bucket for this asset.
     */
    public function storageBucket(): BelongsTo
    {
        return $this->belongsTo(StorageBucket::class);
    }

    /**
     * Get the events for this asset.
     */
    public function events(): HasMany
    {
        return $this->hasMany(AssetEvent::class);
    }
}
