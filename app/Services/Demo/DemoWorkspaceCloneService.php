<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 2C — Clone template tenant content into a new disposable demo tenant (DB + storage).
 */
class DemoWorkspaceCloneService
{
    public function __construct(
        protected DemoTenantStorageCopyService $storageCopy,
    ) {}

    /**
     * @param  list<string>  $invitedEmails  Normalized unique emails; non-empty recommended.
     */
    public function cloneFromTemplate(
        Tenant $template,
        Tenant $demoTenant,
        array $invitedEmails,
        ?User $actingUser,
    ): void {
        if (! $template->is_demo_template) {
            throw new \InvalidArgumentException('Source tenant must be a demo template.');
        }

        $template->refresh();
        $demoTenant->refresh();

        if ((int) $demoTenant->demo_template_id !== (int) $template->id) {
            throw new RuntimeException('Demo tenant demo_template_id does not match the template.');
        }

        $srcTid = (int) $template->id;
        $dstTid = (int) $demoTenant->id;

        if (! $template->uuid || ! $demoTenant->uuid) {
            throw new RuntimeException('Template and demo tenant must have uuid for storage isolation.');
        }

        $copyTasks = [];

        DB::transaction(function () use ($srcTid, $dstTid, $template, $demoTenant, $invitedEmails, $actingUser, &$copyTasks) {
            $bucketIdMap = $this->buildStorageBucketIdMap($srcTid, $dstTid);

            $brandMap = $this->cloneBrands($srcTid, $dstTid);
            $categoryMap = $this->cloneCategories($srcTid, $dstTid, $brandMap);
            $fieldMap = $this->cloneMetadataFieldsAndRelated($srcTid, $dstTid, $categoryMap);
            $assetMap = $this->cloneAssetsAndVersions($srcTid, $dstTid, $template, $demoTenant, $bucketIdMap, $brandMap, $categoryMap, $copyTasks);
            $this->cloneAssetSatellites($srcTid, $assetMap, $fieldMap);
            $this->cloneBrandModelGraph($srcTid, $brandMap, $assetMap, $actingUser);
            $collectionMap = $this->cloneCollections($srcTid, $dstTid, $brandMap, $actingUser);
            $this->cloneAssetCollections($collectionMap, $assetMap);
            $this->cloneExecutions($srcTid, $dstTid, $brandMap, $categoryMap, $assetMap);
            $this->cloneTenantModules($srcTid, $dstTid);

            $ownerUserId = $this->provisionDemoUsers($demoTenant, $invitedEmails, $actingUser, $brandMap);
            $this->attachDemoOwnerToCollections($collectionMap, $ownerUserId);
        });

        foreach ($copyTasks as $task) {
            $this->storageCopy->copyAssetVersionPrefix(
                $template,
                $demoTenant,
                $task['source_asset_id'],
                $task['dest_asset_id'],
                $task['version_number'],
            );
        }
    }

    /**
     * @return array<string, string> old bucket uuid -> new bucket uuid
     */
    private function buildStorageBucketIdMap(int $srcTid, int $dstTid): array
    {
        $src = DB::table('storage_buckets')->where('tenant_id', $srcTid)->orderBy('id')->get();
        $dst = DB::table('storage_buckets')->where('tenant_id', $dstTid)->orderBy('id')->get();
        if ($dst->isEmpty()) {
            throw new RuntimeException('Demo tenant has no storage bucket; provision storage before cloning.');
        }

        $map = [];
        foreach ($src as $i => $row) {
            $target = $dst->get($i) ?? $dst->first();
            $map[(string) $row->id] = (string) $target->id;
        }

        return $map;
    }

    /**
     * @return array<int, int> old brand id -> new brand id
     */
    private function cloneBrands(int $srcTid, int $dstTid): array
    {
        $map = [];
        $rows = DB::table('brands')->where('tenant_id', $srcTid)->orderBy('id')->get();
        $cols = array_diff(Schema::getColumnListing('brands'), ['id']);
        foreach ($rows as $row) {
            $data = [];
            foreach ($cols as $col) {
                $data[$col] = $row->{$col} ?? null;
            }
            $data['tenant_id'] = $dstTid;
            $newId = DB::table('brands')->insertGetId($data);
            $map[(int) $row->id] = $newId;
        }

        return $map;
    }

