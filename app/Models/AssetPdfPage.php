<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetPdfPage extends Model
{
    protected $fillable = [
        'tenant_id',
        'asset_id',
        'asset_version_id',
        'version_number',
        'page_number',
        'storage_path',
        'width',
        'height',
        'size_bytes',
        'mime_type',
        'status',
        'error',
        'rendered_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'version_number' => 'integer',
            'page_number' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'size_bytes' => 'integer',
            'rendered_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function assetVersion(): BelongsTo
    {
        return $this->belongsTo(AssetVersion::class);
    }
}
