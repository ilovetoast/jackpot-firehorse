<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per in-app Help AI ask (diagnostics + optional user feedback).
 */
class HelpAiQuestion extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'brand_id',
        'question',
        'response_kind',
        'matched_action_keys',
        'best_score',
        'confidence',
        'recommended_action_key',
        'agent_run_id',
        'cost',
        'tokens_in',
        'tokens_out',
        'feedback_rating',
        'feedback_note',
        'feedback_submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'matched_action_keys' => 'array',
            'best_score' => 'integer',
            'agent_run_id' => 'integer',
            'cost' => 'decimal:8',
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'feedback_submitted_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
