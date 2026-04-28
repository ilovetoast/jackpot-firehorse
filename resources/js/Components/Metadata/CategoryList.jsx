import { useState, useCallback, useEffect, useMemo } from 'react'
import {
    DndContext,
    closestCenter,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core'
import {
    arrayMove,
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import {
    ArrowPathIcon,
    Bars3Icon,
    ChevronDownIcon,
    ChevronRightIcon,
    EyeIcon,
    EyeSlashIcon,
    InformationCircleIcon,
    PencilIcon,
    PlusIcon,
    TrashIcon,
} from '@heroicons/react/24/outline'
import { CategoryIcon } from '../../Helpers/categoryIcons'

function sortCategoriesStable(list) {
    return [...list].sort((a, b) => {
        const ao = a.sort_order ?? 9999
        const bo = b.sort_order ?? 9999
        if (ao !== bo) return ao - bo
        return String(a.name || '').localeCompare(String(b.name || ''))
    })
}

function partitionVisibleHidden(list) {
    const sorted = sortCategoriesStable(list)
    return {
        visible: sorted.filter((c) => !c.is_hidden),
        hidden: sorted.filter((c) => c.is_hidden),
    }
}

function mergeWithRenumberedSortOrder(visible, hidden) {
    return [...visible, ...hidden].map((c, i) => ({ ...c, sort_order: i + 1 }))
}

/** When the catalog has this many or more rows, use filter + select instead of a long list. */
const CATALOG_COMPACT_THRESHOLD = 5

const CATALOG_HELP =
    'Add a platform folder to this brand. New ones may start hidden until you show them in the library.'
const CATALOG_HELP_VIEW_ONLY = ' View only — adding folders needs the category management permission.'
const HIDDEN_SECTION_HELP =
    'Not shown in the library sidebar, uploader, or default grid. Unhide with the eye icon on a visible folder, or show from here.'

async function persistCategoryReorder(brandId, assetType, mergedList, getCsrfToken) {
    const payload = {
        asset_type: assetType,
        categories: mergedList.map((c, i) => ({ id: c.id, sort_order: i + 1 })),
    }
    const res = await fetch(`/app/api/brands/${brandId}/categories/reorder`, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': getCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    })
    if (!res.ok) {
        const err = await res.json().catch(() => ({}))
        throw new Error(err.message || 'Failed to reorder')
    }
}

/**
 * Sortable category row for Metadata page (visible folders — drag to reorder).
 */
function SortableCategoryRow({
    category,
    isSelected,
    onSelect,
    onToggleHidden,
    onRename,
    onCategorySettingsClick,
    onDelete,
    onRevert,
    canManageVisibility,
    isEditing,
    editName,
    onEditNameChange,
    onSaveRename,
    onCancelRename,
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: category.id })

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
    }

    return (
        <div
            ref={setNodeRef}
            style={style}
            className={`group flex items-center justify-between gap-1.5 py-1.5 px-1.5 rounded-md text-sm transition-colors cursor-default ${
                isSelected
                    ? 'bg-violet-50/95 font-medium text-violet-900 ring-1 ring-inset ring-violet-200/80'
                    : 'text-slate-700 hover:bg-slate-50/90'
            } ${isDragging ? 'z-10 rounded-md bg-white opacity-90 shadow-md' : ''}`}
        >
            {canManageVisibility && (
                <button
                    type="button"
                    className="p-0.5 -m-0.5 rounded cursor-grab active:cursor-grabbing text-gray-400 opacity-60 hover:opacity-100 hover:text-gray-600 touch-none flex-shrink-0 transition-opacity"
                    {...attributes}
                    {...listeners}
                    onClick={(e) => e.stopPropagation()}
                    aria-label="Drag to reorder folders in sidebar"
                >
                    <Bars3Icon className="h-4 w-4" />
                </button>
            )}
            <button
                type="button"
                className="flex-1 text-left min-w-0 flex items-center gap-2"
                onClick={() => !isEditing && onSelect(category.id)}
            >
                {isEditing ? (
                    <input
                        type="text"
                        value={editName}
                        onChange={(e) => onEditNameChange(e.target.value)}
                        onBlur={() => onSaveRename(category, editName)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') onSaveRename(category, editName)
                            if (e.key === 'Escape') onCancelRename()
                        }}
                        className="w-full rounded border border-slate-200 px-2 py-0.5 text-sm focus:border-violet-500 focus:ring-1 focus:ring-violet-500"
                        autoFocus
                        onClick={(e) => e.stopPropagation()}
                    />
                ) : (
                    <span className="truncate">{category.name}</span>
                )}
            </button>
            {canManageVisibility && (
                <div className="flex items-center gap-0.5 flex-shrink-0 text-gray-400">
                    <button
                        type="button"
                        className="p-1 -m-1 rounded hover:text-gray-700 hover:bg-gray-100 flex-shrink-0"
                        onClick={(e) => {
                            e.stopPropagation()
                            onToggleHidden(category)
                        }}
                        title="Hide from library sidebars, uploader, and default grid"
                        aria-label="Hide category from library"
                    >
                        <EyeIcon className="h-4 w-4" />
                    </button>
                    {!category.is_system && (onCategorySettingsClick || onRename) && (
                        <button
                            type="button"
                            className="p-1 -m-1 rounded hover:text-gray-700 hover:bg-gray-100 flex-shrink-0"
                            onClick={(e) => {
                                e.stopPropagation()
                                if (onCategorySettingsClick) {
                                    onCategorySettingsClick(category)
                                } else {
                                    onRename(category)
                                }
                            }}
                            title={onCategorySettingsClick ? 'Category settings' : 'Rename category'}
                            aria-label={onCategorySettingsClick ? 'Category settings' : 'Rename category'}
                        >
                            <PencilIcon className="h-4 w-4" />
                        </button>
                    )}
                    {category.is_system && onRevert && (
                        <button
                            type="button"
                            className="p-1 -m-1 flex-shrink-0 rounded hover:bg-violet-50 hover:text-violet-700"
                            onClick={(e) => {
                                e.stopPropagation()
                                onRevert(category)
                            }}
                            title="Revert to system default"
                            aria-label="Revert to system default"
                        >
                            <ArrowPathIcon className="h-4 w-4" />
                        </button>
                    )}
                    {onDelete && (
                        category.is_system && !category.deletion_available ? (
                            <span
                                className="inline-flex p-1 -m-1 rounded flex-shrink-0 cursor-not-allowed"
                                title="Cannot delete: this system category is in use by brands"
                            >
                                <TrashIcon className="h-4 w-4 text-gray-300" aria-hidden />
                            </span>
                        ) : (
                            <button
                                type="button"
                                className="p-1 -m-1 rounded hover:text-red-600 hover:bg-red-50 flex-shrink-0"
                                onClick={(e) => {
                                    e.stopPropagation()
                                    onDelete(category)
                                }}
                                title="Delete category"
                                aria-label="Delete category"
                            >
                                <TrashIcon className="h-4 w-4" />
                            </button>
                        )
                    )}
                </div>
            )}
        </div>
    )
}

