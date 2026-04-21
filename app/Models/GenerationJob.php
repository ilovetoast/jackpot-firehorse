<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GenerationJob extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'creative_set_id',
        'user_id',
        'status',
        'axis_snapshot',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'axis_snapshot' => 'array',
            'meta' => 'array',
        ];
    }

    public function creativeSet(): BelongsTo
    {
        return $this->belongsTo(CreativeSet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(GenerationJobItem::class, 'generation_job_id');
    }

    /**
     * Derive aggregate job status from item rows (called after each item finishes).
     */
    public function refreshStatusFromItems(): void
    {
        $this->unsetRelation('items');

        $pending = $this->items()->where('status', GenerationJobItem::STATUS_PENDING)->exists();
        $running = $this->items()->where('status', GenerationJobItem::STATUS_RUNNING)->exists();

        if ($pending || $running) {
            $this->update(['status' => self::STATUS_RUNNING]);

            return;
        }

        $failed = $this->items()
            ->where('status', GenerationJobItem::STATUS_FAILED)
            ->whereNull('superseded_at')
            ->exists();
        $meta = is_array($this->meta) ? $this->meta : [];
        $meta['had_failures'] = $failed;
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'meta' => $meta,
        ]);
    }
}
