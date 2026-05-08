<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\Asset;
use App\Models\Collection;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\URL;

/**
 * Admin/sales UI helpers for disposable demo workspaces (Phase 3).
 */
final class DemoWorkspaceAdminService
{
    public const string BADGE_PENDING = 'pending';

    public const string BADGE_CLONING = 'cloning';

    public const string BADGE_ACTIVE = 'active';

    public const string BADGE_EXPIRED = 'expired';

    public const string BADGE_FAILED = 'failed';

    public const string BADGE_ARCHIVED = 'archived';

    public const string SCOPE_ALL = 'all';

    public const string SCOPE_ACTIVE = 'active';

    public const string SCOPE_EXPIRED = 'expired';

    public const string SCOPE_FAILED = 'failed';

    public const string SCOPE_ARCHIVED = 'archived';

    public const string SCOPE_IN_PROGRESS = 'in_progress';

    /**
     * @return list<string>
     */
    public static function instanceFilterScopes(): array
    {
        return [
            self::SCOPE_ALL,
            self::SCOPE_ACTIVE,
            self::SCOPE_EXPIRED,
            self::SCOPE_FAILED,
            self::SCOPE_ARCHIVED,
            self::SCOPE_IN_PROGRESS,
        ];
    }

    /**
     * Badge for a demo instance row (not templates).
     */
    public function resolveInstanceDisplayBadge(Tenant $tenant): string
    {
        if ($tenant->is_demo_template || ! $tenant->is_demo) {
            return self::BADGE_ACTIVE;
        }

        if ($tenant->demo_status === 'archived') {
            return self::BADGE_ARCHIVED;
        }

        if ($tenant->demo_status === 'failed') {
            return self::BADGE_FAILED;
        }

        if ($tenant->demo_status === 'expired' || $this->isInstancePastExpirationDate($tenant)) {
            return self::BADGE_EXPIRED;
        }

        if ($tenant->demo_status === 'cloning') {
            return self::BADGE_CLONING;
        }

        if ($tenant->demo_status === 'pending') {
            return self::BADGE_PENDING;
        }

        return self::BADGE_ACTIVE;
    }

    /**
     * True when the calendar day is past the demo expiry day (expiry day inclusive).
     */
    public function isInstancePastExpirationDate(Tenant $tenant): bool
    {
        if (! $tenant->demo_expires_at instanceof CarbonInterface) {
            return false;
        }

        return now()->startOfDay()->gt($tenant->demo_expires_at->copy()->startOfDay());
    }

    /**
     * Gateway URL for members to sign in / pick this company (when the workspace is usable).
     */
    public function demoAccessUrl(?Tenant $tenant): ?string
    {
        if ($tenant === null || ! $tenant->is_demo || $tenant->is_demo_template || $tenant->slug === '') {
            return null;
        }

        $badge = $this->resolveInstanceDisplayBadge($tenant);
        if ($badge !== self::BADGE_ACTIVE) {
            return null;
        }

        return URL::to('/gateway?tenant='.rawurlencode((string) $tenant->slug));
    }

