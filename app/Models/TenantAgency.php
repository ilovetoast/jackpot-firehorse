<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class TenantAgency extends Model
{
    protected $fillable = [
        'tenant_id',
        'agency_tenant_id',
        'role',
        'brand_assignments',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'brand_assignments' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function agencyTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'agency_tenant_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Brand assignments with human-readable names for API / Inertia.
     *
     * @return array<int, array{brand_id: int, role: string, brand_name: string|null}>
     */
    public function brandAssignmentsWithLabels(): array
    {
        $assignments = $this->brand_assignments ?? [];
        if (! is_array($assignments) || $assignments === []) {
            return [];
        }

        $ids = Collection::make($assignments)
            ->pluck('brand_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $names = Brand::query()
            ->where('tenant_id', $this->tenant_id)
            ->whereIn('id', $ids)
            ->pluck('name', 'id');

        return Collection::make($assignments)
            ->map(function ($a) use ($names) {
                $bid = (int) ($a['brand_id'] ?? 0);
                $role = $a['role'] ?? 'contributor';

                return [
                    'brand_id' => $bid,
                    'role' => is_string($role) ? strtolower($role) : 'contributor',
                    'brand_name' => $names[$bid] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $at = $this->agencyTenant;

        return [
            'id' => $this->id,
            'agency_tenant' => $at ? [
                'id' => $at->id,
                'name' => $at->name,
                'slug' => $at->slug,
            ] : [
                'id' => $this->agency_tenant_id,
                'name' => '',
                'slug' => '',
            ],
            'role' => $this->role,
            'brand_assignments' => $this->brandAssignmentsWithLabels(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
