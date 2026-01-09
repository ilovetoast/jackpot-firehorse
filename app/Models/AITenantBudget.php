<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI Tenant Budget Model
 *
 * Represents a tenant-level AI budget (preparation only).
 * This structure exists for future tenant AI cost controls.
 * Enforcement logic is NOT implemented in Phase 9.
 */
class AITenantBudget extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_tenant_budgets';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'amount',
        'period',
        'warning_threshold_percent',
        'hard_limit_enabled',
        'environment',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'warning_threshold_percent' => 'integer',
            'hard_limit_enabled' => 'boolean',
        ];
    }

    /**
     * Get the tenant this budget belongs to.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