    /**
     * @param  Builder<Tenant>  $query
     */
    public function applyInstanceFilters(
        Builder $query,
        string $scope,
        ?string $planKey,
        ?int $createdByUserId,
    ): void {
        if ($planKey !== null && $planKey !== '') {
            $query->where('demo_plan_key', $planKey);
        }

        if ($createdByUserId !== null && $createdByUserId > 0) {
            $query->where('demo_created_by_user_id', $createdByUserId);
        }

        $scope = $scope === '' ? self::SCOPE_ALL : $scope;

        match ($scope) {
            self::SCOPE_ACTIVE => $query->where('demo_status', 'active')
                ->where(function (Builder $q): void {
                    $q->whereNull('demo_expires_at')
                        ->orWhere('demo_expires_at', '>=', now()->startOfDay());
                }),
            self::SCOPE_EXPIRED => $query->where(function (Builder $q): void {
                $q->where('demo_status', 'expired')
                    ->orWhere(function (Builder $q2): void {
                        $q2->where('demo_status', 'active')
                            ->whereNotNull('demo_expires_at')
                            ->where('demo_expires_at', '<', now()->startOfDay());
                    });
            }),
            self::SCOPE_FAILED => $query->where('demo_status', 'failed'),
            self::SCOPE_ARCHIVED => $query->where('demo_status', 'archived'),
            self::SCOPE_IN_PROGRESS => $query->whereIn('demo_status', ['pending', 'cloning']),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDetailPayload(Tenant $tenant): array
    {
        $tenant->loadMissing(['demoTemplate:id,name,slug', 'demoCreatedByUser:id,first_name,last_name,email']);

        $badge = $this->resolveInstanceDisplayBadge($tenant);

        $buckets = StorageBucket::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->get(['id', 'name', 'region', 'status']);

        $diskBucket = config('filesystems.disks.s3.bucket');

        $users = $tenant->users()
            ->orderBy('email')
            ->get(['users.id', 'users.email', 'users.first_name', 'users.last_name'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'email' => $u->email,
                'name' => trim(($u->first_name.' '.$u->last_name)) ?: $u->email,
                'tenant_role' => $u->pivot->role ?? null,
            ])
            ->values()
            ->all();

        return [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'uuid' => $tenant->uuid,
                'created_at' => $tenant->created_at?->toIso8601String(),
                'updated_at' => $tenant->updated_at?->toIso8601String(),
                'demo_label' => $tenant->demo_label,
                'demo_plan_key' => $tenant->demo_plan_key,
                'demo_status' => $tenant->demo_status,
                'demo_expires_at' => $tenant->demo_expires_at?->toIso8601String(),
                'demo_notes' => $tenant->demo_notes,
                'demo_clone_failure_message' => $tenant->demo_clone_failure_message,
                'demo_cleanup_failure_message' => $tenant->demo_cleanup_failure_message,
                'billing_status' => $tenant->billing_status,
                'manual_plan_override' => $tenant->manual_plan_override,
            ],
            'display_badge' => $badge,
            'demo_template' => $tenant->demoTemplate ? [
                'id' => $tenant->demoTemplate->id,
                'name' => $tenant->demoTemplate->name,
                'slug' => $tenant->demoTemplate->slug,
            ] : null,
            'created_by' => $tenant->demoCreatedByUser ? [
                'id' => $tenant->demoCreatedByUser->id,
                'name' => trim(($tenant->demoCreatedByUser->first_name.' '.$tenant->demoCreatedByUser->last_name)) ?: $tenant->demoCreatedByUser->email,
                'email' => $tenant->demoCreatedByUser->email,
            ] : null,
            'counts' => [
                'brands' => $tenant->brands()->count(),
                'assets' => Asset::query()->where('tenant_id', $tenant->id)->count(),
                'collections' => Collection::query()->where('tenant_id', $tenant->id)->count(),
                'users' => count($users),
            ],
            'storage' => [
                'tenant_uuid' => $tenant->uuid,
                'object_key_prefix' => $tenant->uuid ? 'tenants/'.$tenant->uuid.'/' : null,
                'config_bucket' => is_string($diskBucket) ? $diskBucket : null,
                'dedicated_bucket' => $tenant->storage_bucket,
                'storage_mode' => $tenant->storage_mode,
                'buckets' => $buckets->map(fn (StorageBucket $b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    'region' => $b->region,
                    'status' => $b->status->value ?? (string) $b->status,
                ])->values()->all(),
            ],
            'users' => $users,
            'demo_access_url' => $this->demoAccessUrl($tenant),
            'timeline' => $this->buildTimeline($tenant, $badge),
            'cleanup' => app(DemoWorkspaceCleanupService::class)->buildAdminCleanupPanel($tenant, $badge),
            'actions' => [
                'can_expire' => ! in_array($tenant->demo_status, ['archived', 'failed'], true),
                'can_extend' => in_array($badge, [self::BADGE_ACTIVE, self::BADGE_EXPIRED], true)
                    && ! in_array($tenant->demo_status, ['archived', 'failed', 'pending', 'cloning'], true),
                'can_archive_failed' => $tenant->demo_status === 'failed',
                'can_delete_now' => app(DemoWorkspaceCleanupService::class)->isManualDeleteEligible($tenant, $badge),
            ],
        ];
    }

    /**
     * @return list<array{label: string, at: ?string, detail?: string}>
     */
    private function buildTimeline(Tenant $tenant, string $badge): array
    {
        $rows = [
            [
                'label' => 'Demo tenant record created',
                'at' => $tenant->created_at?->toIso8601String(),
            ],
            [
                'label' => 'Current lifecycle',
                'at' => null,
                'detail' => $badge,
            ],
            [
                'label' => 'Last database update',
                'at' => $tenant->updated_at?->toIso8601String(),
            ],
        ];

        if ($tenant->demo_clone_failure_message && $badge === self::BADGE_FAILED) {
            $rows[] = [
                'label' => 'Clone failure captured',
                'at' => $tenant->updated_at?->toIso8601String(),
                'detail' => 'See failure message below',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{id: int, name: string, email: string}>
     */
    public function listDemoCreatorOptions(): array
    {
        $ids = Tenant::query()
            ->where('is_demo', true)
            ->whereNotNull('demo_created_by_user_id')
            ->distinct()
            ->pluck('demo_created_by_user_id')
            ->filter()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->orderBy('email')
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => trim(($u->first_name.' '.$u->last_name)) ?: $u->email,
                'email' => $u->email,
            ])
            ->values()
            ->all();
    }
}