    /**
     * @param  array<int, int>  $brandMap
     * @return array<int, int>
     */
    private function cloneCategories(int $srcTid, int $dstTid, array $brandMap): array
    {
        if (! Schema::hasTable('categories')) {
            return [];
        }

        $map = [];
        $rows = DB::table('categories')->where('tenant_id', $srcTid)->orderBy('id')->get();
        $cols = array_diff(Schema::getColumnListing('categories'), ['id']);
        foreach ($rows as $row) {
            $data = [];
            foreach ($cols as $col) {
                $data[$col] = $row->{$col} ?? null;
            }
            $data['tenant_id'] = $dstTid;
            if (isset($data['brand_id']) && $data['brand_id'] !== null) {
                $data['brand_id'] = $brandMap[(int) $data['brand_id']] ?? null;
            }
            $newId = DB::table('categories')->insertGetId($data);
            $map[(int) $row->id] = $newId;
        }

        return $map;
    }

    /**
     * @param  array<int, int>  $categoryMap
     * @return array<int, int> old metadata_fields.id -> new id
     */
    private function cloneMetadataFieldsAndRelated(int $srcTid, int $dstTid, array $categoryMap): array
    {
        $fieldMap = [];
        if (! Schema::hasTable('metadata_fields')) {
            return $fieldMap;
        }

        $rows = DB::table('metadata_fields')->where('tenant_id', $srcTid)->orderBy('id')->get();
        $cols = array_diff(Schema::getColumnListing('metadata_fields'), ['id']);
        foreach ($rows as $row) {
            $data = [];
            foreach ($cols as $col) {
                $data[$col] = $row->{$col} ?? null;
            }
            $data['tenant_id'] = $dstTid;
            $data['replacement_field_id'] = null;
            $newId = DB::table('metadata_fields')->insertGetId($data);
            $fieldMap[(int) $row->id] = $newId;
        }

        foreach ($rows as $row) {
            $oldRep = $row->replacement_field_id ?? null;
            if ($oldRep === null) {
                continue;
            }
            $newField = $fieldMap[(int) $row->id] ?? null;
            $newRep = $fieldMap[(int) $oldRep] ?? null;
            if ($newField && $newRep) {
                DB::table('metadata_fields')->where('id', $newField)->update(['replacement_field_id' => $newRep]);
            }
        }

        if (Schema::hasTable('metadata_options')) {
            $opts = DB::table('metadata_options')
                ->join('metadata_fields', 'metadata_options.metadata_field_id', '=', 'metadata_fields.id')
                ->where('metadata_fields.tenant_id', $srcTid)
                ->select('metadata_options.*')
                ->get();
            $oCols = array_diff(Schema::getColumnListing('metadata_options'), ['id']);
            foreach ($opts as $o) {
                $data = [];
                foreach ($oCols as $col) {
                    $data[$col] = $o->{$col} ?? null;
                }
                $data['metadata_field_id'] = $fieldMap[(int) $o->metadata_field_id] ?? null;
                if ($data['metadata_field_id']) {
                    DB::table('metadata_options')->insert($data);
                }
            }
        }

        if (Schema::hasTable('metadata_field_visibility')) {
            $vis = DB::table('metadata_field_visibility')
                ->join('metadata_fields', 'metadata_field_visibility.metadata_field_id', '=', 'metadata_fields.id')
                ->where('metadata_fields.tenant_id', $srcTid)
                ->select('metadata_field_visibility.*')
                ->get();
            $vCols = array_diff(Schema::getColumnListing('metadata_field_visibility'), ['id']);
            foreach ($vis as $v) {
                $data = [];
                foreach ($vCols as $col) {
                    $data[$col] = $v->{$col} ?? null;
                }
                $data['metadata_field_id'] = $fieldMap[(int) $v->metadata_field_id] ?? null;
                if ($data['metadata_field_id']) {
                    DB::table('metadata_field_visibility')->insert($data);
                }
            }
        }

        if (Schema::hasTable('metadata_field_permissions')) {
            $perms = DB::table('metadata_field_permissions')
                ->join('metadata_fields', 'metadata_field_permissions.metadata_field_id', '=', 'metadata_fields.id')
                ->where('metadata_fields.tenant_id', $srcTid)
                ->select('metadata_field_permissions.*')
                ->get();
            $pCols = array_diff(Schema::getColumnListing('metadata_field_permissions'), ['id']);
            foreach ($perms as $p) {
                $data = [];
                foreach ($pCols as $col) {
                    $data[$col] = $p->{$col} ?? null;
                }
                $data['metadata_field_id'] = $fieldMap[(int) $p->metadata_field_id] ?? null;
                if ($data['metadata_field_id']) {
                    DB::table('metadata_field_permissions')->insert($data);
                }
            }
        }

        if (Schema::hasTable('metadata_field_category_visibility') && $categoryMap !== []) {
            $mcv = DB::table('metadata_field_category_visibility')
                ->join('metadata_fields', 'metadata_field_category_visibility.metadata_field_id', '=', 'metadata_fields.id')
                ->where('metadata_fields.tenant_id', $srcTid)
                ->select('metadata_field_category_visibility.*')
                ->get();
            $mCols = array_diff(Schema::getColumnListing('metadata_field_category_visibility'), ['id']);
            foreach ($mcv as $m) {
                $data = [];
                foreach ($mCols as $col) {
                    $data[$col] = $m->{$col} ?? null;
                }
                $data['metadata_field_id'] = $fieldMap[(int) $m->metadata_field_id] ?? null;
                $data['category_id'] = $categoryMap[(int) $m->category_id] ?? null;
                if ($data['metadata_field_id'] && $data['category_id']) {
                    DB::table('metadata_field_category_visibility')->insert($data);
                }
            }
        }

        return $fieldMap;
    }