/**
 * Hidden folder row — same actions as visible, no drag (order is secondary; renumbered after show/hide).
 */
function HiddenCategoryRow({
    category,
    isSelected,
    onSelect,
    onToggleHidden,
    onRename,
    onCategorySettingsClick,
    onDelete,
    onRevert,
    canManageVisibility,
    isEditing,
    editName,
    onEditNameChange,
    onSaveRename,
    onCancelRename,
}) {
    return (
        <div
            className={`group flex items-center justify-between gap-1.5 py-1.5 px-1.5 rounded-md text-sm transition-colors cursor-default text-slate-500 ${
                isSelected
                    ? 'bg-violet-50/95 font-medium text-violet-900 ring-1 ring-inset ring-violet-200/80'
                    : 'hover:bg-slate-100/80'
            }`}
        >
            <span className="w-5 flex-shrink-0" aria-hidden />
            <button
                type="button"
                className="flex-1 text-left min-w-0 flex items-center gap-2"
                onClick={() => !isEditing && onSelect(category.id)}
            >
                {isEditing ? (
                    <input
                        type="text"
                        value={editName}
                        onChange={(e) => onEditNameChange(e.target.value)}
                        onBlur={() => onSaveRename(category, editName)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') onSaveRename(category, editName)
                            if (e.key === 'Escape') onCancelRename()
                        }}
                        className="w-full rounded border border-slate-200 px-2 py-0.5 text-sm focus:border-violet-500 focus:ring-1 focus:ring-violet-500"
                        autoFocus
                        onClick={(e) => e.stopPropagation()}
                    />
                ) : (
                    <span className="truncate">{category.name}</span>
                )}
            </button>
            {canManageVisibility && (
                <div className="flex items-center gap-0.5 flex-shrink-0 text-gray-400">
                    <button
                        type="button"
                        className="p-1 -m-1 rounded hover:text-gray-700 hover:bg-gray-100 flex-shrink-0"
                        onClick={(e) => {
                            e.stopPropagation()
                            onToggleHidden(category)
                        }}
                        title="Show again in library sidebars and grid"
                        aria-label="Show category in library"
                    >
                        <EyeSlashIcon className="h-4 w-4" />
                    </button>
                    {!category.is_system && (onCategorySettingsClick || onRename) && (
                        <button
                            type="button"
                            className="p-1 -m-1 rounded hover:text-gray-700 hover:bg-gray-100 flex-shrink-0"
                            onClick={(e) => {
                                e.stopPropagation()
                                if (onCategorySettingsClick) {
                                    onCategorySettingsClick(category)
                                } else {
                                    onRename(category)
                                }
                            }}
                            title={onCategorySettingsClick ? 'Category settings' : 'Rename category'}
                            aria-label={onCategorySettingsClick ? 'Category settings' : 'Rename category'}
                        >
                            <PencilIcon className="h-4 w-4" />
                        </button>
                    )}
                    {category.is_system && onRevert && (
                        <button
                            type="button"
                            className="p-1 -m-1 flex-shrink-0 rounded hover:bg-violet-50 hover:text-violet-700"
                            onClick={(e) => {
                                e.stopPropagation()
                                onRevert(category)
                            }}
                            title="Revert to system default"
                            aria-label="Revert to system default"
                        >
                            <ArrowPathIcon className="h-4 w-4" />
                        </button>
                    )}
                    {onDelete && (
                        category.is_system && !category.deletion_available ? (
                            <span
                                className="inline-flex p-1 -m-1 rounded flex-shrink-0 cursor-not-allowed"
                                title="Cannot delete: this system category is in use by brands"
                            >
                                <TrashIcon className="h-4 w-4 text-gray-300" aria-hidden />
                            </span>
                        ) : (
                            <button
                                type="button"
                                className="p-1 -m-1 rounded hover:text-red-600 hover:bg-red-50 flex-shrink-0"
                                onClick={(e) => {
                                    e.stopPropagation()
                                    onDelete(category)
                                }}
                                title="Delete category"
                                aria-label="Delete category"
                            >
                                <TrashIcon className="h-4 w-4" />
                            </button>
                        )
                    )}
                </div>
            )}
        </div>
    )
}

