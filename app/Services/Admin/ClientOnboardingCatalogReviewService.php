<?php

namespace App\Services\Admin;

use App\Models\MetadataOption;
use App\Services\SystemCategoryService;
use App\Services\TenantMetadataVisibilityService;
use Illuminate\Support\Facades\DB;

/**
 * Read-only "what new brands get" view: latest {@see \App\Models\SystemCategory} templates
 * and {@see config('metadata_category_defaults')} / {@see TenantMetadataVisibilityService::buildConfigDefaultsMapForSystemTemplate}.
 */
class ClientOnboardingCatalogReviewService
{
    /** Max option rows per field in the options panel (safety for huge vocabularies). */
    public const OPTIONS_CAP = 150;

    public function __construct(
        protected SystemCategoryService $systemCategoryService,
        protected TenantMetadataVisibilityService $tenantMetadataVisibility
    ) {}

    /**
     * @return array{groups: list<array{key: string, label: string, categories: list<array<string, mixed>>}>, options_by_field_id: array<int, list<array{value: string, label: string}>>, meta: array<string, string>}
     */
    public function buildPayload(): array
    {
        $templates = $this->systemCategoryService->getAllTemplates();
        /** @var array<string, list<array<string, mixed>>> $byAssetType */
        $byAssetType = [];
        $fieldIdsForOptions = [];

        foreach ($templates as $template) {
            $at = $template->asset_type->value;
            if (! isset($byAssetType[$at])) {
                $byAssetType[$at] = [];
            }

            $map = $this->tenantMetadataVisibility->buildConfigDefaultsMapForSystemTemplate(
                $template->slug,
                $at
            );
            $fields = [];
            if ($map !== []) {
                $ids = array_keys($map);
                $rows = DB::table('metadata_fields')
                    ->whereIn('id', $ids)
                    ->get()
                    ->keyBy('id');

                foreach ($map as $fieldId => $vis) {
                    $row = $rows->get($fieldId);
                    if (! $row) {
                        continue;
                    }
                    $fid = (int) $fieldId;
                    $isHidden = (bool) ($vis['is_hidden'] ?? true);
                    $fields[] = [
                        'id' => $fid,
                        'key' => (string) $row->key,
                        'label' => (string) ($row->system_label !== '' && $row->system_label !== null ? $row->system_label : $row->key),
                        'type' => (string) $row->type,
                        'is_hidden' => $isHidden,
                        'is_active_in_default' => ! $isHidden,
                        'is_primary' => $vis['is_primary'] ?? null,
                        'is_upload_hidden' => (bool) ($vis['is_upload_hidden'] ?? false),
                        'is_filter_hidden' => (bool) ($vis['is_filter_hidden'] ?? false),
                        'is_edit_hidden' => (bool) ($vis['is_edit_hidden'] ?? false),
                    ];
                    if (in_array((string) $row->type, ['select', 'multiselect'], true)) {
                        $fieldIdsForOptions[$fid] = true;
                    }
                }
                usort($fields, fn (array $a, array $b): int => $this->compareFields($a, $b));
            }

            $byAssetType[$at][] = [
                'id' => (int) $template->id,
                'name' => $template->name,
                'slug' => $template->slug,
                'is_hidden' => (bool) $template->is_hidden,
                'auto_provision' => (bool) $template->auto_provision,
                'sort_order' => (int) $template->sort_order,
                'fields' => $fields,
            ];
        }

        foreach ($byAssetType as $at => $list) {
            usort(
                $byAssetType[$at],
                fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order'] ?: strcasecmp($a['name'], $b['name'])
            );
        }

        $optionsByFieldId = $this->loadOptions(array_map('intval', array_keys($fieldIdsForOptions)));

        $groups = [];
        $order = ['asset', 'deliverable', 'reference', 'ai_generated'];
        foreach ($order as $key) {
            if (! empty($byAssetType[$key])) {
                $groups[] = [
                    'key' => $key,
                    'label' => $this->groupLabel($key),
                    'categories' => $byAssetType[$key],
                ];
            }
        }
        foreach ($byAssetType as $k => $cats) {
            if (in_array($k, $order, true) || $cats === []) {
                continue;
            }
            $groups[] = [
                'key' => $k,
                'label' => $this->groupLabel($k),
                'categories' => $cats,
            ];
        }

        return [
            'groups' => $groups,
            'options_by_field_id' => $optionsByFieldId,
            'meta' => [
                'source' => 'Latest `system_categories` template rows plus `config/metadata_category_defaults.php` (same source as new brand / “Reset to default” visibility).',
                'editing' => 'Read-only review (v1). In-page drag-and-drop or toggles to define future tenant seeding is not wired yet; change templates/config and deploy, or use System Categories / Metadata Registry.',
            ],
        ];
    }

    /**
     * @param  array{is_primary?: mixed, is_active_in_default: bool, label: string}  $a
     * @param  array{is_primary?: mixed, is_active_in_default: bool, label: string}  $b
     */
    protected function compareFields(array $a, array $b): int
    {
        $ap = ($a['is_primary'] === true) ? 1 : 0;
        $bp = ($b['is_primary'] === true) ? 1 : 0;
        if ($ap !== $bp) {
            return $bp <=> $ap;
        }
        $av = ! empty($a['is_active_in_default']) ? 1 : 0;
        $bv = ! empty($b['is_active_in_default']) ? 1 : 0;
        if ($av !== $bv) {
            return $bv <=> $av;
        }

        return strcasecmp($a['label'], $b['label']);
    }

    protected function groupLabel(string $assetTypeValue): string
    {
        return match ($assetTypeValue) {
            'asset' => 'Assets',
            'deliverable' => 'Executions (deliverables)',
            'reference' => 'Reference materials',
            'ai_generated' => 'AI generated',
            default => ucfirst(str_replace('_', ' ', $assetTypeValue)),
        };
    }

    /**
     * @param  list<int>  $fieldIds
     * @return array<int, list<array{value: string, label: string}>>
     */
    protected function loadOptions(array $fieldIds): array
    {
        if ($fieldIds === []) {
            return [];
        }
        $out = [];
        $totals = [];
        $rows = MetadataOption::query()
            ->whereIn('metadata_field_id', $fieldIds)
            ->orderBy('metadata_field_id')
            ->orderBy('system_label')
            ->get(['metadata_field_id', 'value', 'system_label']);
        foreach ($rows as $r) {
            $fid = (int) $r->metadata_field_id;
            if (! isset($out[$fid])) {
                $out[$fid] = [];
                $totals[$fid] = 0;
            }
            if ($totals[$fid] >= self::OPTIONS_CAP) {
                continue;
            }
            $out[$fid][] = [
                'value' => (string) $r->value,
                'label' => (string) ($r->system_label !== '' && $r->system_label !== null ? $r->system_label : $r->value),
            ];
            $totals[$fid]++;
        }

        return $out;
    }
}