    /**
     * @param  array<int, int>  $brandMap
     * @param  array<string, string>  $bucketIdMap
     * @param  array<int, int>  $categoryMap
     * @param  list<array{source_asset_id: string, dest_asset_id: string, version_number: int}>  $copyTasks
     * @return array<string, string> old asset uuid -> new asset uuid
     */
    private function cloneAssetsAndVersions(
        int $srcTid,
        int $dstTid,
        Tenant $template,
        Tenant $demoTenant,
        array $bucketIdMap,
        array $brandMap,
        array $categoryMap,
        array &$copyTasks,
    ): array {
        $assetMap = [];
        $assets = DB::table('assets')
            ->where('tenant_id', $srcTid)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->get();

        $aCols = array_diff(Schema::getColumnListing('assets'), ['id']);
        foreach ($assets as $a) {
            $newId = (string) Str::uuid();
            $data = [];
            foreach ($aCols as $col) {
                $data[$col] = $a->{$col} ?? null;
            }
            $data['tenant_id'] = $dstTid;
            $data['brand_id'] = isset($data['brand_id']) && $data['brand_id'] !== null
                ? ($brandMap[(int) $data['brand_id']] ?? null)
                : null;
            $data['upload_session_id'] = null;
            $oldBucket = (string) ($a->storage_bucket_id ?? '');
            $data['storage_bucket_id'] = $bucketIdMap[$oldBucket] ?? throw new RuntimeException('Unmapped storage bucket for asset '.$a->id);

            $meta = $this->rewriteMetadataCategories($data['metadata'] ?? null, $categoryMap);
            $data['metadata'] = is_array($meta) ? json_encode($meta) : $meta;

            if (! empty($data['storage_root_path']) && is_string($data['storage_root_path'])) {
                $data['storage_root_path'] = str_replace(
                    'tenants/'.$template->uuid.'/',
                    'tenants/'.$demoTenant->uuid.'/',
                    $data['storage_root_path'],
                );
            }

            DB::table('assets')->insert(array_merge($data, ['id' => $newId]));
            $assetMap[(string) $a->id] = $newId;

            $versions = DB::table('asset_versions')
                ->where('asset_id', $a->id)
                ->whereNull('deleted_at')
                ->orderBy('version_number')
                ->get();

            $vCols = array_diff(Schema::getColumnListing('asset_versions'), ['id']);
            foreach ($versions as $v) {
                $vdata = [];
                foreach ($vCols as $col) {
                    $vdata[$col] = $v->{$col} ?? null;
                }
                $vdata['asset_id'] = $newId;
                $vdata['uploaded_by'] = null;
                $vdata['restored_from_version_id'] = null;

                $oldPath = (string) ($vdata['file_path'] ?? '');
                $vdata['file_path'] = str_replace(
                    'tenants/'.$template->uuid.'/assets/'.$a->id.'/',
                    'tenants/'.$demoTenant->uuid.'/assets/'.$newId.'/',
                    $oldPath,
                );

                $newVid = (string) Str::uuid();
                DB::table('asset_versions')->insert(array_merge($vdata, ['id' => $newVid]));

                $copyTasks[] = [
                    'source_asset_id' => (string) $a->id,
                    'dest_asset_id' => $newId,
                    'version_number' => (int) $v->version_number,
                ];
            }
        }

        return $assetMap;
    }

