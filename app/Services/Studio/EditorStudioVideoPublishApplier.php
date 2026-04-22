<?php

namespace App\Services\Studio;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssetEligibilityService;
use App\Services\CollectionAssetService;
use App\Services\MetadataPersistenceService;
use App\Services\UploadMetadataSchemaResolver;
use App\Support\GenerativeAiProvenance;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * After a studio composition MP4 is created, apply the same editor "publish" metadata
 * (title, category, fields, collections, provenance) as a JPEG upload through the asset bridge.
 */
final class EditorStudioVideoPublishApplier
{
    public function __construct(
        protected UploadMetadataSchemaResolver $uploadMetadataSchemaResolver,
        protected MetadataPersistenceService $metadataPersistenceService,
        protected CollectionAssetService $collectionAssetService,
        protected AssetEligibilityService $assetEligibilityService,
    ) {}

    /**
     * @param  array<string, mixed>  $editorPublish
     *  Keys: name, description, category_id, field_metadata, collection_ids, editor_provenance
     */
    /**
     * When no editor publish payload assigned a shelf category, pick the brand's default library category
     * so the asset can appear in the category-filtered grid after pipeline + publish.
     */
    public function ensureShelfCategoryWhenMissing(Asset $asset, Tenant $tenant, Brand $brand): void
    {
        $meta = is_array($asset->metadata) ? $asset->metadata : [];
        $existing = $meta['category_id'] ?? null;
        if ($existing !== null && $existing !== '' && ! (is_string($existing) && strtolower(trim($existing)) === 'null')) {
            return;
        }
        $category = $this->resolveCategory($tenant, $brand, null);
        if (! $category) {
            Log::warning('[EditorStudioVideoPublishApplier] ensureShelfCategoryWhenMissing: no default category', [
                'asset_id' => $asset->id,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
            ]);

            return;
        }
        $meta['category_id'] = $category->id;
        $asset->metadata = $meta;
        $asset->save();
    }

    public function apply(
        Asset $asset,
        User $user,
        Tenant $tenant,
        Brand $brand,
        array $editorPublish
    ): void {
        if ($editorPublish === []) {
            return;
        }

        $title = trim((string) ($editorPublish['name'] ?? ''));
        $description = trim((string) ($editorPublish['description'] ?? ''));
        $fieldMetadata = is_array($editorPublish['field_metadata'] ?? null)
            ? $editorPublish['field_metadata']
            : [];
        $collectionIds = [];
        if (isset($editorPublish['collection_ids']) && is_array($editorPublish['collection_ids'])) {
            foreach ($editorPublish['collection_ids'] as $id) {
                if (is_numeric($id) && (int) $id > 0) {
                    $collectionIds[] = (int) $id;
                }
            }
            $collectionIds = array_values(array_unique($collectionIds));
        }
        $provenanceHints = is_array($editorPublish['editor_provenance'] ?? null)
            ? $editorPublish['editor_provenance']
            : [];

        $category = $this->resolveCategory(
            $tenant,
            $brand,
            $editorPublish['category_id'] ?? null
        );
        if (! $category) {
            Log::warning('[EditorStudioVideoPublishApplier] No category for publish', [
                'asset_id' => $asset->id,
            ]);

            return;
        }

        if ($title !== '') {
            $asset->title = $title;
        }

        $jackpot = GenerativeAiProvenance::forPublishedComposition(
            $user,
            $brand,
            $tenant,
            $provenanceHints
        );

        $asset->refresh();
        $currentMetadata = is_array($asset->metadata) ? $asset->metadata : [];
        $currentMetadata['category_id'] = $category->id;
        if ($description !== '') {
            $currentMetadata['editor_publish_description'] = $description;
        }
        $currentMetadata['jackpot_ai_provenance'] = $jackpot;
        $asset->metadata = $currentMetadata;
        $asset->save();

        $metadataFields = $this->filterToUploadSchema(
            $tenant,
            $brand,
            $category,
            'video',
            $fieldMetadata
        );

        if ($metadataFields !== []) {
            try {
                $this->metadataPersistenceService->persistMetadata(
                    $asset,
                    $category,
                    $metadataFields,
                    (int) $user->id,
                    'video',
                    false
                );
            } catch (\Throwable $e) {
                Log::warning('[EditorStudioVideoPublishApplier] persistMetadata failed', [
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($collectionIds === []) {
            return;
        }

        if (! $this->assetEligibilityService->isEligibleForCollections($asset)) {
            Log::info('[EditorStudioVideoPublishApplier] Skipping collections — asset not eligible', [
                'asset_id' => $asset->id,
            ]);

            return;
        }

        foreach ($collectionIds as $cid) {
            $target = Collection::query()
                ->where('id', $cid)
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->first();
            if (! $target) {
                continue;
            }
            if (! Gate::forUser($user)->allows('addAsset', $target)) {
                continue;
            }
            try {
                $this->collectionAssetService->attach($target, $asset);
            } catch (\Throwable $e) {
                Log::warning('[EditorStudioVideoPublishApplier] collection attach failed', [
                    'asset_id' => $asset->id,
                    'collection_id' => $cid,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function resolveCategory(Tenant $tenant, Brand $brand, mixed $requestCategoryId): ?Category
    {
        if (! empty($requestCategoryId) && is_numeric($requestCategoryId)) {
            $c = Category::query()
                ->where('id', (int) $requestCategoryId)
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->whereIn('asset_type', [AssetType::ASSET, AssetType::DELIVERABLE])
                ->active()
                ->visible()
                ->first();
            if ($c) {
                return $c;
            }
        }

        $c = Category::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', AssetType::ASSET)
            ->active()
            ->visible()
            ->ordered()
            ->first();
        if ($c) {
            return $c;
        }

        return Category::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', AssetType::DELIVERABLE)
            ->active()
            ->visible()
            ->ordered()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $fieldMetadata
     * @return array<string, mixed>
     */
    private function filterToUploadSchema(
        Tenant $tenant,
        Brand $brand,
        Category $category,
        string $fileAssetType,
        array $fieldMetadata
    ): array {
        if ($fieldMetadata === []) {
            return [];
        }
        try {
            $schema = $this->uploadMetadataSchemaResolver->resolve(
                $tenant->id,
                $brand->id,
                $category->id,
                $fileAssetType
            );
        } catch (\Throwable) {
            return [];
        }
        $allowed = [];
        foreach ($schema['groups'] ?? [] as $group) {
            foreach ($group['fields'] ?? [] as $field) {
                if (isset($field['key'])) {
                    $allowed[] = (string) $field['key'];
                }
            }
        }
        if ($allowed === []) {
            return [];
        }
        $allow = array_flip($allowed);

        return array_intersect_key($fieldMetadata, $allow);
    }
}
