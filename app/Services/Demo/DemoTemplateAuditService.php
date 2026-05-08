<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Phase 2A — Read-only inspection of a demo template tenant before cloning exists.
 */
class DemoTemplateAuditService
{
    /**
     * @return array<string, mixed>
     */
    public function audit(Tenant $tenant): array
    {
        if (! $tenant->is_demo_template) {
            throw new InvalidArgumentException('Tenant must be marked as a demo template (is_demo_template = true).');
        }

        $tenantId = (int) $tenant->id;
        $brandIds = DB::table('brands')->where('tenant_id', $tenantId)->pluck('id')->all();

        $warnings = [];
        $unsupported = [];
        $missing = [];

        if (! empty($tenant->stripe_id)) {
            $warnings[] = 'Tenant has a Stripe customer id (stripe_id). Billing must not be cloned; clear or ignore for demo instances.';
        }

        $subCount = $this->countWhenTableExists('subscriptions', fn () => DB::table('subscriptions')->where('tenant_id', $tenantId)->count());
        if ($subCount > 0) {
            $warnings[] = "Found {$subCount} subscription row(s) — excluded from clone.";
        }

        $notificationsForMembers = $this->countNotificationsForTenantUsers($tenantId);
        if ($notificationsForMembers > 0) {
            $warnings[] = "Found {$notificationsForMembers} in-app notification row(s) for tenant members — excluded from clone.";
        }

        $unsupported[] = 'Users are global accounts: tenant_user and brand_user rows reference user_ids that cannot be copied verbatim into a new tenant; cloning must remap or provision new users.';
        $unsupported[] = 'Spatie permission roles are not tenant-scoped in this app (teams disabled); effective access comes from tenant_user.role, brand_user, and PermissionMap — not from cloning role pivot rows alone.';

        if ($tenant->uuid === null || $tenant->uuid === '') {
            $missing[] = 'Tenant uuid is missing (required for storage path isolation).';
        }

        $defaultBrands = DB::table('brands')->where('tenant_id', $tenantId)->where('is_default', true)->count();
        if ($defaultBrands === 0) {
            $missing[] = 'No default brand flagged (is_default) for this tenant.';
        }
        if ($defaultBrands > 1) {
            $warnings[] = 'Multiple brands marked is_default — unusual; verify before cloning.';
        }

        $assetsMissingCurrentVersion = $this->countAssetsMissingCurrentVersion($tenantId);
        if ($assetsMissingCurrentVersion > 0) {
            $missing[] = "{$assetsMissingCurrentVersion} non-deleted asset(s) have no current asset_versions row (is_current).";
        }

        $cloneReady = [
            'tenant_record' => 1,
            'brands' => DB::table('brands')->where('tenant_id', $tenantId)->count(),
            'tenant_user_memberships' => DB::table('tenant_user')->where('tenant_id', $tenantId)->count(),
            'brand_user_assignments' => $brandIds === []
                ? 0
                : (int) DB::table('brand_user')->whereIn('brand_id', $brandIds)->whereNull('removed_at')->count(),
            'assets_active' => DB::table('assets')->where('tenant_id', $tenantId)->whereNull('deleted_at')->count(),
            'assets_trashed' => DB::table('assets')->where('tenant_id', $tenantId)->whereNotNull('deleted_at')->count(),
            'asset_versions' => $this->countAssetVersionsForTenant($tenantId),
            'categories' => DB::table('categories')->where('tenant_id', $tenantId)->whereNull('deleted_at')->count(),
            'asset_tags' => $this->countAssetTagsForTenant($tenantId),
            'metadata_fields' => DB::table('metadata_fields')->where('tenant_id', $tenantId)->count(),
            'metadata_options' => $this->countMetadataOptionsForTenant($tenantId),
            'asset_metadata_values' => $this->countForAssetsSubquery('asset_metadata', $tenantId),
            'asset_metadata_candidates' => $this->countForAssetsSubquery('asset_metadata_candidates', $tenantId),
            'asset_tag_candidates' => $this->countForAssetsSubquery('asset_tag_candidates', $tenantId),
            'collections' => DB::table('collections')->where('tenant_id', $tenantId)->count(),
            'executions' => DB::table('executions')->where('tenant_id', $tenantId)->count(),
            'brand_models' => $brandIds === []
                ? 0
                : (int) DB::table('brand_models')->whereIn('brand_id', $brandIds)->count(),
            'brand_model_versions' => $this->countBrandModelVersions($brandIds),
            'brand_model_version_assets' => $this->countBrandModelVersionAssets($brandIds),
            'tenant_modules' => $this->countWhenTableExists('tenant_modules', fn () => DB::table('tenant_modules')->where('tenant_id', $tenantId)->count()),
            'storage_buckets' => $this->countWhenTableExists('storage_buckets', fn () => DB::table('storage_buckets')->where('tenant_id', $tenantId)->count()),
        ];

        $excluded = [
            'subscriptions' => $subCount,
            'ai_usage_rows' => $this->countWhenTableExists('ai_usage', fn () => DB::table('ai_usage')->where('tenant_id', $tenantId)->count()),
            'ai_agent_runs' => $this->countWhenTableExists('ai_agent_runs', fn () => DB::table('ai_agent_runs')->where('tenant_id', $tenantId)->count()),
            'activity_events' => $this->countWhenTableExists('activity_events', fn () => DB::table('activity_events')->where('tenant_id', $tenantId)->count()),
            'notifications_for_tenant_users' => $notificationsForMembers,
            'tenant_invitations' => $this->countWhenTableExists('tenant_invitations', fn () => DB::table('tenant_invitations')->where('tenant_id', $tenantId)->count()),
            'brand_invitations' => $brandIds === []
                ? 0
                : $this->countWhenTableExists('brand_invitations', fn () => (int) DB::table('brand_invitations')->whereIn('brand_id', $brandIds)->count()),
            'collection_invitations' => $this->countCollectionInvitations($tenantId),
            'downloads' => $this->countWhenTableExists('downloads', fn () => DB::table('downloads')->where('tenant_id', $tenantId)->count()),
            'tickets' => $this->countWhenTableExists('tickets', fn () => DB::table('tickets')->where('tenant_id', $tenantId)->count()),
            'upload_sessions' => $this->countWhenTableExists('upload_sessions', fn () => DB::table('upload_sessions')->where('tenant_id', $tenantId)->count()),
        ];

        if (($excluded['downloads'] ?? 0) > 0) {
            $warnings[] = 'Download/share link records reference tenant-scoped tokens and S3 snapshots — excluded from clone unless a future phase explicitly regenerates them.';
        }

        $storage = $this->buildStorageSummary($tenantId);

        if (($storage['asset_versions_with_file_path'] ?? 0) > 0) {
            $warnings[] = 'Asset versions reference file_path keys in object storage — cloning will require a future S3 copy phase (not implemented).';
        }

        return [
            'meta' => [
                'tenant_id' => $tenantId,
                'tenant_name' => $tenant->name,
                'tenant_slug' => $tenant->slug,
                'is_demo_template' => true,
                'audited_at' => Carbon::now()->toIso8601String(),
            ],
            'clone_ready' => $cloneReady,
            'excluded_from_clone' => $excluded,
            'warnings' => array_values(array_unique($warnings)),
            'unsupported_relationships' => $unsupported,
            'missing_required_data' => $missing,
            'storage' => $storage,
        ];
    }