    /**
     * @param  array<string, string>  $assetMap
     * @param  array<int, int>  $fieldMap
     */
    private function cloneAssetSatellites(int $srcTid, array $assetMap, array $fieldMap): void
    {
        $this->cloneAssetPivotTable('asset_metadata', $srcTid, $assetMap, $fieldMap);
        $this->cloneAssetPivotTable('asset_tags', $srcTid, $assetMap);
        $this->cloneAssetPivotTable('asset_metadata_candidates', $srcTid, $assetMap, $fieldMap);
        $this->cloneAssetPivotTable('asset_tag_candidates', $srcTid, $assetMap);
    }

    /**
     * @param  array<string, string>  $assetMap
     * @param  array<int, int>|null  $fieldMap
     */
    private function cloneAssetPivotTable(string $table, int $srcTid, array $assetMap, ?array $fieldMap = null): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $rows = DB::table($table)
            ->join('assets', $table.'.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $srcTid)
            ->select($table.'.*')
            ->get();

        $cols = array_diff(Schema::getColumnListing($table), ['id']);
        foreach ($rows as $row) {
            $newAsset = $assetMap[(string) $row->asset_id] ?? null;
            if (! $newAsset) {
                continue;
            }
            $data = [];
            foreach ($cols as $col) {
                $data[$col] = $row->{$col} ?? null;
            }
            $data['asset_id'] = $newAsset;
            if ($fieldMap !== null && isset($data['metadata_field_id']) && $data['metadata_field_id'] !== null) {
                $data['metadata_field_id'] = $fieldMap[(int) $data['metadata_field_id']] ?? null;
            }
            DB::table($table)->insert($data);
        }
    }

    /**
     * @param  array<int, int>  $brandMap
     * @param  array<string, string>  $assetMap
     */
    private function cloneBrandModelGraph(int $srcTid, array $brandMap, array $assetMap, ?User $actingUser): void
    {
        if (! Schema::hasTable('brand_models')) {
            return;
        }

        $bmMap = [];
        $models = DB::table('brand_models')
            ->whereIn('brand_id', array_keys($brandMap))
            ->orderBy('id')
            ->get();

        $bmCols = array_diff(Schema::getColumnListing('brand_models'), ['id', 'active_version_id']);
        foreach ($models as $m) {
            $data = [];
            foreach ($bmCols as $col) {
                $data[$col] = $m->{$col} ?? null;
            }
            $data['brand_id'] = $brandMap[(int) $m->brand_id] ?? null;
            if (! $data['brand_id']) {
                continue;
            }
            $newBmId = DB::table('brand_models')->insertGetId($data);
            $bmMap[(int) $m->id] = $newBmId;
        }

        $bmvMap = [];
        if (Schema::hasTable('brand_model_versions') && $bmMap !== []) {
            $versions = DB::table('brand_model_versions')
                ->whereIn('brand_model_id', array_keys($bmMap))
                ->orderBy('id')
                ->get();
            $vCols = array_diff(Schema::getColumnListing('brand_model_versions'), ['id']);
            foreach ($versions as $v) {
                $data = [];
                foreach ($vCols as $col) {
                    $data[$col] = $v->{$col} ?? null;
                }
                $data['brand_model_id'] = $bmMap[(int) $v->brand_model_id] ?? null;
                $data['created_by'] = $actingUser?->id;
                if (! $data['brand_model_id']) {
                    continue;
                }
                $newVId = DB::table('brand_model_versions')->insertGetId($data);
                $bmvMap[(int) $v->id] = $newVId;
            }
        }

        foreach ($models as $m) {
            $oldActive = $m->active_version_id ?? null;
            if ($oldActive === null) {
                continue;
            }
            $newBm = $bmMap[(int) $m->id] ?? null;
            $newActive = $bmvMap[(int) $oldActive] ?? null;
            if ($newBm && $newActive) {
                DB::table('brand_models')->where('id', $newBm)->update(['active_version_id' => $newActive]);
            }
        }

        if (Schema::hasTable('brand_model_version_assets') && $bmvMap !== []) {
            $links = DB::table('brand_model_version_assets')
                ->whereIn('brand_model_version_id', array_keys($bmvMap))
                ->get();
            $lCols = array_diff(Schema::getColumnListing('brand_model_version_assets'), ['id']);
            foreach ($links as $l) {
                $data = [];
                foreach ($lCols as $col) {
                    $data[$col] = $l->{$col} ?? null;
                }
                $data['brand_model_version_id'] = $bmvMap[(int) $l->brand_model_version_id] ?? null;
                $data['asset_id'] = $assetMap[(string) $l->asset_id] ?? null;
                if ($data['brand_model_version_id'] && $data['asset_id']) {
                    DB::table('brand_model_version_assets')->insert($data);
                }
            }
        }
    }

