<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProstaffUploadBatch extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'brand_id',
        'prostaff_user_id',
        'batch_key',
        'upload_count',
        'first_asset_id',
        'last_asset_id',
        'started_at',
        'last_activity_at',
        'processed_at',
        'notifications_sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'processed_at' => 'datetime',
            'notifications_sent_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function prostaffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prostaff_user_id');
    }

    public function firstAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'first_asset_id');
    }

    public function lastAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'last_asset_id');
    }
}
