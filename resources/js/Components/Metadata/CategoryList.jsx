import { useState, useCallback } from 'react'
import { router } from '@inertiajs/react'
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
import { ArrowPathIcon, ArrowUpCircleIcon, Bars3Icon, EyeIcon, EyeSlashIcon, PencilIcon, TrashIcon } from '@heroicons/react/24/outline'

/**
 * Sortable category row for Metadata page.
 * Compact: drag handle, name, visibility icon, upgrade icon (on hover). No badges.
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
    onUpgrade,
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

    const isHidden = category.is_hidden === true

    const hasUpgrade = (category.upgrade_available || category.has_update_available) && category.is_system

    return (
        <div
            ref={setNodeRef}
            style={style}
            className={`group flex items-center justify-between gap-2 py-2 px-2 rounded-lg text-sm transition-all duration-200 ease-out cursor-default ${
                isSelected ? 'bg-indigo-50/80 text-indigo-700 font-medium' : 'text-gray-700 hover:bg-gray-50/80'
            } ${isHidden ? 'opacity-50' : ''} ${isDragging ? 'opacity-90 shadow-md z-10 bg-white rounded-lg' : ''}`}
        >
            {canManageVisibility && (
                <button
                    type="button"
                    className="p-0.5 -m-0.5 rounded cursor-grab active:cursor-grabbing text-gray-400 opacity-60 hover:opacity-100 hover:text-gray-600 touch-none flex-shrink-0 transition-opacity"
                    {...attributes}
                    {...listeners}
                    onClick={(e) => e.stopPropagation()}
                    aria-label="Drag to reorder"
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
                        className="w-full rounded border border-gray-300 px-2 py-0.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        autoFocus
                        onClick={(e) => e.stopPropagation()}
                    />
                ) : (
                    <>
                        {hasUpgrade && (
                            <span className="w-1.5 h-1.5 rounded-full bg-amber-400/80 flex-shrink-0" aria-hidden title="Upgrade available" />
                        )}
                        <span className="truncate">{category.name}</span>
                    </>
                )}
            </button>
            {canManageVisibility && (
                <div className="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity duration-200 flex-shrink-0">
                    <button
                        type="button"
                        className="p-1 -m-1 rounded text-gray-400 hover:text-gray-600 hover:bg-gray-100 flex-shrink-0"
                        onClick={(e) => {
                            e.stopPropagation()
                            onToggleHidden(category)
                        }}
                        title={isHidden ? 'Show in uploader and asset grid' : 'Hidden from uploader and asset grid'}
                        aria-label={isHidden ? 'Show category' : 'Hide category'}
                    >
                        {isHidden ? (
                            <EyeSlashIcon className="h-4 w-4" />
                        ) : (
                            <EyeIcon className="h-4 w-4" />
                        )}
                    </button>
                    {!category.is_system && (onCategorySettingsClick || onRename) && (
                        <button
                            type="button"
                            className="p-1 -m-1 rounded text-gray-400 hover:text-gray-600 hover:bg-gray-100 flex-shrink-0"
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
                    {hasUpgrade && onUpgrade && (
                        <button
                            type="button"
                            className="p-1 -m-1 rounded text-gray-400 hover:text-amber-600 hover:bg-amber-50 flex-shrink-0"
                            onClick={(e) => {
                                e.stopPropagation()
                                onUpgrade(category)
                            }}
                            title="Upgrade category"
                            aria-label="Upgrade category"
                        >
                            <ArrowUpCircleIcon className="h-3.5 w-3.5" />
                        </button>
                    )}
                    {category.is_system && onRevert && (
                        <button
                            type="button"
                            className="p-1 -m-1 rounded text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 flex-shrink-0"
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
                                className="p-1 -m-1 rounded text-gray-400 hover:text-red-600 hover:bg-red-50 flex-shrink-0"
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
 * Category list with drag-and-drop reordering and hide toggle.
 * Two separate sortable groups: Asset Categories and Execution Categories.
 */
