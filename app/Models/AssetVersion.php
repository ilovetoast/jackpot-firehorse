<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase 1A: Asset versioning.
 *
 * Tracks file versions per asset. One is_current per asset (enforced at service layer).
 */
class AssetVersion extends Model
{
    use SoftDeletes;

    protected $table = 'asset_versions';

    /**
     * UUID primary key - prevent Laravel from casting to integer (which would turn UUID to 0).
     */
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'asset_id',
        'version_number',
        'file_path',
        'file_size',
        'mime_type',
        'width',
        'height',
        'checksum',
        'uploaded_by',
        'change_note',
        'pipeline_status',
        'is_current',
        'restored_from_version_id',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'version_number' => 'integer',
            'is_current' => 'boolean',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get metadata rows for this version (Phase 3B).
     */
    public function metadata(): HasMany
    {
        return $this->hasMany(AssetMetadata::class, 'asset_version_id');
    }
}