    /**
     * @param  array<int, int>  $brandMap
     * @return array<int, int>
     */
    private function cloneCollections(int $srcTid, int $dstTid, array $brandMap, ?User $actingUser): array
    {
        if (! Schema::hasTable('collections')) {
            return [];
        }

        $map = [];
        $rows = DB::table('collections')->where('tenant_id', $srcTid)->orderBy('id')->get();
        $cols = array_diff(Schema::getColumnListing('collections'), ['id']);
        foreach ($rows as $row) {
            $data = [];
            foreach ($cols as $col) {
                $data[$col] = $row->{$col} ?? null;
            }
            $data['tenant_id'] = $dstTid;
            $data['brand_id'] = $brandMap[(int) $row->brand_id] ?? null;
            $data['created_by'] = $actingUser?->id;
            $data['public_share_token'] = null;
            $data['public_password_hash'] = null;
            $data['public_password_set_at'] = null;
            $newId = DB::table('collections')->insertGetId($data);
            $map[(int) $row->id] = $newId;
        }

        return $map;
    }

    /**
     * @param  array<int, int>  $collectionMap
     * @param  array<string, string>  $assetMap
     */
    private function cloneAssetCollections(array $collectionMap, array $assetMap): void
    {
        if (! Schema::hasTable('asset_collections') || $collectionMap === []) {
            return;
        }

        $rows = DB::table('asset_collections')
            ->whereIn('collection_id', array_keys($collectionMap))
            ->get();
        $cols = array_diff(Schema::getColumnListing('asset_collections'), ['id']);
        foreach ($rows as $row) {
            $newAsset = $assetMap[(string) $row->asset_id] ?? null;
            $newCol = $collectionMap[(int) $row->collection_id] ?? null;
            if (! $newAsset || ! $newCol) {
                continue;
            }
            $data = [];
            foreach ($cols as $col) {
                $data[$col] = $row->{$col} ?? null;
            }
            $data['collection_id'] = $newCol;
            $data['asset_id'] = $newAsset;
            DB::table('asset_collections')->insert($data);
        }
    }

    /**
     * Grant the demo owner access to cloned collections (do not copy template invites / guests).
     *
     * @param  array<int, int>  $collectionMap
     */
    private function attachDemoOwnerToCollections(array $collectionMap, ?int $ownerUserId): void
    {
        if (! Schema::hasTable('collection_user') || $collectionMap === [] || ! $ownerUserId) {
            return;
        }

        foreach (array_values($collectionMap) as $newCollectionId) {
            DB::table('collection_user')->updateOrInsert(
                [
                    'user_id' => $ownerUserId,
                    'collection_id' => $newCollectionId,
                ],
                [
                    'invited_by_user_id' => null,
                    'accepted_at' => Carbon::now(),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ],
            );
        }
    }

