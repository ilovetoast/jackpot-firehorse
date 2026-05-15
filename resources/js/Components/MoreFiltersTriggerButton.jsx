/**
 * Funnel control for expanding secondary filters — shared by AssetGridToolbar (inline) and AssetGridSecondaryFilters (legacy bar).
 */
import { ChevronDownIcon, ChevronUpIcon, FunnelIcon } from '@heroicons/react/24/outline'
import { getSolidFillButtonForegroundHex } from '../utils/colorUtils'

export default function MoreFiltersTriggerButton({
    isExpanded,
    onToggle,
    activeFilterCount = 0,
    brandPrimary = '#6366f1',
    visibleSecondaryFiltersLength = 0,
    /** Toolbar overflow: primary metadata filters moved into this panel — show chevron even with 0 secondary fields. */
    inlinePrimaryFiltersCollapsed = false,
    className = '',
    /** id of the expandable panel for aria-controls (e.g. asset grid secondary filters). */
    controlsId = undefined,
}) {
    const hasExpandableFields = visibleSecondaryFiltersLength > 0 || inlinePrimaryFiltersCollapsed
    /** Brand count disk on neutral chrome — white on saturated orange (same rule as solid primary buttons). */
    const badgeFg = getSolidFillButtonForegroundHex(brandPrimary)

    return (
        <button
            type="button"
            onClick={onToggle}
            className={`flex items-center gap-1.5 sm:gap-2 min-w-0 shrink-0 rounded-md border border-slate-200 bg-white px-2 py-1.5 text-left shadow-sm transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-400/90 ${className}`}
            aria-expanded={isExpanded}
            aria-controls={controlsId}
            aria-label={
                isExpanded
                    ? 'Collapse filters'
                    : inlinePrimaryFiltersCollapsed
                      ? 'Expand filters — includes quick filters moved from the toolbar'
                      : 'Expand filters'
            }
        >
            <FunnelIcon className="h-4 w-4 flex-shrink-0 text-slate-500" aria-hidden />
            <span className="truncate text-xs font-medium text-slate-700 sm:text-sm">Filters</span>
            {activeFilterCount > 0 && (
                <span
                    className="inline-flex min-w-[1.25rem] items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-semibold tabular-nums sm:text-xs"
                    style={{
                        backgroundColor: brandPrimary,
                        color: badgeFg,
                    }}
                >
                    {activeFilterCount}
                </span>
            )}
            {hasExpandableFields && (
                <span className="flex-shrink-0 text-slate-400">
                    {isExpanded ? <ChevronUpIcon className="h-4 w-4" aria-hidden /> : <ChevronDownIcon className="h-4 w-4" aria-hidden />}
                </span>
            )}
        </button>
    )
}
