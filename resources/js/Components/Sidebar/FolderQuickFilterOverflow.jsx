import { useCallback, useMemo, useState } from 'react'
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react'
import FolderQuickFilterFlyout from './FolderQuickFilterFlyout'

/**
 * Phase 5.2 — overflow flyout for the sidebar quick-filter strip.
 *
 * Replaces the static "+N more" line. Clicking opens a compact contextual
 * flyout that lists the hidden quick filters in the same visual language as
 * the value flyout. Selecting a hidden filter swaps the panel into its
 * value flyout in-place — there is never more than one popover open and
 * the user always returns to the overflow list via the back affordance.
 *
 * Behaviour:
 *   - Lists hidden filters only; the visible filters already render inline.
 *   - Pinned filters never show up here (the assignment service sort
 *     guarantees they sit in the visible slice).
 *   - Same anchor / square corners / brand tonality as the value flyout so
 *     the strip never looks like two different surfaces stitched together.
 *   - Fire-and-forget instrumentation hit on open. We don't block the UI
 *     on the response, and we deliberately ignore failures — a broken sink
 *     never blocks navigation.
 */
export default function FolderQuickFilterOverflow({
    /** List of hidden quick-filter rows from the AssetController payload. */
    hiddenFilters = [],
    categoryId,
    categorySlug,
    tone,
    activeCountByFieldKey = {},
    /** All folder quick-filter field keys (visible + hidden) for URL exclusivity in the value flyout. */
    allQuickFilterFieldKeys = [],
}) {
    const [selectedField, setSelectedField] = useState(null)
    const reportedRef = useMemo(() => ({ current: false }), [])

    const total = Array.isArray(hiddenFilters) ? hiddenFilters.length : 0

    const reportOpen = useCallback(() => {
        if (reportedRef.current) return
        reportedRef.current = true
        if (typeof window === 'undefined') return
        try {
            fetch(
                `/app/api/tenant/folders/${categoryId}/quick-filters/overflow-open`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({}),
                }
            ).catch(() => {})
        } catch {
            // Swallow — instrumentation must never break the overflow flyout.
        }
    }, [categoryId, reportedRef])

    if (total <= 0) return null

    return (
        <Popover className="relative">
            {({ open, close }) => {
                if (open) reportOpen()
                return (
                    <>
                        <PopoverButton
                            type="button"
                            title={`${total} more quick filter${total === 1 ? '' : 's'}`}
                            aria-label={`${total} more quick filter${total === 1 ? '' : 's'} available`}
                            className="group/qfm flex w-full items-center gap-2 truncate px-2 py-[3px] text-left text-[11px] leading-[1.35] transition-[color] duration-100 ease-out hover:[background-color:var(--qf-row-hover)] focus:outline-none focus-visible:outline-none"
                            style={{
                                color: tone.labelWeak,
                                backgroundColor: 'transparent',
                                ['--qf-row-hover']: tone.rowHoverBg,
                            }}
                            onClick={() => {
                                // Reset the inner view when the trigger is
                                // clicked again so re-opens always start at
                                // the filter list.
                                if (!open) setSelectedField(null)
                            }}
                        >
                            <span
                                aria-hidden
                                className="flex h-[14px] w-[6px] shrink-0 items-center justify-center"
                            />
                            <span className="min-w-0 flex-1 truncate">
                                +{total} more
                            </span>
                        </PopoverButton>
                        <PopoverPanel
                            transition
                            anchor={{ to: 'right start', gap: 0, offset: 0 }}
                            className="z-[220] motion-safe:transition motion-safe:duration-[130ms] motion-safe:ease-out data-[closed]:translate-x-[-2px] data-[closed]:opacity-0 motion-reduce:transition-none motion-reduce:data-[closed]:opacity-100"
                        >
                            {selectedField ? (
                                <OverflowValueView
                                    field={selectedField}
                                    categoryId={categoryId}
                                    categorySlug={categorySlug}
                                    tone={tone}
                                    exclusiveQuickFilterKeys={allQuickFilterFieldKeys.filter(
                                        (k) => k !== selectedField.key
                                    )}
                                    onBack={() => setSelectedField(null)}
                                    onRequestClose={() => {
                                        setSelectedField(null)
                                        close()
                                    }}
                                />
                            ) : (
                                <OverflowListView
                                    hiddenFilters={hiddenFilters}
                                    tone={tone}
                                    activeCountByFieldKey={activeCountByFieldKey}
                                    onPick={(field) => setSelectedField(field)}
                                />
                            )}
                        </PopoverPanel>
                    </>
                )
            }}
        </Popover>
    )
}