    private function countWhenTableExists(string $table, callable $callback): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return (int) $callback();
    }

    /**
     * @param  list<int>  $brandIdList
     */
    private function countBrandModelVersions(array $brandIdList): int
    {
        if (! Schema::hasTable('brand_model_versions') || ! Schema::hasTable('brand_models')) {
            return 0;
        }
        if ($brandIdList === []) {
            return 0;
        }

        return (int) DB::table('brand_model_versions')
            ->join('brand_models', 'brand_model_versions.brand_model_id', '=', 'brand_models.id')
            ->whereIn('brand_models.brand_id', $brandIdList)
            ->count();
    }

    /**
     * DNA builder references to assets (clone-ready in principle; storage paths still need S3 copy).
     *
     * @param  list<int>  $brandIds
     */
    private function countBrandModelVersionAssets(array $brandIds): int
    {
        if (! Schema::hasTable('brand_model_version_assets') || $brandIds === []) {
            return 0;
        }

        return (int) DB::table('brand_model_version_assets')
            ->join('brand_model_versions', 'brand_model_version_assets.brand_model_version_id', '=', 'brand_model_versions.id')
            ->join('brand_models', 'brand_model_versions.brand_model_id', '=', 'brand_models.id')
            ->whereIn('brand_models.brand_id', $brandIds)
            ->count();
    }

    private function countAssetVersionsForTenant(int $tenantId): int
    {
        if (! Schema::hasTable('asset_versions')) {
            return 0;
        }

        return (int) DB::table('asset_versions')
            ->join('assets', 'asset_versions.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $tenantId)
            ->whereNull('asset_versions.deleted_at')
            ->whereNull('assets.deleted_at')
            ->count();
    }

    private function countAssetTagsForTenant(int $tenantId): int
    {
        if (! Schema::hasTable('asset_tags')) {
            return 0;
        }

        return (int) DB::table('asset_tags')
            ->join('assets', 'asset_tags.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $tenantId)
            ->whereNull('assets.deleted_at')
            ->count();
    }

    private function countForAssetsSubquery(string $table, int $tenantId): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return (int) DB::table($table)
            ->join('assets', $table.'.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $tenantId)
            ->whereNull('assets.deleted_at')
            ->count();
    }

    private function countMetadataOptionsForTenant(int $tenantId): int
    {
        if (! Schema::hasTable('metadata_options') || ! Schema::hasTable('metadata_fields')) {
            return 0;
        }

        return (int) DB::table('metadata_options')
            ->join('metadata_fields', 'metadata_options.metadata_field_id', '=', 'metadata_fields.id')
            ->where('metadata_fields.tenant_id', $tenantId)
            ->count();
    }

    private function countAssetsMissingCurrentVersion(int $tenantId): int
    {
        if (! Schema::hasTable('asset_versions')) {
            return 0;
        }

        return (int) DB::table('assets')
            ->where('assets.tenant_id', $tenantId)
            ->whereNull('assets.deleted_at')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw('1'))
                    ->from('asset_versions')
                    ->whereColumn('asset_versions.asset_id', 'assets.id')
                    ->whereNull('asset_versions.deleted_at')
                    ->where('asset_versions.is_current', true);
            })
            ->count();
    }

    /**
     * @return array<string, int|string|null>
     */
    private function buildStorageSummary(int $tenantId): array
    {
        $versionsWithPath = 0;
        if (Schema::hasTable('asset_versions')) {
            $versionsWithPath = (int) DB::table('asset_versions')
                ->join('assets', 'asset_versions.asset_id', '=', 'assets.id')
                ->where('assets.tenant_id', $tenantId)
                ->whereNull('assets.deleted_at')
                ->whereNull('asset_versions.deleted_at')
                ->whereNotNull('asset_versions.file_path')
                ->where('asset_versions.file_path', '!=', '')
                ->count();
        }

        $assetsWithRoot = 0;
        if (Schema::hasTable('assets')) {
            $assetsWithRoot = (int) DB::table('assets')
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->whereNotNull('storage_root_path')
                ->where('storage_root_path', '!=', '')
                ->count();
        }

        $assetsWithBucket = 0;
        if (Schema::hasTable('assets')) {
            $assetsWithBucket = (int) DB::table('assets')
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->whereNotNull('storage_bucket_id')
                ->count();
        }

        $samplePath = null;
        if (Schema::hasTable('asset_versions')) {
            $samplePath = DB::table('asset_versions')
                ->join('assets', 'asset_versions.asset_id', '=', 'assets.id')
                ->where('assets.tenant_id', $tenantId)
                ->whereNull('assets.deleted_at')
                ->whereNotNull('asset_versions.file_path')
                ->orderBy('asset_versions.id')
                ->value('asset_versions.file_path');
        }

        return [
            'asset_versions_with_file_path' => $versionsWithPath,
            'assets_with_storage_root_path' => $assetsWithRoot,
            'assets_with_storage_bucket_id' => $assetsWithBucket,
            'sample_file_path' => $samplePath ? (string) $samplePath : null,
        ];
    }

    private function countNotificationsForTenantUsers(int $tenantId): int
    {
        if (! Schema::hasTable('notifications') || ! Schema::hasTable('tenant_user')) {
            return 0;
        }

        $userIds = DB::table('tenant_user')->where('tenant_id', $tenantId)->pluck('user_id')->unique()->all();
        if ($userIds === []) {
            return 0;
        }

        return (int) DB::table('notifications')->whereIn('user_id', $userIds)->count();
    }

    private function countCollectionInvitations(int $tenantId): int
    {
        if (! Schema::hasTable('collection_invitations') || ! Schema::hasTable('collections')) {
            return 0;
        }

        return (int) DB::table('collection_invitations')
            ->join('collections', 'collection_invitations.collection_id', '=', 'collections.id')
            ->where('collections.tenant_id', $tenantId)
            ->count();
    }
}
