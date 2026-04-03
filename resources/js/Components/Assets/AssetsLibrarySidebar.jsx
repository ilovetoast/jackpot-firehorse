/**
 * AssetsLibrarySidebar — Reusable sidebar for Assets, Executions, and Generative pages.
 *
 * Structure:
 *   LIBRARY — All, categories (library content)
 *   SYSTEM   — Staged, References, Trash (workflow queues)
 *
 * Each page can show/hide system items via props.
 * Backend queries unchanged; only sidebar grouping and labels.
 */
import {
    TagIcon,
    FolderIcon,
    MagnifyingGlassIcon,
    TrashIcon,
    LockClosedIcon,
} from '@heroicons/react/24/outline'
import { CategoryIcon } from '../../Helpers/categoryIcons'
import { filterActiveCategories } from '../../utils/categoryUtils'

export default function AssetsLibrarySidebar({
    // LIBRARY section
    categories = [],
    showAllButton = false,
    totalAssetCount = 0,
    selectedCategoryId,
    onCategorySelect,
    lifecycle = '',
    isResearchView = false,
    isStagedView = false,
    // SYSTEM section visibility (per page)
    showStaged = false,
    stagedCount = 0,
    showResearch = false,
    researchCount = 0,
    showTrash = false,
    canViewTrash = false,
    trashCount = 0,
    onResearchClick,
    onStagedClick,
    onTrashClick,
    // Layout
    sidebarColor = '#1f2937',
    textColor,
    activeBgColor,
    activeTextColor,
    hoverBgColor,
    unselectedTextColor,
    unselectedIconColor,
    unselectedCountColor,
    // Optional: custom add button slot
    addButtonSlot,
    // Optional: empty state when no categories
    emptyLabel = 'No categories yet',
}) {
    const hasSystemItems = showStaged || showResearch || (showTrash && canViewTrash)
    // References always shown when enabled so users can open builder reference materials
    const hasAnySystemContent = (showStaged && stagedCount > 0) || showResearch || (showTrash && canViewTrash && (trashCount > 0 || lifecycle === 'deleted'))

    const sectionLabelColor = textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)'
    const borderColor = textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.15)' : 'rgba(0, 0, 0, 0.12)'

    const isLibraryAllSelected = selectedCategoryId == null && lifecycle !== 'deleted' && !isResearchView && !isStagedView

    return (
        <nav className="mt-3 lg:mt-5 flex-1 px-1.5 lg:px-2 space-y-1">
            {addButtonSlot && (
                <div className="px-2 py-1.5 lg:px-3 lg:py-2 mb-3 lg:mb-4">
                    {addButtonSlot}
                </div>
            )}

            {categories && categories.length > 0 && (
                <div className="px-2 py-1.5 lg:px-3 lg:py-2">
                    {/* LIBRARY section */}
                    <h3 className="px-2 lg:px-3 text-xs font-semibold uppercase tracking-wider" style={{ color: sectionLabelColor }}>
                        Library
                    </h3>
                    <div className="mt-1.5 lg:mt-2 space-y-1">
                        {showAllButton && (
                            <button
                                onClick={() => onCategorySelect?.(null)}
                                className="group flex items-center px-2 py-1.5 lg:px-3 lg:py-2 text-sm font-medium rounded-md w-full text-left"
                                style={{
                                    backgroundColor: isLibraryAllSelected ? activeBgColor : 'transparent',
                                    color: isLibraryAllSelected ? activeTextColor : unselectedTextColor,
                                }}
                                onMouseEnter={(e) => {
                                    if (!isLibraryAllSelected) {
                                        e.currentTarget.style.backgroundColor = hoverBgColor
                                        e.currentTarget.style.color = activeTextColor
                                    }
                                }}
                                onMouseLeave={(e) => {
                                    if (!isLibraryAllSelected) {
                                        e.currentTarget.style.backgroundColor = 'transparent'
                                        e.currentTarget.style.color = unselectedTextColor
                                    }
                                }}
                            >
                                <TagIcon className="mr-2 lg:mr-3 flex-shrink-0 h-5 w-5" style={{ color: isLibraryAllSelected ? activeTextColor : unselectedIconColor }} />
                                <span className="flex-1">All</span>
                                {totalAssetCount > 0 && (
                                    <span className="text-xs font-normal opacity-80" style={{ color: isLibraryAllSelected ? activeTextColor : unselectedCountColor }}>
                                        {totalAssetCount}
                                    </span>
                                )}
                            </button>
                        )}
                        {filterActiveCategories(categories).map((category) => {
                            const isSelected = selectedCategoryId === category.id && selectedCategoryId != null
                            return (
                                <button
                                    key={category.id}
                                    onClick={() => onCategorySelect?.(category)}
                                    className="group flex items-center px-2 py-1.5 lg:px-3 lg:py-2 text-sm font-medium rounded-md w-full text-left"
                                    style={{
                                        backgroundColor: isSelected ? activeBgColor : 'transparent',
                                        color: isSelected ? activeTextColor : unselectedTextColor,
                                    }}
                                    onMouseEnter={(e) => {
                                        if (!isSelected) {
                                            e.currentTarget.style.backgroundColor = hoverBgColor
                                            e.currentTarget.style.color = activeTextColor
                                        }
                                    }}
                                    onMouseLeave={(e) => {
                                        if (!isSelected) {
                                            e.currentTarget.style.backgroundColor = 'transparent'
                                            e.currentTarget.style.color = unselectedTextColor
                                        }
                                    }}
                                >
                                    <CategoryIcon
                                        iconId={category.icon || 'folder'}
                                        className="mr-2 lg:mr-3 flex-shrink-0 h-5 w-5"
                                        style={{ color: isSelected ? activeTextColor : unselectedIconColor }}
                                    />
                                    <span className="flex-1">{category.name}</span>
                                    {category.id != null &&
                                        typeof category.asset_count === 'number' && (
                                        <span className="text-xs font-normal opacity-80 ml-2" style={{ color: isSelected ? activeTextColor : unselectedCountColor }}>
                                            {category.asset_count}
                                        </span>
                                    )}
                                    {category.is_private && (
                                        <div className="relative ml-2 group">
                                            <LockClosedIcon
                                                className="h-4 w-4 flex-shrink-0 cursor-help"
                                                style={{ color: isSelected ? activeTextColor : unselectedIconColor }}
                                            />
                                        </div>
                                    )}
                                </button>
                            )
                        })}

                        {/* SYSTEM section */}
                        {hasSystemItems && hasAnySystemContent && (
                            <>
                                <div className="my-1.5 border-t" style={{ borderColor }} />
                                <h3 className="px-2 lg:px-3 text-xs font-semibold uppercase tracking-wider mt-2" style={{ color: sectionLabelColor }}>
                                    System
                                </h3>
                                {showStaged && (
                                            <button
                                                onClick={onStagedClick}
                                                className="group flex items-center px-2 py-1.5 lg:px-3 lg:py-2 text-sm font-medium rounded-md w-full text-left"
                                                style={{
                                                    backgroundColor: isStagedView ? activeBgColor : 'transparent',
                                                    color: isStagedView ? activeTextColor : unselectedTextColor,
                                                }}
                                                onMouseEnter={(e) => {
                                                    if (!isStagedView) {
                                                        e.currentTarget.style.backgroundColor = hoverBgColor
                                                        e.currentTarget.style.color = activeTextColor
                                                    }
                                                }}
                                                onMouseLeave={(e) => {
                                                    if (!isStagedView) {
                                                        e.currentTarget.style.backgroundColor = 'transparent'
                                                        e.currentTarget.style.color = unselectedTextColor
                                                    }
                                                }}
                                            >
                                                <FolderIcon className="mr-2 lg:mr-3 flex-shrink-0 h-5 w-5" style={{ color: isStagedView ? activeTextColor : unselectedIconColor }} />
                                                <span className="flex-1">Staged</span>
                                                {stagedCount > 0 && (
                                                    <span className="text-xs font-normal opacity-80" style={{ color: isStagedView ? activeTextColor : unselectedCountColor }}>
                                                        {stagedCount}
                                                    </span>
                                                )}
                                            </button>
                                        )}
                                        {showResearch && (
                                            <button
                                                onClick={onResearchClick}
                                                className="group flex items-center px-2 py-1.5 lg:px-3 lg:py-2 text-sm font-medium rounded-md w-full text-left"
                                                style={{
                                                    backgroundColor: isResearchView ? activeBgColor : 'transparent',
                                                    color: isResearchView ? activeTextColor : unselectedTextColor,
                                                }}
                                                onMouseEnter={(e) => {
                                                    if (!isResearchView) {
                                                        e.currentTarget.style.backgroundColor = hoverBgColor
                                                        e.currentTarget.style.color = activeTextColor
                                                    }
                                                }}
                                                onMouseLeave={(e) => {
                                                    if (!isResearchView) {
                                                        e.currentTarget.style.backgroundColor = 'transparent'
                                                        e.currentTarget.style.color = unselectedTextColor
                                                    }
                                                }}
                                            >
                                                <MagnifyingGlassIcon className="mr-2 lg:mr-3 flex-shrink-0 h-5 w-5" style={{ color: isResearchView ? activeTextColor : unselectedIconColor }} />
                                                <span className="flex-1">References</span>
                                                {researchCount > 0 && (
                                                    <span className="text-xs font-normal opacity-80" style={{ color: isResearchView ? activeTextColor : unselectedCountColor }}>
                                                        {researchCount}
                                                    </span>
                                                )}
                                            </button>
                                        )}
                                        {showTrash && canViewTrash && (trashCount > 0 || lifecycle === 'deleted') && (
                                            <button
                                                onClick={onTrashClick}
                                                className="group flex items-center px-2 py-1.5 lg:px-3 lg:py-2 text-sm font-medium rounded-md w-full text-left"
                                                style={{
                                                    backgroundColor: lifecycle === 'deleted' ? activeBgColor : 'transparent',
                                                    color: lifecycle === 'deleted' ? activeTextColor : unselectedTextColor,
                                                }}
                                                onMouseEnter={(e) => {
                                                    if (lifecycle !== 'deleted') {
                                                        e.currentTarget.style.backgroundColor = hoverBgColor
                                                        e.currentTarget.style.color = activeTextColor
                                                    }
                                                }}
                                                onMouseLeave={(e) => {
                                                    if (lifecycle !== 'deleted') {
                                                        e.currentTarget.style.backgroundColor = 'transparent'
                                                        e.currentTarget.style.color = unselectedTextColor
                                                    }
                                                }}
                                            >
                                                <TrashIcon className="mr-2 lg:mr-3 flex-shrink-0 h-5 w-5" style={{ color: lifecycle === 'deleted' ? activeTextColor : unselectedIconColor }} />
                                                <span className="flex-1">Trash</span>
                                                {trashCount > 0 && (
                                                    <span className="text-xs font-normal opacity-80" style={{ color: lifecycle === 'deleted' ? activeTextColor : unselectedCountColor }}>
                                                        {trashCount}
                                                    </span>
                                                )}
                                            </button>
                                        )}
                            </>
                        )}
                    </div>
                </div>
            )}

            {(!categories || categories.length === 0) && (
                <div className="px-3 py-2 text-sm" style={{ color: sectionLabelColor }}>
                    {emptyLabel}
                </div>
            )}
        </nav>
    )
}