/**
 * Category list: visible folders (drag reorder → sidebar order) + collapsed hidden section.
 */
export default function CategoryList({
    groupedCategories,
    selectedCategoryId,
    onSelectCategory,
    canManageVisibility,
    canManageBrandCategories = false,
    brandId,
    onCategoriesChange,
    onRename,
    onCategorySettingsClick,
    onDelete,
    onRevert,
    onAddCategory,
    onAfterAddSystemCategory,
    onSaveNotice,
    editingCategoryId,
    editingCategoryName,
    onEditingCategoryNameChange,
    onSaveRename,
    onCancelRename,
}) {
    const [reorderLoading, setReorderLoading] = useState(false)
    const [toggleLoading, setToggleLoading] = useState(new Set())
    const [hiddenExpanded, setHiddenExpanded] = useState({ asset: false, deliverable: false })
    const [availableByType, setAvailableByType] = useState({ asset: [], deliverable: [] })
    const [visibleCategoryLimits, setVisibleCategoryLimits] = useState(null)
    const [catalogFilter, setCatalogFilter] = useState({ asset: '', deliverable: '' })
    const [catalogSelectedId, setCatalogSelectedId] = useState({ asset: '', deliverable: '' })
    const [availableLoading, setAvailableLoading] = useState(false)
    const [addingTemplateId, setAddingTemplateId] = useState(null)
    const [addTemplateMessage, setAddTemplateMessage] = useState(null)

    const availableCatalogUrl = useMemo(() => {
        if (!brandId) return ''
        return typeof route === 'function'
            ? route('tenant.metadata.brands.available-system-categories', { brand: brandId })
            : `/app/api/tenant/metadata/brands/${brandId}/available-system-categories`
    }, [brandId])

    useEffect(() => {
        if (!brandId || !availableCatalogUrl) {
            setAvailableByType({ asset: [], deliverable: [] })
            setVisibleCategoryLimits(null)
            return
        }
        setAvailableLoading(true)
        setAddTemplateMessage(null)
        fetch(availableCatalogUrl, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then((r) => {
                if (!r.ok) {
                    throw new Error('failed')
                }
                return r.json()
            })
            .then((data) => {
                const list = data.available_system_templates || []
                setVisibleCategoryLimits(data.visible_category_limits ?? null)
                setAvailableByType({
                    asset: list.filter((t) => t.asset_type === 'asset'),
                    deliverable: list.filter((t) => t.asset_type === 'deliverable'),
                })
            })
            .catch(() => {
                setAvailableByType({ asset: [], deliverable: [] })
                setVisibleCategoryLimits(null)
            })
            .finally(() => setAvailableLoading(false))
    }, [brandId, availableCatalogUrl])

    useEffect(() => {
        ;['asset', 'deliverable'].forEach((key) => {
            const { visible, hidden } = partitionVisibleHidden(groupedCategories[key] || [])
            if (visible.length === 0 && hidden.length > 0) {
                setHiddenExpanded((s) => ({ ...s, [key]: true }))
            }
        })
    }, [groupedCategories])

    const getCsrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || ''

    const handleAddSystemTemplate = useCallback(
        async (template) => {
            if (!brandId || !canManageBrandCategories) {
                return
            }
            setAddTemplateMessage(null)
            setAddingTemplateId(template.system_category_id)
            try {
                const res = await fetch(`/app/brands/${brandId}/categories/add-system-template`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ system_category_id: template.system_category_id }),
                })
                const data = await res.json().catch(() => ({}))
                if (res.ok && data.category) {
                    if (typeof window !== 'undefined' && window.toast) {
                        window.toast(`Added “${template.name}”`, 'success')
                    }
                    onSaveNotice?.(`Added “${template.name}” from catalog.`)
                    onAfterAddSystemCategory?.(data.category)
                    setCatalogSelectedId((s) => ({
                        ...s,
                        [template.asset_type === 'deliverable' ? 'deliverable' : 'asset']: '',
                    }))
                } else {
                    const msg = data.error || data.message || 'Could not add this folder.'
                    setAddTemplateMessage(msg)
                    if (typeof window !== 'undefined' && window.toast) {
                        window.toast(msg, 'error')
                    }
                }
            } catch {
                setAddTemplateMessage('Network error. Please try again.')
            } finally {
                setAddingTemplateId(null)
            }
        },
        [brandId, canManageBrandCategories, onAfterAddSystemCategory, onSaveNotice]
    )

    const replaceGroup = useCallback(
        (assetTypeKey, mergedList) => {
            onCategoriesChange({
                ...groupedCategories,
                [assetTypeKey]: mergedList,
            })
        },
        [groupedCategories, onCategoriesChange]
    )

    const handleDragEnd = useCallback(
        async (event, assetTypeKey) => {
            const { active, over } = event
            if (!over || active.id === over.id || !brandId) return

            const list = groupedCategories[assetTypeKey] || []
            const { visible, hidden } = partitionVisibleHidden(list)
            const oldIndex = visible.findIndex((c) => c.id === active.id)
            const newIndex = visible.findIndex((c) => c.id === over.id)
            if (oldIndex === -1 || newIndex === -1) return

            const newVisible = arrayMove(visible, oldIndex, newIndex)
            const merged = mergeWithRenumberedSortOrder(newVisible, hidden)

            const previous = {
                asset: [...groupedCategories.asset],
                deliverable: [...groupedCategories.deliverable],
            }
            replaceGroup(assetTypeKey, merged)

            setReorderLoading(true)
            try {
                await persistCategoryReorder(brandId, assetTypeKey, merged, getCsrfToken)
                onSaveNotice?.('Folder order saved.')
            } catch (e) {
                onCategoriesChange(previous)
                console.error('[CategoryList] Reorder failed:', e)
                onSaveNotice?.('Could not save folder order.', { variant: 'error' })
            } finally {
                setReorderLoading(false)
            }
        },
        [groupedCategories, brandId, replaceGroup, onCategoriesChange, onSaveNotice]
    )

    const handleToggleHidden = useCallback(
        async (category) => {
            if (!category?.id) return
            const typeKey = category.asset_type === 'deliverable' ? 'deliverable' : 'asset'
            const nextHidden = !category.is_hidden

            setToggleLoading((prev) => new Set(prev).add(category.id))

            const list = (groupedCategories[typeKey] || []).map((c) =>
                c.id === category.id ? { ...c, is_hidden: nextHidden } : c
            )
            const { visible, hidden } = partitionVisibleHidden(list)
            const merged = mergeWithRenumberedSortOrder(visible, hidden)

            const previous = {
                asset: [...groupedCategories.asset],
                deliverable: [...groupedCategories.deliverable],
            }
            replaceGroup(typeKey, merged)

            try {
                const res = await fetch(`/app/api/brands/${brandId}/categories/${category.id}/visibility`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ is_hidden: nextHidden }),
                })
                if (!res.ok) {
                    const err = await res.json().catch(() => ({}))
                    throw new Error(err.message || 'Failed to update')
                }
                await persistCategoryReorder(brandId, typeKey, merged, getCsrfToken)
                onSaveNotice?.(
                    nextHidden ? 'Folder hidden from the library; order saved.' : 'Folder shown in the library; order saved.'
                )
            } catch (e) {
                onCategoriesChange(previous)
                console.error('[CategoryList] Toggle hidden failed:', e)
                onSaveNotice?.('Could not update folder visibility.', { variant: 'error' })
            } finally {
                setToggleLoading((prev) => {
                    const next = new Set(prev)
                    next.delete(category.id)
                    return next
                })
            }
        },
        [groupedCategories, brandId, replaceGroup, onCategoriesChange, onSaveNotice]
    )

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
    )

    const rowProps = {
        onSelect: onSelectCategory,
        onToggleHidden: handleToggleHidden,
        onRename,
        onCategorySettingsClick,
        onDelete,
        onRevert,
        canManageVisibility,
        onSaveRename,
        onCancelRename,
    }

    /** Hidden folders + “available from catalog” in one dashed card (catalog always below hidden header). */
    const renderBelowLibraryList = (assetTypeKey, label, hiddenList) => {
        const hasHidden = hiddenList.length > 0
        const expanded = hiddenExpanded[assetTypeKey]

        const existingSlugKeys = new Set(
            (groupedCategories[assetTypeKey] || []).map((c) => {
                const t = String(c.asset_type || assetTypeKey).toLowerCase()
                return `${t}:${String(c.slug || '').toLowerCase()}`
            })
        )

        let catalogBlock = null
        if (brandId) {
            if (availableLoading) {
                catalogBlock = (
                    <div className="px-2.5 py-2 text-xs text-gray-500" aria-live="polite">
                        Loading platform catalog…
                    </div>
                )
            } else {
                const raw = availableByType[assetTypeKey] || []
                const templates = raw.filter((t) => {
                    const tType = String(t.asset_type || assetTypeKey).toLowerCase()
                    const key = `${tType}:${String(t.slug || '').toLowerCase()}`
                    return !existingSlugKeys.has(key)
                })
                if (templates.length > 0) {
                    const catalogTooltip =
                        CATALOG_HELP + (!canManageBrandCategories ? CATALOG_HELP_VIEW_ONLY : '')
                    const limits = visibleCategoryLimits?.[assetTypeKey]
                    const slotsLine =
                        limits != null
                            ? `Visible folders: ${limits.visible} / ${limits.max} for this brand’s ${
                                  assetTypeKey === 'deliverable' ? 'executions' : 'asset library'
                              }. Hidden folders do not count.`
                            : null
                    const useCompact = templates.length >= CATALOG_COMPACT_THRESHOLD
                    const q = (catalogFilter[assetTypeKey] || '').trim().toLowerCase()
                    const filtered = templates.filter(
                        (t) =>
                            !q ||
                            String(t.name || '')
                                .toLowerCase()
                                .includes(q) ||
                            String(t.slug || '')
                                .toLowerCase()
                                .includes(q)
                    )
                    const selectedRaw = catalogSelectedId[assetTypeKey] || ''
                    const selectedTemplate = filtered.find(
                        (t) => String(t.system_category_id) === String(selectedRaw)
                    )
                    const capTitle =
                        'This folder would start visible, but the brand is at the visible folder limit. Hide another folder first, or choose a catalog template that starts hidden.'

                    catalogBlock = (
                        <div className="px-2 pb-2 pt-1.5">
                            <div className="rounded-lg border border-violet-200/70 bg-violet-50/50 px-3 py-2.5">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="text-xs font-semibold text-violet-950">From catalog</p>
                                        <p className="mt-0.5 text-[11px] leading-snug text-violet-800/85">
                                            Add a platform folder to this brand.
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        className="shrink-0 rounded p-0.5 text-violet-600 hover:bg-violet-100/80 hover:text-violet-900 focus:outline-none focus:ring-2 focus:ring-violet-400/50"
                                        title={catalogTooltip}
                                        aria-label={catalogTooltip}
                                    >
                                        <InformationCircleIcon className="h-4 w-4" aria-hidden />
                                    </button>
                                </div>
                                {slotsLine ? (
                                    <p className="mt-2 text-[11px] leading-snug text-violet-900/75">{slotsLine}</p>
                                ) : null}
                                {useCompact ? (
                                    <div className="mt-2 space-y-2">
                                        <input
                                            type="search"
                                            value={catalogFilter[assetTypeKey] || ''}
                                            onChange={(e) =>
                                                setCatalogFilter((s) => ({
                                                    ...s,
                                                    [assetTypeKey]: e.target.value,
                                                }))
                                            }
                                            placeholder="Filter catalog…"
                                            className="block w-full rounded-md border-0 py-1.5 pl-2 pr-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-violet-200/80 placeholder:text-slate-400 focus:ring-2 focus:ring-violet-400/40"
                                            aria-label="Filter platform catalog folders"
                                        />
                                        <div className="flex flex-col gap-2 sm:flex-row sm:items-stretch">
                                            <select
                                                value={selectedRaw}
                                                onChange={(e) =>
                                                    setCatalogSelectedId((s) => ({
                                                        ...s,
                                                        [assetTypeKey]: e.target.value,
                                                    }))
                                                }
                                                className="block w-full min-w-0 flex-1 rounded-md border-0 py-1.5 pl-2 pr-8 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-violet-200/80 focus:ring-2 focus:ring-violet-400/40"
                                                aria-label="Choose a catalog folder to add"
                                            >
                                                <option value="">Select a catalog folder…</option>
                                                {filtered.map((t) => (
                                                    <option
                                                        key={t.system_category_id}
                                                        value={t.system_category_id}
                                                        disabled={!!t.visible_cap_blocks_add}
                                                    >
                                                        {t.name}
                                                        {t.is_hidden ? ' (starts hidden)' : ''}
                                                        {t.visible_cap_blocks_add ? ' — at visible cap' : ''}
                                                    </option>
                                                ))}
                                            </select>
                                            {canManageBrandCategories ? (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        selectedTemplate && handleAddSystemTemplate(selectedTemplate)
                                                    }
                                                    disabled={
                                                        !selectedTemplate ||
                                                        !!selectedTemplate.visible_cap_blocks_add ||
                                                        addingTemplateId === selectedTemplate.system_category_id
                                                    }
                                                    title={
                                                        selectedTemplate?.visible_cap_blocks_add ? capTitle : undefined
                                                    }
                                                    className="inline-flex min-w-[4.75rem] flex-shrink-0 items-center justify-center gap-1 whitespace-nowrap rounded-md bg-violet-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-violet-500 disabled:pointer-events-none disabled:opacity-50 sm:w-auto"
                                                >
                                                    <PlusIcon className="h-3.5 w-3.5" aria-hidden />
                                                    Add
                                                </button>
                                            ) : null}
                                        </div>
                                    </div>
                                ) : (
                                    <ul className="mt-2 space-y-1">
                                        {templates.map((t) => (
                                            <li
                                                key={t.system_category_id}
                                                className="flex items-center justify-between gap-2 rounded-md bg-white/90 px-2 py-1.5 text-sm text-slate-800 ring-1 ring-inset ring-violet-100/80"
                                            >
                                                <span className="flex min-w-0 items-center gap-2">
                                                    <CategoryIcon
                                                        iconId={t.icon || 'folder'}
                                                        className="h-4 w-4 flex-shrink-0 text-gray-500"
                                                    />
                                                    <span className="truncate font-medium">{t.name}</span>
                                                </span>
                                                {canManageBrandCategories ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleAddSystemTemplate(t)}
                                                        disabled={
                                                            !!t.visible_cap_blocks_add ||
                                                            addingTemplateId === t.system_category_id
                                                        }
                                                        title={t.visible_cap_blocks_add ? capTitle : undefined}
                                                        className="inline-flex min-w-[4.5rem] flex-shrink-0 items-center justify-center gap-1 whitespace-nowrap rounded-md bg-violet-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-violet-500 disabled:pointer-events-none disabled:opacity-50"
                                                    >
                                                        <PlusIcon className="h-3.5 w-3.5" aria-hidden />
                                                        Add
                                                    </button>
                                                ) : null}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </div>
                    )
                }
            }
        }

        const showCatalog = Boolean(catalogBlock)
        if (!hasHidden && !showCatalog) {
            return null
        }

        return (
            <div className="mt-2 rounded-lg border border-dashed border-gray-200 bg-gray-50/90">
                {hasHidden && (
                    <>
                        <div className="flex w-full items-center gap-1 pr-1">
                            <button
                                type="button"
                                onClick={() =>
                                    setHiddenExpanded((s) => ({
                                        ...s,
                                        [assetTypeKey]: !s[assetTypeKey],
                                    }))
                                }
                                className="flex min-w-0 flex-1 items-center gap-2 rounded-lg px-2.5 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 hover:bg-gray-100/80"
                            >
                                {expanded ? (
                                    <ChevronDownIcon className="h-4 w-4 flex-shrink-0 text-gray-500" />
                                ) : (
                                    <ChevronRightIcon className="h-4 w-4 flex-shrink-0 text-gray-500" />
                                )}
                                <span className="truncate">
                                    Hidden — not in {label} ({hiddenList.length})
                                </span>
                            </button>
                            <button
                                type="button"
                                className="flex-shrink-0 rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-300"
                                title={HIDDEN_SECTION_HELP}
                                aria-label={HIDDEN_SECTION_HELP}
                                onClick={(e) => e.stopPropagation()}
                            >
                                <InformationCircleIcon className="h-4 w-4" aria-hidden />
                            </button>
                        </div>
                        {expanded && (
                            <div className="border-t border-gray-200/90 px-1 pb-2 pt-0">
                                <div className="space-y-0.5">
                                    {hiddenList.map((category) => (
                                        <HiddenCategoryRow
                                            key={category.id}
                                            category={category}
                                            isSelected={selectedCategoryId === category.id}
                                            isEditing={editingCategoryId === category.id}
                                            editName={editingCategoryName}
                                            onEditNameChange={onEditingCategoryNameChange}
                                            {...rowProps}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}
                    </>
                )}
                {hasHidden && showCatalog && <div className="border-t border-gray-200/90" />}
                {catalogBlock}
            </div>
        )
    }

    const renderSection = (assetTypeKey, sectionTitle, libraryLabel) => {
        const list = groupedCategories[assetTypeKey] || []
        const { visible, hidden } = partitionVisibleHidden(list)
        const visibleIds = visible.map((c) => c.id)

        return (
            <div>
                <div className="text-xs uppercase tracking-widest text-gray-400 pb-2 border-b border-gray-100">
                    {sectionTitle}
                </div>
                {list.length === 0 ? (
                    <p className="py-3 text-sm text-gray-500 italic">No categories yet</p>
                ) : (
                    <>
                        {visible.length === 0 ? (
                            <p className="py-2 text-sm text-amber-800/90 bg-amber-50/80 rounded-lg px-2 mt-1">
                                All folders are hidden for this brand. Expand &quot;Hidden&quot; below to select one and manage fields, or show a folder with the eye icon.
                            </p>
                        ) : (
                            <DndContext
                                sensors={sensors}
                                collisionDetection={closestCenter}
                                onDragEnd={(e) => handleDragEnd(e, assetTypeKey)}
                            >
                                <SortableContext items={visibleIds} strategy={verticalListSortingStrategy}>
                                    <div className="space-y-1 mt-1">
                                        {visible.map((category) => (
                                            <SortableCategoryRow
                                                key={category.id}
                                                category={category}
                                                isSelected={selectedCategoryId === category.id}
                                                isEditing={editingCategoryId === category.id}
                                                editName={editingCategoryName}
                                                onEditNameChange={onEditingCategoryNameChange}
                                                {...rowProps}
                                            />
                                        ))}
                                    </div>
                                </SortableContext>
                            </DndContext>
                        )}
                        {renderBelowLibraryList(assetTypeKey, libraryLabel, hidden)}
                    </>
                )}
                {list.length === 0 && renderBelowLibraryList(assetTypeKey, libraryLabel, [])}
            </div>
        )
    }

    return (
        <div className="space-y-6">
            {renderSection('asset', 'Asset Library', 'asset library')}
            {renderSection('deliverable', 'Executions', 'executions')}

            {addTemplateMessage && (
                <p className="text-xs text-red-600 px-0.5" role="alert">
                    {addTemplateMessage}
                </p>
            )}

            {onAddCategory && (
                <button
                    type="button"
                    onClick={onAddCategory}
                    className="mt-2 w-full inline-flex items-center justify-center gap-1.5 rounded-lg border border-dashed border-gray-200 px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 hover:border-gray-300 transition-colors"
                >
                    <span className="text-base leading-none">+</span>
                    Add Category
                </button>
            )}

            {(reorderLoading || toggleLoading.size > 0) && (
                <div className="text-xs text-gray-500 mt-2">Saving…</div>
            )}
        </div>
    )
}
