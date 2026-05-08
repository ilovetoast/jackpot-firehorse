<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Phase 4 — Expiration cleanup for disposable demo tenants (DB + tenant-scoped object storage only).
 */
final class DemoWorkspaceCleanupService
{
    public function __construct(
        protected DemoWorkspaceAdminService $demoWorkspaceAdminService,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public function assertDisposableDemoInstance(Tenant $tenant): void
    {
        if ($tenant->is_demo_template) {
            throw new InvalidArgumentException('Cleanup refused: demo templates must never be auto-deleted.');
        }

        if (! $tenant->is_demo) {
            throw new InvalidArgumentException('Cleanup refused: tenant is not a demo workspace.');
        }

        if ($tenant->uuid === null || $tenant->uuid === '') {
            throw new InvalidArgumentException('Cleanup refused: tenant UUID is required to scope storage deletion.');
        }
    }

    /**
     * Admin “delete now”: only expired / archived disposable demos.
     */
    public function isManualDeleteEligible(Tenant $tenant, string $displayBadge): bool
    {
        return in_array($displayBadge, [
            DemoWorkspaceAdminService::BADGE_EXPIRED,
            DemoWorkspaceAdminService::BADGE_ARCHIVED,
        ], true);
    }

    /**
     * Whether this tenant would be selected by the scheduled cleanup query (respects grace, excludes in-flight clones).
     */
    public function passesScheduledCleanupRules(Tenant $tenant): bool
    {
        if (! $tenant->is_demo || $tenant->is_demo_template || $tenant->uuid === null || $tenant->uuid === '') {
            return false;
        }

        if (in_array($tenant->demo_status, ['pending', 'cloning'], true)) {
            return false;
        }

        $grace = max(0, (int) config('demo.cleanup_grace_days', 3));

        if ($tenant->demo_status === 'archived' && $tenant->updated_at instanceof CarbonInterface) {
            return $tenant->updated_at->lte(now()->subDays($grace));
        }

        if ($tenant->demo_status === 'expired' && $tenant->updated_at instanceof CarbonInterface) {
            return $tenant->updated_at->lte(now()->subDays($grace));
        }

        if ($tenant->demo_expires_at instanceof CarbonInterface) {
            $cutoffDate = now()->startOfDay()->subDays($grace)->toDateString();

            return $tenant->demo_expires_at->toDateString() <= $cutoffDate;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAdminCleanupPanel(Tenant $tenant, string $displayBadge): array
    {
        $grace = max(0, (int) config('demo.cleanup_grace_days', 3));
        $scheduled = $this->passesScheduledCleanupRules($tenant);
        $manual = $this->isManualDeleteEligible($tenant, $displayBadge);

        $note = 'Scheduled cleanup only processes disposable demos past grace, excluding pending/cloning.';
        if ($tenant->demo_expires_at instanceof CarbonInterface) {
            $note .= ' Expiry + grace: deletes on or after '.($tenant->demo_expires_at->copy()->startOfDay()->addDays($grace)->toDateString()).' (calendar days).';
        }

        return [
            'cleanup_enabled' => (bool) config('demo.cleanup_enabled'),
            'grace_days' => $grace,
            'chunk_size' => max(1, (int) config('demo.cleanup_chunk_size', 25)),
            'cleanup_dry_run' => (bool) config('demo.cleanup_dry_run'),
            'scheduled_eligible_now' => $scheduled,
            'scheduled_note' => $note,
            'manual_delete_eligible' => $manual,
            'storage_prefix' => 'tenants/'.$tenant->uuid.'/',
        ];
    }

    /**
     * @return Builder<Tenant>
     */
    public function scheduledEligibleQuery(): Builder
    {
        $grace = max(0, (int) config('demo.cleanup_grace_days', 3));
        $cutoffDate = now()->startOfDay()->subDays($grace)->toDateString();

        return Tenant::query()
            ->where('is_demo', true)
            ->where('is_demo_template', false)
            ->whereNotNull('uuid')
            ->where('uuid', '!=', '')
            ->whereNotIn('demo_status', ['pending', 'cloning'])
            ->where(function (Builder $q) use ($grace, $cutoffDate) {
                $q->where(function (Builder $q2) use ($grace) {
                    $q2->where('demo_status', 'archived')
                        ->where('updated_at', '<=', now()->subDays($grace));
                })->orWhere(function (Builder $q3) use ($cutoffDate) {
                    $q3->whereNotNull('demo_expires_at')
                        ->whereDate('demo_expires_at', '<=', $cutoffDate);
                })->orWhere(function (Builder $q4) use ($grace) {
                    $q4->where('demo_status', 'expired')
                        ->where('updated_at', '<=', now()->subDays($grace));
                });
            })
            ->orderBy('id');
    }

    /**
     * @return Collection<int, Tenant>
     */
    public function findScheduledCleanupBatch(): Collection
    {
        $chunk = max(1, (int) config('demo.cleanup_chunk_size', 25));

        return $this->scheduledEligibleQuery()->limit($chunk)->get();
    }

    /**
     * @return array{success: bool, dry_run: bool, message: string, tenant_id: ?int, storage_keys_removed: int}
     */
    public function cleanupTenant(Tenant $tenant, bool $dryRun, bool $adminBypassGrace): array
    {
        $tenantId = (int) $tenant->id;

        try {
            $this->assertDisposableDemoInstance($tenant);
        } catch (InvalidArgumentException $e) {
            Log::warning('demo.cleanup.refused', [
                'tenant_id' => $tenantId,
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'dry_run' => $dryRun,
                'message' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'storage_keys_removed' => 0,
            ];
        }

        $badge = $this->demoWorkspaceAdminService->resolveInstanceDisplayBadge($tenant);

        if ($adminBypassGrace) {
            if (! $this->isManualDeleteEligible($tenant, $badge)) {
                $msg = 'Manual delete is only allowed for expired or archived disposable demos.';

                return [
                    'success' => false,
                    'dry_run' => $dryRun,
                    'message' => $msg,
                    'tenant_id' => $tenantId,
                    'storage_keys_removed' => 0,
                ];
            }
        } elseif (! $this->passesScheduledCleanupRules($tenant)) {
            $msg = 'Tenant is not eligible for scheduled cleanup yet.';
            Log::info('demo.cleanup.skipped_not_eligible', ['tenant_id' => $tenantId]);

            return [
                'success' => false,
                'dry_run' => $dryRun,
                'message' => $msg,
                'tenant_id' => $tenantId,
                'storage_keys_removed' => 0,
            ];
        }

        $uuid = (string) $tenant->uuid;
        $prefix = 'tenants/'.$uuid;

        $disk = Storage::disk('s3');
        $keys = $disk->allFiles($prefix);
        $storageCount = count($keys);

        if ($dryRun) {
            Log::info('demo.cleanup.dry_run', [
                'tenant_id' => $tenantId,
                'storage_prefix' => $prefix.'/',
                'storage_object_count' => $storageCount,
            ]);

            return [
                'success' => true,
                'dry_run' => true,
                'message' => "Dry run: would remove {$storageCount} objects under {$prefix}/ and delete tenant #{$tenantId}.",
                'tenant_id' => $tenantId,
                'storage_keys_removed' => $storageCount,
            ];
        }

        $tenant->forceFill(['demo_cleanup_failure_message' => null])->save();

        try {
            $this->cancelSubscriptionsQuietly($tenant);

            if ($keys !== [] || $disk->exists($prefix)) {
                $disk->deleteDirectory($prefix);
            }

            $tenant->delete();

            Log::info('demo.cleanup.completed', [
                'tenant_id' => $tenantId,
                'storage_prefix' => $prefix.'/',
                'storage_object_count' => $storageCount,
                'admin_bypass_grace' => $adminBypassGrace,
            ]);

            return [
                'success' => true,
                'dry_run' => false,
                'message' => "Deleted demo tenant #{$tenantId} and removed {$storageCount} storage objects under {$prefix}/.",
                'tenant_id' => $tenantId,
                'storage_keys_removed' => $storageCount,
            ];
        } catch (Throwable $e) {
            $msg = Str::limit($e->getMessage(), 65000);
            $this->recordFailure($tenant, $msg);

            Log::error('demo.cleanup.failed', [
                'tenant_id' => $tenantId,
                'message' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);

            return [
                'success' => false,
                'dry_run' => false,
                'message' => $msg,
                'tenant_id' => $tenantId,
                'storage_keys_removed' => 0,
            ];
        }
    }

    /**
     * @return list<array{name: string, slug: string, success: bool, dry_run: bool, message: string, tenant_id: ?int, storage_keys_removed: int}>
     */
    public function runScheduledPass(bool $dryRun): array
    {
        $rows = [];
        foreach ($this->findScheduledCleanupBatch() as $tenant) {
            $rows[] = array_merge(
                ['name' => $tenant->name, 'slug' => $tenant->slug],
                $this->cleanupTenant($tenant, $dryRun, false),
            );
        }

        return $rows;
    }

    private function recordFailure(Tenant $tenant, string $message): void
    {
        try {
            if (! $tenant->exists) {
                return;
            }
            $tenant->forceFill([
                'demo_cleanup_failure_message' => Str::limit($message, 65000),
            ])->save();
        } catch (Throwable) {
            // ignore secondary failures
        }
    }

    private function cancelSubscriptionsQuietly(Tenant $tenant): void
    {
        try {
            $subscription = $tenant->subscription('default');
            if ($subscription && $subscription->active()) {
                $subscription->cancel();
            }
        } catch (Throwable $e) {
            Log::warning('demo.cleanup.subscription_cancel_failed', [
                'tenant_id' => $tenant->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
