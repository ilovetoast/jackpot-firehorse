/**
 * Reusable asset sidebar with LIBRARY and SYSTEM sections.
 * Used by Assets, Executions, and Generative pages.
 *
 * LIBRARY: All, category list (Logos, Photography, etc.)
 * SYSTEM: Staged, References, Trash (workflow queues). Staged + References are hidden from
 * contributors/viewers via showStaged/showResearch from the parent (brand admin/manager, tenant admin, agency).
 *
 * References = builder reference materials (source=reference_materials, type=REFERENCE)
 * Staged = assets without category (intake_state=staged)
 * Trash = soft-deleted assets
 */
import { useState, useCallback } from 'react'
import { Link, router } from '@inertiajs/react'
import {
    TagIcon,
    DocumentTextIcon,
    FolderIcon,
    TrashIcon,
    ChevronRightIcon,
    LockClosedIcon,
    Cog6ToothIcon,
    PlusIcon,
} from '@heroicons/react/24/outline'
import { CategoryIcon } from '../Helpers/categoryIcons'
import FolderSchemaHelp from './Metadata/FolderSchemaHelp'
import FolderQuickFilters from './Sidebar/FolderQuickFilters'
import OnlineUsersIndicator from './OnlineUsersIndicator'
import {
    getWorkspaceContextualTone,
    getWorkspaceSidebarForegroundHex,
    getWorkspaceSidebarActiveRowForegroundHex,
    getLuminance,
    normalizeHexColor,
} from '../utils/colorUtils'

const STORAGE_KEY = 'workspace-sidebar-system-collapsed'

const sectionLabelStyle = (textColor) => ({
    color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)',
})

const NavButton = ({
    isSelected,
    onClick,
    icon: Icon,
    label,
    count,
    title: tooltip,
    activeBgColor,
    activeTextColor,
    hoverBgColor,
    unselectedTextColor,
    unselectedIconColor,
    unselectedCountColor,
    iconStyle,
    extra,
}) => (
    <button
        onClick={onClick}
        title={tooltip}
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
        {Icon && (
            <Icon
                className="mr-2 lg:mr-3 flex-shrink-0 h-5 w-5"
                style={{ color: isSelected ? activeTextColor : unselectedIconColor }}
            />
        )}
        <span className="flex-1">{label}</span>
        {typeof count === 'number' && count > 0 && (
            <span
                className="text-xs font-normal tabular-nums opacity-80"
                style={{ color: isSelected ? activeTextColor : unselectedCountColor }}
            >
                {count}
            </span>
        )}
        {extra}
    </button>
)

