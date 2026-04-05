<?php

namespace App\Models;

use App\Support\Roles\RoleRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class ProstaffMembership extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'brand_id',
        'user_id',
        'status',
        'target_uploads',
        'period_type',
        'period_start',
        'requires_approval',
        'custom_fields',
        'assigned_by_user_id',
        'started_at',
        'ended_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_approval' => 'boolean',
            'period_start' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'custom_fields' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ProstaffMembership $membership): void {
            $membership->assertEligibilityRules();
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Enforce Phase 1 rules: tenant + brand alignment, tenant membership, active brand_user,
     * and brand role not admin / brand_manager.
     *
     * @throws \InvalidArgumentException
     */
    public function assertEligibilityRules(): void
    {
        $brand = Brand::query()->find($this->brand_id);
        if (! $brand) {
            throw new \InvalidArgumentException('Prostaff membership requires a valid brand_id.');
        }

        if ((int) $this->tenant_id !== (int) $brand->tenant_id) {
            throw new \InvalidArgumentException(
                'prostaff_memberships.tenant_id must match the brand\'s tenant_id.'
            );
        }

        $user = User::query()->find($this->user_id);
        if (! $user) {
            throw new \InvalidArgumentException('Prostaff membership requires a valid user_id.');
        }

        if (! $user->belongsToTenant($brand->tenant_id)) {
            throw new \InvalidArgumentException(
                'User must belong to the brand\'s tenant before prostaff assignment.'
            );
        }

        $pivot = DB::table('brand_user')
            ->where('user_id', $user->id)
            ->where('brand_id', $brand->id)
            ->whereNull('removed_at')
            ->first();

        if (! $pivot) {
            throw new \InvalidArgumentException(
                'User must have an active brand_user row for this brand.'
            );
        }

        $role = $pivot->role ?? null;
        if (! $role || ! RoleRegistry::isValidBrandRole($role)) {
            throw new \InvalidArgumentException(
                'User has no valid active brand role for this brand.'
            );
        }

        if (RoleRegistry::isBrandApproverRole($role)) {
            throw new \InvalidArgumentException(
                'Prostaff cannot be assigned to users with brand roles admin or brand_manager.'
            );
        }

        if (strtolower((string) $role) !== 'contributor') {
            throw new \InvalidArgumentException(
                'Prostaff membership requires brand role contributor.'
            );
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}