    /**
     * @param  array<int, int>  $brandMap
     * @param  array<int, int>  $categoryMap
     * @param  array<string, string>  $assetMap
     */
    private function cloneExecutions(int $srcTid, int $dstTid, array $brandMap, array $categoryMap, array $assetMap): void
    {
        if (! Schema::hasTable('executions')) {
            return;
        }

        $rows = DB::table('executions')->where('tenant_id', $srcTid)->orderBy('id')->get();
        $cols = array_diff(Schema::getColumnListing('executions'), ['id']);
        $exMap = [];
        foreach ($rows as $row) {
            $data = [];
            foreach ($cols as $col) {
                $data[$col] = $row->{$col} ?? null;
            }
            $data['tenant_id'] = $dstTid;
            $data['brand_id'] = $brandMap[(int) $row->brand_id] ?? null;
            $data['category_id'] = isset($row->category_id) && $row->category_id !== null
                ? ($categoryMap[(int) $row->category_id] ?? null)
                : null;
            $data['primary_asset_id'] = isset($row->primary_asset_id) && $row->primary_asset_id
                ? ($assetMap[(string) $row->primary_asset_id] ?? null)
                : null;
            $newId = DB::table('executions')->insertGetId($data);
            $exMap[(int) $row->id] = $newId;
        }

        if (! Schema::hasTable('execution_assets') || $exMap === []) {
            return;
        }

        $links = DB::table('execution_assets')->whereIn('execution_id', array_keys($exMap))->get();
        $lCols = array_diff(Schema::getColumnListing('execution_assets'), ['id']);
        foreach ($links as $l) {
            $data = [];
            foreach ($lCols as $col) {
                $data[$col] = $l->{$col} ?? null;
            }
            $data['execution_id'] = $exMap[(int) $l->execution_id] ?? null;
            $data['asset_id'] = $assetMap[(string) $l->asset_id] ?? null;
            if ($data['execution_id'] && $data['asset_id']) {
                DB::table('execution_assets')->insert($data);
            }
        }
    }

    private function cloneTenantModules(int $srcTid, int $dstTid): void
    {
        if (! Schema::hasTable('tenant_modules')) {
            return;
        }

        $rows = DB::table('tenant_modules')->where('tenant_id', $srcTid)->get();
        $cols = array_diff(Schema::getColumnListing('tenant_modules'), ['id']);
        foreach ($rows as $row) {
            $data = [];
            foreach ($cols as $col) {
                $data[$col] = $row->{$col} ?? null;
            }
            $data['tenant_id'] = $dstTid;
            foreach (['stripe_price_id', 'stripe_subscription_item_id', 'seat_pack_stripe_price_id', 'seat_pack_stripe_subscription_item_id'] as $stripeCol) {
                if (array_key_exists($stripeCol, $data)) {
                    $data[$stripeCol] = null;
                }
            }
            DB::table('tenant_modules')->insert($data);
        }
    }

    /**
     * @param  array<int, int>  $brandMap
     */
    private function provisionDemoUsers(Tenant $demoTenant, array $invitedEmails, ?User $actingUser, array $brandMap): ?int
    {
        $emails = array_values(array_unique(array_filter($invitedEmails, fn ($e) => is_string($e) && $e !== '')));
        if ($emails === [] && $actingUser) {
            $emails = [(string) $actingUser->email];
        }
        if ($emails === []) {
            return null;
        }

        $ownerUserId = null;
        foreach ($emails as $i => $email) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'first_name' => 'Demo',
                    'last_name' => 'User',
                    'password' => bcrypt(Str::random(32)),
                ],
            );

            $role = $i === 0 ? 'owner' : 'member';
            if ($i === 0) {
                $ownerUserId = $user->id;
            }
            if (! $demoTenant->users()->where('users.id', $user->id)->exists()) {
                $demoTenant->users()->attach($user->id, ['role' => $role]);
            } else {
                $user->setRoleForTenant($demoTenant, $role, true);
            }

            $brandRole = $i === 0 ? 'admin' : 'member';
            foreach ($brandMap as $newBrandId) {
                $brand = \App\Models\Brand::query()->find($newBrandId);
                if ($brand && ! $brand->users()->where('users.id', $user->id)->exists()) {
                    $brand->users()->attach($user->id, ['role' => $brandRole]);
                }
            }
        }

        return $ownerUserId;
    }

    private function rewriteMetadataCategories(mixed $metadata, array $categoryMap): mixed
    {
        if (! is_string($metadata) && ! is_array($metadata)) {
            return $metadata;
        }
        $meta = is_array($metadata) ? $metadata : json_decode($metadata, true);
        if (! is_array($meta)) {
            return $metadata;
        }
        if (isset($meta['category_id']) && is_numeric($meta['category_id'])) {
            $old = (int) $meta['category_id'];
            if (isset($categoryMap[$old])) {
                $meta['category_id'] = (string) $categoryMap[$old];
            }
        }

        return $meta;
    }
}
