<?php

namespace App\Models;

use App\Enums\EventType;
use App\Enums\AITaskType;
use App\Traits\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * AI Agent Run Model
 *
 * Tracks individual AI agent executions for cost tracking and audit logging.
 * This is the primary unit of cost attribution and provides comprehensive
 * audit trail for all AI operations in the system.
 *
 * Why track agent runs?
 * - Cost attribution: Track costs per tenant, user, agent, or task type
 * - Audit trail: Immutable record of all AI operations
 * - Performance monitoring: Duration, token usage, success rates
 * - Compliance: Full accountability for automated actions
 *
 * Cost Attribution:
 * - System context: No tenant attribution (system-level cost)
 * - Tenant context: Cost attributed to tenant_id
 * - User context: Cost attributed to both tenant_id and user_id
 *
 * All agent runs are logged to activity events via RecordsActivity trait
 * for integration with existing audit logging infrastructure.
 */
class AIAgentRun extends Model
{
    use RecordsActivity;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_agent_runs';

    /**
     * Custom event names for activity logging.
     */
    protected static $activityEventNames = [
        'created' => EventType::AI_AGENT_RUN_STARTED,
        'updated' => EventType::AI_AGENT_RUN_COMPLETED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'agent_id',
        'triggering_context',
        'environment',
        'tenant_id',
        'user_id',
        'task_type',
        'model_used',
        'tokens_in',
        'tokens_out',
        'estimated_cost',
        'status',
        'error_message',
        'blocked_reason',
        'metadata',
        'started_at',
        'completed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
            'estimated_cost' => 'decimal:6',
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
        ];
    }

    /**
     * Get the tenant that this agent run is associated with.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the user that triggered this agent run (if applicable).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all tickets that link to this AI agent run.
     */
    /**
     * Get all tickets that link to this AI agent run.
     */
    public function tickets(): MorphMany
    {
        return $this->morphMany(\App\Models\TicketLink::class, 'linkable');
    }

    /**
     * Get the duration of the agent run in seconds.
     *
     * @return int|null Duration in seconds, or null if not completed
     */
    public function getDurationAttribute(): ?int
    {
        if (!$this->started_at || !$this->completed_at) {
            return null;
        }

        return $this->started_at->diffInSeconds($this->completed_at);
    }

    /**
     * Get formatted duration string (e.g., "1.5s", "2m 30s").
     *
     * @return string|null
     */
    public function getFormattedDurationAttribute(): ?string
    {
        $duration = $this->duration;
        if ($duration === null) {
            return null;
        }

        if ($duration < 60) {
            return "{$duration}s";
        }

        $minutes = floor($duration / 60);
        $seconds = $duration % 60;

        if ($seconds === 0) {
            return "{$minutes}m";
        }

        return "{$minutes}m {$seconds}s";
    }

    /**
     * Scope a query to only include successful runs.
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', 'success');
    }

    /**
     * Scope a query to only include failed runs.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope a query to filter by agent ID.
     */
    public function scopeForAgent(Builder $query, string $agentId): Builder
    {
        return $query->where('agent_id', $agentId);
    }

    /**
     * Scope a query to filter by task type.
     */
    public function scopeForTask(Builder $query, string $taskType): Builder
    {
        return $query->where('task_type', $taskType);
    }

    /**
     * Scope a query to filter by triggering context.
     */
    public function scopeForContext(Builder $query, string $context): Builder
    {
        return $query->where('triggering_context', $context);
    }

    /**
     * Scope a query to filter by tenant.
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope a query to filter by user.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to filter by model used.
     */
    public function scopeForModel(Builder $query, string $modelName): Builder
    {
        return $query->where('model_used', $modelName);
    }

    /**
     * Scope a query to only include runs within a date range.
     */
    public function scopeInDateRange(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->whereBetween('started_at', [$start, $end]);
    }

    /**
     * Scope a query to only include runs older than retention period.
     * Used by PruneAILogs command for cleanup.
     */
    public function scopeOlderThan(Builder $query, int $days): Builder
    {
        $cutoffDate = Carbon::now()->subDays($days);
        return $query->where('started_at', '<', $cutoffDate);
    }

    /**
     * Check if the agent run is for a system context.
     */
    public function isSystemContext(): bool
    {
        return $this->triggering_context === 'system';
    }

    /**
     * Check if the agent run is for a tenant context.
     */
    public function isTenantContext(): bool
    {
        return $this->triggering_context === 'tenant';
    }

    /**
     * Check if the agent run is for a user context.
     */
    public function isUserContext(): bool
    {
        return $this->triggering_context === 'user';
    }

    /**
     * Check if the run was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }

    /**
     * Check if the run failed.
     */
    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Get total tokens used (input + output).
     */
    public function getTotalTokensAttribute(): int
    {
        return $this->tokens_in + $this->tokens_out;
    }

    /**
     * Mark the agent run as completed with success.
     */
    public function markAsSuccessful(int $tokensIn, int $tokensOut, float $estimatedCost, ?array $metadata = null): void
    {
        $this->update([
            'status' => 'success',
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'estimated_cost' => $estimatedCost,
            'completed_at' => now(),
            'metadata' => $metadata ?? $this->metadata,
        ]);
    }

    /**
     * Mark the agent run as failed.
     */
    public function markAsFailed(string $errorMessage, ?array $metadata = null): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'completed_at' => now(),
            'metadata' => $metadata ?? $this->metadata,
        ]);
    }
}
