<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompositionVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'composition_id',
        'document_json',
        'label',
        'thumbnail_path',
        'created_at',
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
}
