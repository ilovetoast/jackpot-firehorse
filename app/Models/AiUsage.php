<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI Usage Model
 *
 * Tracks AI usage by feature (tagging, suggestions) per tenant per day.
 * Used to enforce monthly caps and prevent runaway AI costs.
 */
class AiUsage extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_usage';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'feature',
        'usage_date',
        'call_count',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'call_count' => 'integer',
        ];
    }

    /**
     * Get the tenant that owns this usage record.
     *
     * @return BelongsTo
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
