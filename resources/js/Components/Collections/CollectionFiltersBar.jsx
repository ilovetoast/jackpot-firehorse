import { useMemo, useCallback } from 'react'
import { router } from '@inertiajs/react'
import { SegmentedPrimaryFilter, CollectionStyleSelect } from '../PrimaryFilterToolbarControls'

const COLLECTION_INERTIA_ONLY = [
    'assets',
    'next_page_url',
    'q',
    'sort',
    'sort_direction',
    'group_by_category',
    'collection_type',
    'category_id',
    'filter_categories',
    'filterable_schema',
    'available_values',
    'filters',
    'filtered_grid_total',
    'grid_folder_total',
    'selected_collection',
]

/** Same segmented pattern as Assets inline metadata filters ({@link SegmentedPrimaryFilter}). */
const TYPE_SEGMENT_OPTIONS = [
    { value: 'asset', label: 'Asset' },
    { value: 'deliverable', label: 'Execution' },
]

/**
 * Collections-only filters: type toggles + category dropdown. Inline with search via AssetGridToolbar `beforeSearchSlot`.
 */
export default function CollectionFiltersBar({
    collectionId,
    collectionType = 'all',
    categoryId = null,
    filterCategories = [],
    primaryColor = '#6366f1',
}) {
    const categories = useMemo(
        () => (Array.isArray(filterCategories) ? filterCategories : []),
        [filterCategories]
    )

    const apply = useCallback(
        (nextType, nextCategoryId) => {
            if (collectionId == null) return
            const urlParams = new URLSearchParams(window.location.search)
            urlParams.set('collection', String(collectionId))
            urlParams.set('collection_type', nextType === 'all' ? 'all' : nextType)
            if (nextCategoryId == null || nextCategoryId === '' || nextCategoryId === 'all') {
                urlParams.delete('category_id')
            } else {
                urlParams.set('category_id', String(nextCategoryId))
            }
            urlParams.set('page', '1')
            router.get(window.location.pathname, Object.fromEntries(urlParams), {
                preserveState: true,
                preserveScroll: false,
                only: COLLECTION_INERTIA_ONLY,
            })
        },
        [collectionId]
    )

    const idSet = useMemo(() => new Set(categories.map((c) => String(c.id))), [categories])
    const rawCat =
        categoryId != null && categoryId !== '' && String(categoryId) !== 'all'
            ? String(categoryId)
            : ''
    const selectValue = rawCat && idSet.has(rawCat) ? rawCat : ''

    if (collectionId == null) return null

    const segmentedValue =
        collectionType === 'all' ? null : collectionType === 'deliverable' ? 'deliverable' : 'asset'

    return (
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1.5">
            <SegmentedPrimaryFilter
                label="Type"
                accentColor={primaryColor}
                options={TYPE_SEGMENT_OPTIONS}
                value={segmentedValue}
                anyLabel="All"
                onChange={(v) => {
                    const nextType = v == null ? 'all' : v
                    apply(nextType, null)
                }}
            />

            {categories.length > 0 && (
                <>
                    <span
                        className="hidden h-5 w-px shrink-0 bg-gray-200 sm:inline-block"
                        aria-hidden
                    />
                    <CollectionStyleSelect
                        label="Category"
                        accentColor={primaryColor}
                        value={selectValue}
                        onChange={(e) => {
                            const v = e.target.value
                            apply(collectionType, v === '' ? null : v)
                        }}
                    >
                        <option value="">All categories</option>
                        {categories.map((c) => (
                            <option key={c.id} value={String(c.id)}>
                                {c.name}
                            </option>
                        ))}
                    </CollectionStyleSelect>
                </>
            )}
        </div>
    )
}