function OverflowListView({ hiddenFilters, tone, activeCountByFieldKey, onPick }) {
    const usesWorkspaceSidebarBackdrop =
        tone.flyoutBackground != null && String(tone.flyoutBackground).trim() !== ''

    return (
        <div
            role="dialog"
            aria-label="More quick filters"
            className={`w-[15rem] max-h-[18rem] overflow-hidden border ${
                usesWorkspaceSidebarBackdrop ? '' : 'backdrop-blur-md'
            }`}
            style={{
                ...(usesWorkspaceSidebarBackdrop
                    ? {
                          background: tone.flyoutBackground,
                          backgroundColor: tone.flyoutBackgroundColor ?? '#0B0B0D',
                      }
                    : { background: tone.surface }),
                borderColor: tone.border,
                boxShadow: tone.shadow,
                color: tone.labelStrong,
            }}
        >
            <div
                className="px-3 py-2"
                style={{ borderBottom: `1px solid ${tone.separator}` }}
            >
                <div
                    className="text-[12px] font-medium"
                    style={{ color: tone.labelStrong }}
                >
                    More filters
                </div>
            </div>
            <ul
                className="max-h-[14rem] overflow-auto px-1 py-1"
                style={{
                    scrollbarWidth: 'thin',
                    scrollbarColor: `${tone.scrollbarThumb} transparent`,
                }}
            >
                {hiddenFilters.map((row) => {
                    const isActive = (activeCountByFieldKey[row.field_key] ?? 0) > 0
                    return (
                        <li key={row.metadata_field_id}>
                            <button
                                type="button"
                                onClick={() =>
                                    onPick({
                                        id: row.metadata_field_id,
                                        key: row.field_key,
                                        label: row.label,
                                        type: row.field_type,
                                    })
                                }
                                title={row.label}
                                className="flex w-full items-center gap-2 px-2.5 py-[5px] text-left text-[12px] leading-[1.35] transition-[background-color] duration-100 ease-out hover:[background-color:var(--qf-row-hover)] focus:outline-none focus-visible:outline-none"
                                style={{
                                    color: tone.labelStrong,
                                    backgroundColor: 'transparent',
                                    fontWeight: isActive ? 500 : 400,
                                    ['--qf-row-hover']: tone.valueHoverBg,
                                }}
                            >
                                <span
                                    aria-hidden
                                    className="flex h-[14px] w-[6px] shrink-0 items-center justify-center"
                                >
                                    <span
                                        className="block h-[5px] w-[5px] rounded-full"
                                        style={{
                                            background: tone.labelStrong,
                                            opacity: isActive ? 0.9 : 0,
                                        }}
                                    />
                                </span>
                                <span className="min-w-0 flex-1 truncate">
                                    {row.label}
                                </span>
                                <ChevronGlyph
                                    className="h-[10px] w-[10px] shrink-0"
                                    color={tone.labelWeak}
                                />
                            </button>
                        </li>
                    )
                })}
            </ul>
        </div>
    )
}

/**
 * Wrapper that re-uses {@see FolderQuickFilterFlyout} but prepends a tiny
 * "back" affordance so users can return to the overflow list without
 * dismissing the popover entirely.
 */
function OverflowValueView({ field, categoryId, categorySlug, tone, exclusiveQuickFilterKeys, onBack, onRequestClose }) {
    return (
        <div className="relative">
            <button
                type="button"
                onClick={onBack}
                aria-label="Back to overflow list"
                title="Back"
                className="absolute left-1 top-1.5 z-[1] flex h-[20px] w-[20px] items-center justify-center text-[11px] transition-[background-color] duration-100 ease-out hover:[background-color:var(--qf-row-hover)] focus:outline-none focus-visible:outline-none"
                style={{
                    color: tone.labelWeak,
                    ['--qf-row-hover']: tone.valueHoverBg,
                }}
            >
                <ChevronGlyph
                    className="h-[10px] w-[10px] rotate-180"
                    color={tone.labelWeak}
                />
            </button>
            <FolderQuickFilterFlyout
                field={field}
                categoryId={categoryId}
                categorySlug={categorySlug}
                tone={tone}
                exclusiveQuickFilterKeys={exclusiveQuickFilterKeys}
                onRequestClose={onRequestClose}
            />
        </div>
    )
}

function ChevronGlyph({ className = 'h-[10px] w-[10px]', color = 'currentColor' }) {
    return (
        <svg
            viewBox="0 0 12 12"
            className={className}
            aria-hidden
            fill="none"
            stroke={color}
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M4.5 2.5l3.5 3.5-3.5 3.5" />
        </svg>
    )
}
