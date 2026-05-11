<?php

namespace App\Services\Metadata;

use App\Models\Category;
use App\Models\Tenant;
use App\Services\MetadataPermissionResolver;
use App\Services\MetadataSchemaResolver;
use App\Services\MetadataVisibilityResolver;
use App\Services\TenantMetadataVisibilityService;
use App\Support\Metadata\CategoryTypeResolver;
use Illuminate\Support\Facades\DB;

/**
 * Field IDs that appear on the asset metadata Fields tab (drawer), aligned with
 * {@see \App\Http\Controllers\AssetMetadataController::getEditableMetadata} structural rules
 * (no asset values / pending / system append-only block).
 *
 * Used by folder-schema helper so "enabled" lists match the editor, not only the Manage folder toggle.
 */
class AssetMetadataDrawerFieldIdsResolver
{
    public function __construct(
        protected MetadataSchemaResolver $metadataSchemaResolver,
        protected MetadataVisibilityResolver $visibilityResolver,
        protected TenantMetadataVisibilityService $restrictVisibility,
        protected MetadataPermissionResolver $permissionResolver,
    ) {
    }

    /**
     * @return array<int, true> metadata_field_id => true
     */
    public function fieldIdsForCategory(
        Tenant $tenant,
        ?int $brandId,
        Category $category,
        string $userRole,
    ): array {
        $assetType = CategoryTypeResolver::metadataSchemaAssetTypeForSlug((string) ($category->slug ?? ''));

        $schema = $this->metadataSchemaResolver->resolve(
            (int) $tenant->id,
            $brandId,
            (int) $category->id,
            $assetType
        );

        $candidateFields = [];
        foreach ($schema['fields'] ?? [] as $field) {
            $fieldKey = $field['key'] ?? null;

            if (($field['is_internal_only'] ?? false) && $fieldKey !== 'quality_rating') {
                continue;
            }

            if ($fieldKey === 'dimensions') {
                continue;
            }

            if ($fieldKey !== null && $fieldKey !== ''
                && ! $this->restrictVisibility->isRestrictFieldEnabledForCategorySlug((string) $fieldKey, (string) ($category->slug ?? ''))) {
                continue;
            }

            if ($fieldKey !== null && $fieldKey !== ''
                && $this->restrictVisibility->isSystemFieldHiddenForCategorySlug((string) $fieldKey, (string) ($category->slug ?? ''))) {
                continue;
            }

            $candidateFields[] = $field;
        }

        $visibleFields = $this->visibilityResolver->filterVisibleFields($candidateFields, $category, $tenant);

        $visibleFieldIds = collect($visibleFields)->pluck('field_id')->filter()->unique()->values()->all();

        $metadataFieldsById = collect();
        if ($visibleFieldIds !== []) {
            $metadataFieldsById = DB::table('metadata_fields')
                ->whereIn('id', $visibleFieldIds)
                ->get()
                ->keyBy('id');
        }

        $canEditByFieldId = $this->permissionResolver->canEditMultiple(
            $visibleFieldIds,
            $userRole,
            (int) $tenant->id,
            $brandId,
            (int) $category->id,
            $metadataFieldsById
        );

        $out = [];

        foreach ($visibleFields as $field) {
            $fieldDef = $metadataFieldsById[$field['field_id']] ?? null;
            if (! $fieldDef) {
                continue;
            }

            $showOnEdit = $field['show_on_edit'] ?? true;
            if (! $showOnEdit) {
                continue;
            }

            $populationMode = $field['population_mode'] ?? 'manual';
            $isReadonly = ($field['readonly'] ?? false) || ($populationMode === 'automatic');
            $isUserEditable = $fieldDef->is_user_editable ?? true;

            if (! $isReadonly && ! $isUserEditable) {
                continue;
            }

            $canEdit = true;
            if (! $isReadonly) {
                $canEdit = $canEditByFieldId[$field['field_id']] ?? false;
            }

            $isRating = ($field['type'] ?? 'text') === 'rating' || ($field['key'] ?? null) === 'quality_rating';

            if ($isReadonly || $isRating || $canEdit) {
                $out[(int) $field['field_id']] = true;
            }
        }

        return $out;
    }
}
