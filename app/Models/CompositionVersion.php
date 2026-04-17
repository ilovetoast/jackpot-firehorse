<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompositionVersion extends Model
{
    public const KIND_MANUAL = 'manual';

    public const KIND_AUTOSAVE = 'autosave';

    public $timestamps = false;

    protected $fillable = [
        'composition_id',
        'document_json',
        'label',
        'kind',
        'thumbnail_asset_id',
        'created_at',
    ];

    protected $attributes = [
        'kind' => self::KIND_MANUAL,
    ];

    protected function casts(): array
    {
        return [
            'document_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function composition(): BelongsTo
    {
        return $this->belongsTo(Composition::class);
    }

    public function thumbnailAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'thumbnail_asset_id');
    }
}
