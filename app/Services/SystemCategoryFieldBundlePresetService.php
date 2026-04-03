<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Models\SystemCategory;
use App\Models\SystemCategoryFieldDefault;
use App\Support\MetadataCache;
use Illuminate\Support\Facades\DB;

/**
 * Seeds {@see SystemCategoryFieldDefault} rows from presets (latest template only).
 */
class SystemCategoryFieldBundlePresetService
{
    public function __construct(
        protected SystemMetadataVisibilityService $systemVisibility,
        protected TenantMetadataVisibilityService $tenantVisibility
    ) {}

    /**
     * @param  list<string>|null  $fieldTypes  Required when preset is by_field_types
     * @return int Rows upserted
     */
    public function seed(SystemCategory $template, string $preset, ?array $fieldTypes = null): int
    {
        if (! $template->isLatestVersion()) {
            throw new \InvalidArgumentException('Only the latest template version can be seeded.');
        }

        if (! in_array($preset, ['minimal', 'by_field_types', 'photography_like'], true)) {
            throw new \InvalidArgumentException('Invalid preset.');
        }

        if ($preset === 'by_field_types' && (empty($fieldTypes))) {
            return 0;
        }

        $assetTypeVal = $template->asset_type instanceof \BackedEnum
            ? $template->asset_type->value
            : (string) $template->asset_type;

        $configMap = $this->tenantVisibility->buildConfigDefaultsMapForSystemTemplate(
            $template->slug,
            $assetTypeVal
        );

        $q = DB::table('metadata_fields')
            ->where('scope', 'system')
            ->where('is_active', true)
            ->whereNull('deprecated_at')
            ->whereNull('archived_at');

        if ($preset === 'minimal') {
            $q->whereIn('key', ['tags', 'collection', 'starred']);
        } elseif ($preset === 'by_field_types') {
            $q->whereIn('type', array_values(array_unique($fieldTypes)));
        } else {
            $q->whereIn('type', ['boolean', 'select', 'multiselect', 'text', 'textarea', 'number', 'date']);
        }

        $fields = $q->orderBy('key')->get();
        $count = 0;
        $templateId = (int) $template->id;

        foreach ($fields as $f) {
            if (! $this->appliesToMatches($template->asset_type, (string) $f->applies_to)) {
                continue;
            }
            $fid = (int) $f->id;
            if ($this->systemVisibility->getSuppressedFieldIdsForSystemCategoryFamily($templateId, [$fid]) !== []) {
                continue;
            }
            $cfg = $configMap[$fid] ?? [
                'is_hidden' => false,
                'is_upload_hidden' => false,
                'is_filter_hidden' => false,
                'is_edit_hidden' => false,
                'is_primary' => null,
            ];

            SystemCategoryFieldDefault::query()->updateOrCreate(
                [
                    'system_category_id' => $templateId,
                    'metadata_field_id' => $fid,
                ],
                [
                    'is_hidden' => (bool) ($cfg['is_hidden'] ?? false),
                    'is_upload_hidden' => (bool) ($cfg['is_upload_hidden'] ?? false),
                    'is_filter_hidden' => (bool) ($cfg['is_filter_hidden'] ?? false),
                    'is_edit_hidden' => (bool) ($cfg['is_edit_hidden'] ?? false),
                    'is_primary' => array_key_exists('is_primary', $cfg) ? $cfg['is_primary'] : null,
                ]
            );
            $count++;
        }

        MetadataCache::flushGlobal();

        return $count;
    }

    protected function appliesToMatches(AssetType $templateAssetType, string $appliesTo): bool
    {
        if ($appliesTo === 'all') {
            return true;
        }

        if ($templateAssetType === AssetType::DELIVERABLE) {
            return in_array($appliesTo, ['document', 'video'], true);
        }

        return in_array($appliesTo, ['image', 'video', 'document'], true);
    }
}
