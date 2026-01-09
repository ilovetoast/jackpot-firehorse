<?php

namespace App\Models;

use App\Enums\UploadStatus;
use App\Enums\UploadType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class UploadSession extends Model
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
            'status' => UploadStatus::class,
            'type' => UploadType::class,
            'file_size' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * Get the tenant that owns this upload session.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the brand that owns this upload session.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Get the storage bucket for this upload session.
     */
    public function storageBucket(): BelongsTo
    {
        return $this->belongsTo(StorageBucket::class);
    }

    /**
     * Get the assets created from this upload session.
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