export default function CategoryList({
    groupedCategories,
    selectedCategoryId,
    onSelectCategory,
    canManageVisibility,
    brandId,
    onCategoriesChange,
    onRename,
    onCategorySettingsClick,
    onDelete,
    onRevert,
    onUpgrade,
    onAddCategory,
    editingCategoryId,
    editingCategoryName,
    onEditingCategoryNameChange,
    onSaveRename,
    onCancelRename,
}) {
    const [reorderLoading, setReorderLoading] = useState(false)
    const [toggleLoading, setToggleLoading] = useState(new Set())

    const getCsrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || ''

    const handleDragEnd = useCallback(
        async (event, assetType) => {
            const { active, over } = event
            if (!over || active.id === over.id || !brandId) return

            const list = assetType === 'asset' ? groupedCategories.asset : groupedCategories.deliverable
            const oldIndex = list.findIndex((c) => c.id === active.id)
            const newIndex = list.findIndex((c) => c.id === over.id)
            if (oldIndex === -1 || newIndex === -1) return

            const newOrder = arrayMove(list, oldIndex, newIndex)
            const payload = {
                asset_type: assetType,
                categories: newOrder.map((c, i) => ({ id: c.id, sort_order: i + 1 })),
            }

            // Optimistic update
            const previous = { asset: [...groupedCategories.asset], deliverable: [...groupedCategories.deliverable] }
            if (assetType === 'asset') {
                onCategoriesChange({ ...groupedCategories, asset: newOrder })
            } else {
                onCategoriesChange({ ...groupedCategories, deliverable: newOrder })
            }

            setReorderLoading(true)
            try {
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
            } catch (e) {
                onCategoriesChange(previous)
                console.error('[CategoryList] Reorder failed:', e)
            } finally {
                setReorderLoading(false)
            }
        },
        [groupedCategories, brandId, onCategoriesChange]
    )

    const handleToggleHidden = useCallback(
        async (category) => {
            if (!category?.id) return
            setToggleLoading((prev) => new Set(prev).add(category.id))
            const nextHidden = !category.is_hidden

            const updateLocal = (cat) =>
                cat.id === category.id ? { ...cat, is_hidden: nextHidden } : cat
            const previousAsset = groupedCategories.asset.map(updateLocal)
            const previousDeliverable = groupedCategories.deliverable.map(updateLocal)
            onCategoriesChange({ asset: previousAsset, deliverable: previousDeliverable })

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
            } catch (e) {
                onCategoriesChange(groupedCategories)
                console.error('[CategoryList] Toggle hidden failed:', e)
            } finally {
                setToggleLoading((prev) => {
                    const next = new Set(prev)
                    next.delete(category.id)
                    return next
                })
            }
        },
        [groupedCategories, brandId, onCategoriesChange]
    )

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
    )

    const assetIds = groupedCategories.asset.map((c) => c.id)
    const deliverableIds = groupedCategories.deliverable.map((c) => c.id)

    return (
        <div className="space-y-6">
            {groupedCategories.asset.length > 0 && (
                <div>
                    <div className="text-xs uppercase tracking-widest text-gray-400 pb-2 border-b border-gray-100">
                        Asset Library
                    </div>
                    <DndContext
                        sensors={sensors}
                        collisionDetection={closestCenter}
                        onDragEnd={(e) => handleDragEnd(e, 'asset')}
                    >
                        <SortableContext items={assetIds} strategy={verticalListSortingStrategy}>
                            <div className="space-y-1">
                                {groupedCategories.asset.map((category) => (
                                    <SortableCategoryRow
                                        key={category.id}
                                        category={category}
                                        isSelected={selectedCategoryId === category.id}
                                        onSelect={onSelectCategory}
                                        onToggleHidden={handleToggleHidden}
                                        onRename={onRename}
                                        onCategorySettingsClick={onCategorySettingsClick}
                                        onDelete={onDelete}
                                        onRevert={onRevert}
                                        onUpgrade={onUpgrade}
                                        canManageVisibility={canManageVisibility}
                                        isEditing={editingCategoryId === category.id}
                                        editName={editingCategoryName}
                                        onEditNameChange={onEditingCategoryNameChange}
                                        onSaveRename={onSaveRename}
                                        onCancelRename={onCancelRename}
                                    />
                                ))}
                            </div>
                        </SortableContext>
                    </DndContext>
                </div>
            )}

            {groupedCategories.asset.length === 0 && (
                <div>
                    <div className="text-xs uppercase tracking-widest text-gray-400 pb-2 border-b border-gray-100">
                        Asset Library
                    </div>
                    <p className="py-3 text-sm text-gray-500 italic">No asset categories yet</p>
                </div>
            )}

            {groupedCategories.deliverable.length > 0 && (
                <div>
                    <div className="text-xs uppercase tracking-widest text-gray-400 pb-2 border-b border-gray-100">
                        Executions
                    </div>
                    <DndContext
                        sensors={sensors}
                        collisionDetection={closestCenter}
                        onDragEnd={(e) => handleDragEnd(e, 'deliverable')}
                    >
                        <SortableContext items={deliverableIds} strategy={verticalListSortingStrategy}>
                            <div className="space-y-1">
                                {groupedCategories.deliverable.map((category) => (
                                    <SortableCategoryRow
                                        key={category.id}
                                        category={category}
                                        isSelected={selectedCategoryId === category.id}
                                        onSelect={onSelectCategory}
                                        onToggleHidden={handleToggleHidden}
                                        onRename={onRename}
                                        onCategorySettingsClick={onCategorySettingsClick}
                                        onDelete={onDelete}
                                        onRevert={onRevert}
                                        onUpgrade={onUpgrade}
                                        canManageVisibility={canManageVisibility}
                                        isEditing={editingCategoryId === category.id}
                                        editName={editingCategoryName}
                                        onEditNameChange={onEditingCategoryNameChange}
                                        onSaveRename={onSaveRename}
                                        onCancelRename={onCancelRename}
                                    />
                                ))}
                            </div>
                        </SortableContext>
                    </DndContext>
                </div>
            )}

            {groupedCategories.deliverable.length === 0 && (
                <div>
                    <div className="text-xs uppercase tracking-widest text-gray-400 pb-2 border-b border-gray-100">
                        Executions
                    </div>
                    <p className="py-3 text-sm text-gray-500 italic">No execution categories yet</p>
                </div>
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

            {reorderLoading && (
                <div className="text-xs text-gray-500 mt-2">Saving order…</div>
            )}
        </div>
    )
}
