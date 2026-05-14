<?php

namespace App\Services\Filters\Facet;

use App\Models\Category;
use App\Models\MetadataField;
use App\Services\Filters\FolderQuickFilterAssignmentService;

/**
 * Phase 5 seam.
 *
 * Caller-facing wrapper that composes {@see FolderQuickFilterAssignmentService}
 * (the source of truth for "which filters are quick filters in which folder")
 * with {@see AssetFacetCountService} (the source of truth for "how many of
 * each value exist"). Phase 5's sidebar render path will go through this
 * service so we have one cohesive seam to:
 *   - apply the cardinality cap (max_distinct_values_for_quick_filter)
 *   - apply efficiency / quality guards
 *   - coalesce N option-count queries into one
 *   - hydrate per-(folder, filter) values for the value flyout
 *
 * Today it exposes the bare minimum so call sites that want to start wiring
 * against it can compile against a stable surface.
 */
class FolderQuickFilterFacetService
{
    public function __construct(
        protected FolderQuickFilterAssignmentService $assignment,
        protected AssetFacetCountService $counts,
    ) {}

    /**
     * Phase 5 will return a list of "decorated" quick filters: assignment row
     * + estimated distinct count + per-option counts (where applicable). For
     * Phase 2 we expose the assignment list with null counts so frontends and
     * backends can wire against the eventual shape.
     *
     * @return list<array{
     *     metadata_field_id: int,
     *     order: int|null,
     *     weight: int|null,
     *     source: string|null,
     *     estimated_distinct_count: int|null,
     *     is_facet_efficient: bool,
     * }>
     */
    public function listForFolder(Category $folder): array
    {
        $rows = $this->assignment->getQuickFiltersForFolder($folder);

        $out = [];
        foreach ($rows as $row) {
            $field = $row->metadataField;
            if (! $field instanceof MetadataField) {
                continue;
            }

            $out[] = [
                'metadata_field_id' => (int) $row->metadata_field_id,
                'order' => $row->folder_quick_filter_order,
                'weight' => $row->folder_quick_filter_weight,
                'source' => $row->folder_quick_filter_source,
                'estimated_distinct_count' => $this->counts->estimateDistinctValueCount(
                    $field,
                    $folder->tenant,
                    $folder->brand,
                    $folder
                ),
                'is_facet_efficient' => $this->assignment->isFacetEfficient($field),
            ];
        }

        return $out;
    }
}