export default function AssetSidebar({
    addAssetButton,
    categories = [],
    filterCategories,
    showAllButton = false,
    totalAssetCount = 0,
    selectedCategoryId,
    onCategorySelect,
    lifecycle = '',
    source = '',
    canViewTrash = false,
    trashCount = 0,
    researchCount = 0,
    stagedCount = 0,
    showStaged = true,
    showResearch = true,
    baseUrl = '/app/assets',
    emptyMessage = 'No folders yet',
    onResearchClick,
    onStagedClick,
    onTrashClick,
    sidebarColor = '#1f2937',
    /** When set, Overview-style cinematic gradient (brand primary + secondary glows on #0B0B0D). */
    sidebarBackdropCss = null,
    workspaceAccentColor,
    tooltipVisible,
    setTooltipVisible,
    canManageCategoriesAndFields = false,
    activeBrandId = null,
    onAddCategoryClick,
    /** (i) folder fields flyout on row hover — same API as Manage; requires metadata registry view. */
    showFolderSchemaHelp = false,
}) {
    const isResearchView = source === 'reference_materials'
    const isStagedView = source === 'staged'

    const [systemCollapsed, setSystemCollapsed] = useState(() => {
        if (typeof window === 'undefined') return true
        try {
            const stored = localStorage.getItem(STORAGE_KEY)
            if (stored === null) return true
            return stored === 'true'
        } catch {
            return true
        }
    })

    const toggleSystemCollapsed = useCallback(() => {
        setSystemCollapsed((prev) => {
            const next = !prev
            try {
                localStorage.setItem(STORAGE_KEY, String(next))
            } catch {}
            return next
        })
    }, [])

    const [libraryRowActiveId, setLibraryRowActiveId] = useState(null)

    const textColor =
        sidebarBackdropCss != null && String(sidebarBackdropCss).trim() !== ''
            ? '#ffffff'
            : getWorkspaceSidebarForegroundHex(sidebarColor)
    const contextualDarkColor = getWorkspaceContextualTone(workspaceAccentColor || '#6366f1')
    const activeBgColor = contextualDarkColor
    const activeTextColor = getWorkspaceSidebarActiveRowForegroundHex(contextualDarkColor, textColor)
    const hoverBgColor = contextualDarkColor
    const unselectedTextColor = textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.65)' : 'rgba(0, 0, 0, 0.65)'
    const unselectedIconColor = textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.6)' : 'rgba(0, 0, 0, 0.6)'
    const unselectedCountColor = textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.5)' : 'rgba(0, 0, 0, 0.5)'
    const borderColor = textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.15)' : 'rgba(0, 0, 0, 0.12)'

    const folderSchemaTriggerClass =
        textColor === '#ffffff'
            ? 'flex h-full min-h-[2.25rem] w-full min-w-[2rem] items-center justify-center px-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-white/40 focus-visible:ring-offset-0'
            : 'flex h-full min-h-[2.25rem] w-full min-w-[2rem] items-center justify-center px-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-400/50 focus-visible:ring-offset-0'

    const isLibrarySelected = (id) =>
        selectedCategoryId === id && lifecycle !== 'deleted' && !isResearchView && !isStagedView

    // Show SYSTEM section when any workflow area is configured (Staged, Research, or Trash)
    const hasSystemItems =
        showStaged ||
        showResearch ||
        canViewTrash

    const filteredCategories = filterCategories ? filterCategories(categories) : categories

    return (
        <div
            className="flex flex-col w-64 xl:w-72 h-full transition-[width] duration-200"
            style={
                sidebarBackdropCss != null && String(sidebarBackdropCss).trim() !== ''
                    ? { background: sidebarBackdropCss, backgroundColor: '#0B0B0D' }
                    : { backgroundColor: sidebarColor }
            }
        >
            <div className="flex-1 flex flex-col pt-4 pb-3 lg:pt-5 lg:pb-4 overflow-y-auto">
                <nav className="mt-3 lg:mt-5 flex-1 px-1.5 lg:px-2 space-y-1">
                    {addAssetButton && (
                        <div className="px-2 py-1.5 lg:px-3 lg:py-2 mb-3 lg:mb-4">{addAssetButton}</div>
                    )}

                    {(categories?.length > 0 || showAllButton) && (
                        <div className="px-2 py-1.5 lg:px-3 lg:py-2">
                            <div
                                className="flex items-center justify-between gap-1 px-2 lg:px-3"
                                style={sectionLabelStyle(textColor)}
                            >
                                <h3 className="text-xs font-semibold uppercase tracking-wider">
                                    LIBRARY
                                </h3>
                                {canManageCategoriesAndFields && (
                                    <div className="flex items-center gap-0.5">
                                        {activeBrandId && (
                                            <Link
                                                href={
                                                    typeof route === 'function'
                                                        ? route('manage.categories')
                                                        : '/app/manage/categories'
                                                }
                                                className="rounded p-1 opacity-70 hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-white/30"
                                                title="Manage folders and fields"
                                                aria-label="Manage folders and fields"
                                            >
                                                <Cog6ToothIcon className="h-3.5 w-3.5" />
                                            </Link>
                                        )}
                                        <button
                                            type="button"
                                            onClick={onAddCategoryClick ?? (() => {})}
                                            className="rounded p-1 opacity-70 hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-white/30"
                                            title="Add folder"
                                            aria-label="Add folder"
                                        >
                                            <PlusIcon className="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                )}
                            </div>
                            <div className="mt-1.5 lg:mt-2 space-y-1">
                                {showAllButton && (
                                    <NavButton
                                        isSelected={isLibrarySelected(null)}
                                        onClick={() => onCategorySelect(null)}
                                        icon={TagIcon}
                                        label="All"
                                        count={totalAssetCount}
                                        activeBgColor={activeBgColor}
                                        activeTextColor={activeTextColor}
                                        hoverBgColor={hoverBgColor}
                                        unselectedTextColor={unselectedTextColor}
                                        unselectedIconColor={unselectedIconColor}
                                        unselectedCountColor={unselectedCountColor}
                                    />
                                )}
                                {filteredCategories.map((category) => {
                                    const isSelected = selectedCategoryId === category.id && lifecycle !== 'deleted'
                                    const categoryCount = Number(category.asset_count)
                                    const showCategoryCount =
                                        category.id != null && Number.isFinite(categoryCount) && categoryCount > 0
                                    const showSchemaTrigger = showFolderSchemaHelp && category?.id != null
                                    const showRightTrail = showSchemaTrigger || showCategoryCount
                                    const rowHighlighted =
                                        isSelected ||
                                        (!isSelected && libraryRowActiveId === category.id)
                                    const rowBg = isSelected ? activeBgColor : libraryRowActiveId === category.id ? hoverBgColor : 'transparent'
                                    const rowFg = rowHighlighted ? activeTextColor : unselectedTextColor
                                    const rowIconColor = rowHighlighted ? activeTextColor : unselectedIconColor
                                    const rowCountColor = rowHighlighted ? activeTextColor : unselectedCountColor

                                    return (
                                        <div key={category.id} className="min-w-0">
                                        <div
                                            className="group flex min-w-0 w-full items-stretch rounded-md focus-within:outline-none"
                                            style={{ backgroundColor: rowBg }}
                                            onMouseEnter={() => {
                                                if (!isSelected) setLibraryRowActiveId(category.id)
                                            }}
                                            onMouseLeave={(e) => {
                                                const rel = e.relatedTarget
                                                // Still inside this row (e.g. moving onto the nested button) — keep hover.
                                                if (rel instanceof Node && e.currentTarget.contains(rel)) return
                                                // Pointer left the row: always clear hover. Do not gate on
                                                // document.activeElement — after a click the button stays focused
                                                // and would block clearing, leaving the row "stuck" highlighted.
                                                setLibraryRowActiveId((id) =>
                                                    id === category.id ? null : id
                                                )
                                            }}
                                            onFocusCapture={() => {
                                                if (!isSelected) setLibraryRowActiveId(category.id)
                                            }}
                                            onBlurCapture={(e) => {
                                                if (!e.currentTarget.contains(e.relatedTarget)) {
                                                    setLibraryRowActiveId((id) =>
                                                        id === category.id ? null : id
                                                    )
                                                }
                                            }}
                                        >
                                            <button
                                                type="button"
                                                onClick={() => onCategorySelect(category)}
                                                className={`flex min-w-0 flex-1 items-center py-1.5 text-left text-sm font-medium lg:py-2 pl-2 lg:pl-3 ${
                                                    showRightTrail
                                                        ? 'rounded-l-md pr-1'
                                                        : 'rounded-md pr-2 lg:pr-3'
                                                }`}
                                                style={{
                                                    backgroundColor: 'transparent',
                                                    color: rowFg,
                                                }}
                                            >
                                                <CategoryIcon
                                                    iconId={category.icon || 'folder'}
                                                    className="mr-2 lg:mr-3 flex-shrink-0 h-5 w-5"
                                                    style={{ color: rowIconColor }}
                                                />
                                                <span className="min-w-0 flex-1 truncate">{category.name}</span>
                                                {category.is_private && setTooltipVisible && (
                                                    <div className="relative ml-2 shrink-0 group/lock">
                                                        <LockClosedIcon
                                                            className="h-4 w-4 flex-shrink-0 cursor-help"
                                                            style={{ color: rowIconColor }}
                                                            onMouseEnter={() => setTooltipVisible(category.id)}
                                                            onMouseLeave={() => setTooltipVisible(null)}
                                                        />
                                                        {tooltipVisible === category.id && (
                                                            <div
                                                                className="absolute right-full mr-2 top-1/2 transform -translate-y-1/2 bg-gray-900 text-white text-xs rounded-lg shadow-xl z-[9999] pointer-events-none whitespace-normal"
                                                                style={{ width: '250px' }}
                                                            >
                                                                <div className="p-3">
                                                                    <div className="font-semibold mb-2.5 text-white">Restricted folder</div>
                                                                    <div className="space-y-2">
                                                                        <div className="text-gray-200">Accessible by:</div>
                                                                        <ul className="list-disc list-outside ml-4 space-y-1 text-gray-200">
                                                                            <li>Owners</li>
                                                                            <li>Admins</li>
                                                                            {category.access_rules?.filter((r) => r.type === 'role').map((rule, idx) => (
                                                                                <li key={idx} className="capitalize">
                                                                                    {rule.role.replace('_', ' ')}
                                                                                </li>
                                                                            ))}
                                                                        </ul>
                                                                    </div>
                                                                </div>
                                                                <div
                                                                    className="absolute left-full top-1/2 transform -translate-y-1/2 w-0 h-0 border-t-[6px] border-b-[6px] border-l-[6px] border-transparent border-l-gray-900"
                                                                />
                                                            </div>
                                                        )}
                                                    </div>
                                                )}
                                            </button>
                                            {showRightTrail ? (
                                                <div className="flex shrink-0 items-stretch rounded-r-md">
                                                    {showSchemaTrigger ? (
                                                        <div
                                                            className="flex min-w-[2rem] shrink-0 items-stretch justify-center opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100 has-[[data-open]]:opacity-100"
                                                            style={{ color: rowIconColor }}
                                                        >
                                                            <FolderSchemaHelp
                                                                category={category}
                                                                triggerClassName={folderSchemaTriggerClass}
                                                            />
                                                        </div>
                                                    ) : null}
                                                    {showCategoryCount ? (
                                                        <span
                                                            className="flex items-center pr-2 text-xs font-normal tabular-nums opacity-80 lg:pr-3"
                                                            style={{ color: rowCountColor }}
                                                        >
                                                            {categoryCount}
                                                        </span>
                                                    ) : null}
                                                </div>
                                            ) : null}
                                        </div>
                                        {/* Phase 3 — Folder Quick Filters: nested contextual list. Renders
                                            only under the active folder; respects feature flag, desktop_only,
                                            and max_visible_per_folder from the Inertia payload. */}
                                        <FolderQuickFilters
                                            quickFilters={category.quick_filters || []}
                                            categoryId={category.id}
                                            categorySlug={category.slug}
                                            isActiveFolder={isSelected}
                                            textColor={rowFg}
                                            activeAccentColor={activeTextColor}
                                            // Phase 4.4 — pass the actual sidebar
                                            // surface + brand-darkened active bg
                                            // so the quick-filter tone is brand-
                                            // aware (flyout reads as same family
                                            // as the sidebar instead of generic
                                            // dark slate).
                                            sidebarColor={sidebarColor}
                                            sidebarActiveBgColor={activeBgColor}
                                            brandAccentHex={workspaceAccentColor}
                                            sidebarBackdropCss={sidebarBackdropCss}
                                        />
                                        </div>
                                    )
                                })}
                                {filteredCategories.length === 0 && !showAllButton && (
                                    <div className="px-3 py-2 text-sm" style={sectionLabelStyle(textColor)}>
                                        {emptyMessage}
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {hasSystemItems && (
                        <>
                            <div className="my-1.5 border-t" style={{ borderColor }} />
                            <div className="px-2 py-1.5 lg:px-3 lg:py-2">
                                <button
                                    type="button"
                                    onClick={toggleSystemCollapsed}
                                    className="flex items-center justify-between w-full px-2 py-1.5 lg:px-3 lg:py-2 text-xs font-semibold uppercase tracking-wider rounded-md"
                                    style={{ color: sectionLabelStyle(textColor).color }}
                                >
                                    <span>SYSTEM</span>
                                    <ChevronRightIcon className={`h-4 w-4 transition-transform ${systemCollapsed ? '' : 'rotate-90'}`} />
                                </button>
                                {!systemCollapsed && (
                                    <div className="mt-1 space-y-1">
                                        {showStaged && (
                                            <NavButton
                                                isSelected={isStagedView}
                                                onClick={onStagedClick ?? (() => router.get(`${baseUrl}/staged`))}
                                                icon={FolderIcon}
                                                label="Staged"
                                                title="Assets awaiting classification. Assign a folder to move them into the main library."
                                                count={stagedCount}
                                                activeBgColor={activeBgColor}
                                                activeTextColor={activeTextColor}
                                                hoverBgColor={hoverBgColor}
                                                unselectedTextColor={unselectedTextColor}
                                                unselectedIconColor={unselectedIconColor}
                                                unselectedCountColor={unselectedCountColor}
                                            />
                                        )}
                                        {showResearch && (
                                            <NavButton
                                                isSelected={isResearchView}
                                                onClick={onResearchClick ?? (() => router.get(baseUrl, { source: 'reference_materials' }))}
                                                icon={DocumentTextIcon}
                                                label="References"
                                                title="Builder reference materials — PDFs, screenshots, ads, packaging. Used for Brand Guidelines and AI analysis; not shown in the main library until published."
                                                count={researchCount}
                                                activeBgColor={activeBgColor}
                                                activeTextColor={activeTextColor}
                                                hoverBgColor={hoverBgColor}
                                                unselectedTextColor={unselectedTextColor}
                                                unselectedIconColor={unselectedIconColor}
                                                unselectedCountColor={unselectedCountColor}
                                            />
                                        )}
                                        {canViewTrash && (
                                            <NavButton
                                                isSelected={lifecycle === 'deleted'}
                                                onClick={onTrashClick ?? (() => router.get(baseUrl, { lifecycle: 'deleted' }))}
                                                icon={TrashIcon}
                                                label="Trash"
                                                title="Soft-deleted assets. Restore or permanently delete from here."
                                                count={trashCount}
                                                activeBgColor={activeBgColor}
                                                activeTextColor={activeTextColor}
                                                hoverBgColor={hoverBgColor}
                                                unselectedTextColor={unselectedTextColor}
                                                unselectedIconColor={unselectedIconColor}
                                                unselectedCountColor={unselectedCountColor}
                                            />
                                        )}
                                    </div>
                                )}
                            </div>
                        </>
                    )}
                </nav>
            </div>
            <div className="flex-shrink-0 px-1.5 lg:px-2 pb-2 lg:pb-3">
                <OnlineUsersIndicator
                    textColor={sectionLabelStyle(textColor).color}
                    primaryColor={workspaceAccentColor}
                    isLightBackground={
                        sidebarBackdropCss != null && String(sidebarBackdropCss).trim() !== ''
                            ? false
                            : getLuminance(normalizeHexColor(sidebarColor)) > 0.88
                    }
                />
            </div>
        </div>
    )
}
