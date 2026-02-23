<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Persisted Sentry issue (system-level; no tenant).
 */
class SentryIssue extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'sentry_issues';

    protected $fillable = [
        'sentry_issue_id',
        'environment',
        'level',
        'title',
        'fingerprint',
        'occurrence_count',
        'first_seen',
        'last_seen',
        'stack_trace',
        'ai_summary',
        'ai_root_cause',
        'ai_fix_suggestion',
        'status',
        'selected_for_heal',
        'confirmed_for_heal',
        'auto_heal_attempted',
        'ai_token_input',
        'ai_token_output',
        'ai_cost',
        'ai_analyzed_at',
    ];

    protected $casts = [
        'occurrence_count' => 'integer',
        'first_seen' => 'datetime',
        'last_seen' => 'datetime',
        'selected_for_heal' => 'boolean',
        'confirmed_for_heal' => 'boolean',
        'auto_heal_attempted' => 'boolean',
        'ai_token_input' => 'integer',
        'ai_token_output' => 'integer',
        'ai_cost' => 'decimal:4',
        'ai_analyzed_at' => 'datetime',
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopeEnvironment(Builder $query, string $env): Builder
    {
        return $query->where('environment', $env);
    }
}
