import { useMemo, useCallback } from 'react'
import { router } from '@inertiajs/react'
import { getContrastTextColor, hexToRgba } from '../../utils/colorUtils'

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

const TYPE_OPTIONS = [
    { id: 'all', label: 'All' },
    { id: 'asset', label: 'Asset' },
    { id: 'deliverable', label: 'Execution' },
]

function normalizeHex(c) {
    if (!c || typeof c !== 'string') return '#6366f1'
    const t = c.trim()
    return t.startsWith('#') ? t : `#${t}`
}

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
    const accent = normalizeHex(primaryColor)
    const onAccent = getContrastTextColor(accent)
    const mutedRing = hexToRgba(accent, 0.35)

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

    const typeActive = (t) => collectionType === t

    return (
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1.5">
            <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Type</span>
            <div
                style={{ ['--cf-accent']: accent }}
                className="inline-flex flex-wrap items-center gap-0.5 rounded-lg border border-gray-200 bg-white p-0.5 shadow-sm"
                role="group"
                aria-label="Collection asset types"
            >
                {TYPE_OPTIONS.map(({ id, label }) => {
                    const active = typeActive(id)
                    const isAll = id === 'all'
                    return (
                        <button
                            key={id}
                            type="button"
                            aria-pressed={active}
                            onClick={() => apply(id, null)}
                            style={
                                active
                                    ? isAll
                                        ? {
                                              backgroundColor: accent,
                                              color: onAccent,
                                              boxShadow: `0 1px 2px ${hexToRgba('#000000', 0.06)}`,
                                          }
                                        : {
                                              backgroundColor: '#fff',
                                              color: accent,
                                              boxShadow: `0 0 0 1px ${mutedRing}`,
                                          }
                                    : undefined
                            }
                            className={`rounded-md px-2.5 py-1 text-xs font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--cf-accent)] focus-visible:ring-offset-2 ${
                                active
                                    ? isAll
                                        ? 'font-semibold'
                                        : 'font-medium'
                                    : 'text-slate-600 hover:bg-gray-50 hover:text-slate-800'
                            }`}
                        >
                            {label}
                        </button>
                    )
                })}
            </div>

            {categories.length > 0 && (
                <>
                    <span
                        className="hidden h-5 w-px shrink-0 bg-slate-200 sm:inline-block"
                        aria-hidden
                    />
                    <label
                        htmlFor={`collection-category-${collectionId}`}
                        className="text-[10px] font-semibold uppercase tracking-wide text-slate-500"
                    >
                        Category
                    </label>
                    <select
                        id={`collection-category-${collectionId}`}
                        value={selectValue}
                        onChange={(e) => {
                            const v = e.target.value
                            apply(collectionType, v === '' ? null : v)
                        }}
                        style={{ accentColor: accent, ['--cf-accent']: accent }}
                        className="max-w-[14rem] min-w-[8.5rem] rounded-md border border-slate-200 bg-white py-1.5 pl-2 pr-8 text-xs font-medium text-slate-800 shadow-sm focus:border-slate-300 focus:outline-none focus:ring-2 focus:ring-[var(--cf-accent)] focus:ring-offset-0 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
                    >
                        <option value="">All categories</option>
                        {categories.map((c) => (
                            <option key={c.id} value={String(c.id)}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                </>
            )}
        </div>
    )
}
