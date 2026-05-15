import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import { router, usePage } from '@inertiajs/react'
import {
    applyBoolean,
    applyMultiselectToggle,
    applySingleSelect,
    buildFilterKeysForQuickFilter,
    buildNextParamsForQuickFilter,
    selectedValuesFromUrl,
} from '../../utils/folderQuickFilterApply'
import { parseFiltersFromUrl } from '../../utils/filterUrlUtils'
import { resolveQuickFilterTone } from '../../utils/folderQuickFilterTone'

/**
 * Phase 4 — value picker flyout panel.
 *
 * Phase 4.2 (UX/design pass) — the panel reads as a contextual extension of
 * the sidebar, not a generic floating dropdown:
 *   - 0px gap to the row + asymmetric radius (rounded-r only, square left)
 *     so the row and panel form one connected silhouette.
 *   - Tonal surface inherited from the sidebar (dark sidebar → dark slab,
 *     light sidebar → near-white tinted slab) via resolveQuickFilterTone.
 *   - Layered ambient shadow (3 stops), no harsh outline, restrained radius.
 *   - Mixed-case header instead of uppercase tracking.
 *   - Custom thin scrollbar. Empty / loading / error states use restrained
 *     editorial copy.
 *
 * Strict contract (unchanged from Phase 4.1):
 *   - Lazy fetch ONCE per open.
 *   - URL is the only filter state. Every render derives `selected` from
 *     `usePage().url`.
 *   - Apply path uses `buildUrlParamsWithFlatFilters` + `router.get` exactly
 *     like AssetGridSecondaryFilters.
 */
export default function FolderQuickFilterFlyout({
    field,
    categoryId,
    /** Optional pre-derived tone from the row. If omitted, we derive from textColor on the page. */
    tone: incomingTone,
    inertiaOnly = [
        'assets',
        'next_page_url',
        'filters',
        'filterable_schema',
        'available_values',
        'uploaded_by_users',
        'filtered_grid_total',
        'grid_folder_total',
    ],
    onRequestClose,
}) {
    const { props, url } = usePage()
    const settings = props?.folder_quick_filter_settings || {}
    const filterableSchema = props?.filterable_schema || []
    const tone = useMemo(
        () => incomingTone || resolveQuickFilterTone(props?.workspace_sidebar?.text_color),
        [incomingTone, props?.workspace_sidebar?.text_color]
    )
    const scopeId = useId().replace(/[^a-zA-Z0-9_-]/g, '')
    const scopedScrollClass = `qf-scroll-${scopeId}`
    const scopedSearchClass = `qf-search-${scopeId}`

    const searchThreshold = Number.isFinite(Number(settings.search_threshold))
        ? Math.max(0, Number(settings.search_threshold))
        : 12
    const closeOnSingle = settings.close_on_single_select !== false
    const closeOnBoolean = settings.close_on_boolean_select !== false

    const [state, setState] = useState({
        status: 'loading',
        values: [],
        hasMore: false,
        limit: 0,
        countsAvailable: false,
        errorMessage: '',
    })
    const [searchQuery, setSearchQuery] = useState('')
    const cancelledRef = useRef(false)

    useEffect(() => {
        cancelledRef.current = false

        // Phase 5 — forward the page's other-dimension active filters to the
        // values endpoint. The backend strips the current dimension and uses
        // the remainder to scope facet counts. Counts are computed once at
        // open time and remain stable while the flyout is open (toggling
        // values inside the SAME dimension never affects this dimension's
        // counts because the dimension is excluded by design).
        let activeFiltersJson = null
        if (typeof window !== 'undefined') {
            try {
                const allKeys = Array.isArray(filterableSchema)
                    ? filterableSchema
                          .map((s) => s?.field_key || s?.key)
                          .filter(Boolean)
                    : []
                const allFilters = parseFiltersFromUrl(
                    new URLSearchParams(window.location.search),
                    allKeys
                )
                if (allFilters && Object.keys(allFilters).length > 0) {
                    activeFiltersJson = JSON.stringify(allFilters)
                }
            } catch {
                // Swallow — degrade to "no context" rather than blocking the
                // values request on a parse error.
            }
        }

        const baseEndpoint = `/app/api/tenant/folders/${categoryId}/quick-filters/${field.id}/values`
        const endpoint = activeFiltersJson
            ? `${baseEndpoint}?filters=${encodeURIComponent(activeFiltersJson)}`
            : baseEndpoint

        async function load() {
            try {
                const res = await fetch(endpoint, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                })
                if (!res.ok) {
                    let admin = ''
                    try {
                        const j = await res.json()
                        admin = typeof j?.message === 'string' ? j.message : ''
                    } catch {
                        // 5xx without JSON body — fall through to generic copy.
                    }
                    if (cancelledRef.current) return
                    setState({
                        status: 'error',
                        values: [],
                        hasMore: false,
                        limit: 0,
                        countsAvailable: false,
                        errorMessage:
                            admin ||
                            (res.status === 422 || res.status === 403
                                ? 'This filter is not available here.'
                                : 'Could not load values.'),
                    })
                    return
                }
                const data = await res.json()
                if (cancelledRef.current) return
                const values = Array.isArray(data?.values) ? data.values : []
                setState({
                    status: values.length === 0 ? 'empty' : 'ready',
                    values,
                    hasMore: !!data?.has_more,
                    limit: Number(data?.limit) || values.length,
                    countsAvailable: !!data?.counts_available,
                    errorMessage: '',
                })
            } catch {
                if (cancelledRef.current) return
                setState({
                    status: 'error',
                    values: [],
                    hasMore: false,
                    limit: 0,
                    countsAvailable: false,
                    errorMessage: 'Could not load values.',
                })
            }
        }

        load()
        return () => {
            cancelledRef.current = true
        }
        // We deliberately do NOT depend on `url` — counts should be stable
        // while the flyout is open. Closing & reopening picks up the latest
        // active filters.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [categoryId, field.id])

    const schemaKeys = useMemo(
        () =>
            (Array.isArray(filterableSchema) ? filterableSchema : [])
                .map((s) => s?.field_key || s?.key)
                .filter(Boolean),
        [filterableSchema]
    )

    const filterKeys = useMemo(
        () => buildFilterKeysForQuickFilter(schemaKeys, field.key),
        [schemaKeys, field.key]
    )

    const selectedFromUrl = useMemo(() => {
        if (typeof window === 'undefined') return []
        return selectedValuesFromUrl(window.location.search, field.key, filterKeys)
    }, [filterKeys, field.key, url])

    const debugEnabled = useMemo(() => {
        if (typeof window === 'undefined') return false
        return new URLSearchParams(window.location.search).get('debug') === 'qf-filters'
    }, [url])

    const applyMutation = useCallback(
        (mutator, kind) => {
            const before =
                typeof window !== 'undefined' ? window.location.search : ''
            const params = buildNextParamsForQuickFilter(
                before,
                field.key,
                (draft) => mutator(draft, field.key),
                filterKeys
            )
            if (debugEnabled) {
                console.info('[qf-filters]', {
                    kind,
                    field_key: field.key,
                    field_type: field.type,
                    filter_keys: filterKeys,
                    before,
                    next_params: params,
                })
            }
            router.get(window.location.pathname, params, {
                preserveState: true,
                preserveScroll: true,
                only: inertiaOnly,
            })
        },
        [field.key, field.type, filterKeys, inertiaOnly, debugEnabled]
    )

    const onToggleMulti = useCallback(
        (rawValue) => {
            applyMutation((draft, key) => {
                applyMultiselectToggle(draft, key, rawValue)
            }, 'multiselect.toggle')
        },
        [applyMutation]
    )

    const onSetSingle = useCallback(
        (rawValue) => {
            applyMutation((draft, key) => {
                applySingleSelect(draft, key, rawValue)
            }, 'select.set')
            if (closeOnSingle && typeof onRequestClose === 'function') {
                onRequestClose()
            }
        },
        [applyMutation, closeOnSingle, onRequestClose]
    )

    const onSetBoolean = useCallback(
        (boolValue) => {
            applyMutation((draft, key) => {
                applyBoolean(draft, key, boolValue)
            }, 'boolean.set')
            if (closeOnBoolean && typeof onRequestClose === 'function') {
                onRequestClose()
            }
        },
        [applyMutation, closeOnBoolean, onRequestClose]
    )

    const filteredValues = useMemo(() => {
        if (!searchQuery.trim()) return state.values
        const needle = searchQuery.trim().toLowerCase()
        return state.values.filter((v) =>
            String(v.label ?? v.value ?? '')
                .toLowerCase()
                .includes(needle)
        )
    }, [state.values, searchQuery])

    const showSearch =
        state.status === 'ready' && state.values.length > searchThreshold

    return (
        <div
            role="dialog"
            aria-label={`${field.label} values`}
            // Phase 5.1 — square corners. The flyout reads as an extension
            // of the sidebar surface (which is itself square against the
            // app chrome), so any radius makes the boundary feel like a
            // detached card. The hairline border + layered shadow carry
            // edge definition without softening the silhouette.
            className="w-[15rem] max-h-[18rem] overflow-hidden border backdrop-blur-md"
            style={{
                background: tone.surface,
                borderColor: tone.border,
                boxShadow: tone.shadow,
                color: tone.labelStrong,
            }}
        >
            <style>{`
                .${scopedScrollClass}::-webkit-scrollbar { width: 6px; height: 6px; }
                .${scopedScrollClass}::-webkit-scrollbar-thumb { background: ${tone.scrollbarThumb}; border-radius: 3px; }
                .${scopedScrollClass}::-webkit-scrollbar-track { background: transparent; }
                .${scopedScrollClass} { scrollbar-width: thin; scrollbar-color: ${tone.scrollbarThumb} transparent; }
                .${scopedSearchClass}::placeholder { color: ${tone.labelWeak}; opacity: 1; }
            `}</style>

            {/* Header: editorial mixed-case, low weight, calm. The optional
                "first N" hint sits opposite as a quiet caption. */}
            <div
                className="flex items-center justify-between gap-2 px-3 py-2"
                style={{ borderBottom: `1px solid ${tone.separator}` }}
            >
                <div
                    className="min-w-0 truncate text-[12px] font-medium tracking-[0]"
                    style={{ color: tone.labelStrong }}
                >
                    {field.label}
                </div>
                {state.hasMore ? (
                    <span
                        className="shrink-0 text-[10px] tabular-nums"
                        style={{ color: tone.labelWeak }}
                        aria-hidden
                    >
                        first {state.limit}
                    </span>
                ) : null}
            </div>

            {showSearch ? (
                <div className="px-2.5 pt-2">
                    <input
                        type="text"
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        placeholder="Search values"
                        className={`w-full px-2 py-1 text-[12px] focus:outline-none ${scopedSearchClass}`}
                        style={{
                            background: tone.surfaceElevated,
                            color: tone.labelStrong,
                            border: `1px solid ${tone.border}`,
                        }}
                        aria-label={`Search ${field.label} values`}
                    />
                </div>
            ) : null}

            <div className={`max-h-[14rem] overflow-auto px-1 py-1 ${scopedScrollClass}`}>
                {state.status === 'loading' ? (
                    <FlyoutHint tone={tone}>Loading values…</FlyoutHint>
                ) : null}

                {state.status === 'error' ? (
                    <FlyoutHint tone={tone} kind="danger">
                        {state.errorMessage}
                    </FlyoutHint>
                ) : null}

                {state.status === 'empty' ? (
                    <FlyoutHint tone={tone}>
                        No values available for this filter.
                    </FlyoutHint>
                ) : null}

                {state.status === 'ready'
                    ? filteredValues.map((row) => {
                          const isBoolean = field.type === 'boolean'
                          const valueAsString = isBoolean
                              ? row.value
                                  ? 'true'
                                  : 'false'
                              : String(row.value)
                          const isSelected = selectedFromUrl.includes(valueAsString)
                          const count = state.countsAvailable
                              ? Number.isFinite(Number(row.count))
                                  ? Number(row.count)
                                  : null
                              : null

                          return (
                              <ValueRow
                                  key={`${field.id}-${valueAsString}`}
                                  label={row.label}
                                  selected={isSelected}
                                  fieldType={field.type}
                                  tone={tone}
                                  count={count}
                                  onClick={() => {
                                      if (field.type === 'multiselect') {
                                          onToggleMulti(row.value)
                                      } else if (field.type === 'boolean') {
                                          onSetBoolean(row.value)
                                      } else {
                                          onSetSingle(row.value)
                                      }
                                  }}
                              />
                          )
                      })
                    : null}

                {state.status === 'ready' && filteredValues.length === 0 ? (
                    <FlyoutHint tone={tone}>No matches.</FlyoutHint>
                ) : null}
            </div>

            {state.hasMore ? (
                <div
                    className="px-3 py-1.5 text-[10.5px] leading-snug"
                    style={{
                        color: tone.labelWeak,
                        borderTop: `1px solid ${tone.separator}`,
                    }}
                >
                    Showing first {state.limit} values. Use the full filter panel
                    for more.
                </div>
            ) : null}

            {/* Phase 5.2 — admin-only "Manage filter" deep link. Visibility is
                gated server-side via folder_quick_filter_settings.can_manage_filters
                so unauthorized users never see the affordance. The link
                deep-links into the existing filter management surface; no
                new admin panel is built here. */}
            {settings?.can_manage_filters ? (
                <div
                    className="flex items-center justify-end px-3 py-1.5"
                    style={{
                        borderTop: `1px solid ${tone.separator}`,
                    }}
                >
                    <a
                        href={`/app/admin/metadata/fields/${field.id}`}
                        className="text-[11px] underline-offset-2 hover:underline"
                        style={{ color: tone.labelWeak }}
                        onClick={(e) => {
                            // Never block the popover's own outside-click /
                            // close handlers; just let the navigation
                            // happen.
                            e.stopPropagation()
                        }}
                    >
                        Manage filter
                    </a>
                </div>
            ) : null}
        </div>
    )
}

function FlyoutHint({ children, tone, kind = 'neutral' }) {
    return (
        <div
            className="px-3 py-2 text-[12px] leading-snug"
            style={{
                color: kind === 'danger' ? '#ef6b6b' : tone.labelWeak,
            }}
        >
            {children}
        </div>
    )
}

function ValueRow({ label, selected, fieldType, onClick, tone, count = null }) {
    const isMulti = fieldType === 'multiselect'
    const hasCount = typeof count === 'number'
    const isZero = hasCount && count === 0
    const formattedCount = hasCount ? formatCount(count) : ''
    return (
        <button
            type="button"
            onClick={onClick}
            // Phase 4.4 / 5 / 5.1 — selected/hover rows use the brand-darkened
            // tones pulled from the sidebar's active-row palette. Square
            // corners on inner rows match the outer square panel — no
            // radius drift between containers. NO ring, NO outline —
            // interaction is communicated through background only. Zero-
            // count rows fade slightly so layout rhythm survives a long
            // tail of empty values without making them feel disabled.
            className="flex w-full items-center gap-2 px-2.5 py-[5px] text-left text-[12px] leading-[1.35] transition-[background-color] duration-100 ease-out hover:[background-color:var(--qf-row-hover)] focus:outline-none focus-visible:outline-none"
            style={{
                backgroundColor: selected ? tone.valueSelectedBg : 'transparent',
                color: tone.labelStrong,
                opacity: isZero && !selected ? 0.55 : 1,
                ['--qf-row-hover']: selected ? tone.valueSelectedBg : tone.valueHoverBg,
            }}
            aria-pressed={isMulti ? selected : undefined}
            aria-label={hasCount ? `${label}, ${count} matching` : undefined}
        >
            {/* Multi-select retains a small square indicator so users can
                tell at a glance that multiple values are pickable. Single-
                select has NO indicator — the row's darkened bg + a trailing
                check is the entire selected affordance (Phase 4.4). */}
            {isMulti ? (
                <MultiIndicator selected={selected} tone={tone} />
            ) : (
                // Reserve the same gutter so single + multi columns align.
                <span aria-hidden className="h-[14px] w-[14px] shrink-0" />
            )}
            <span
                className="min-w-0 flex-1 truncate"
                style={{ opacity: selected ? 1 : 0.95 }}
            >
                {label}
            </span>
            {/* Phase 5 — count column. Tabular nums keep digits column-aligned
                across rows; explicit countLabel tone keeps it secondary to
                the label. When the row is selected the count gets fully
                opaque so the active row reads as more emphatic than peers. */}
            {hasCount ? (
                <span
                    className="ml-auto shrink-0 pl-2 text-[11px] tabular-nums"
                    style={{
                        color: tone.countLabel || tone.labelWeak,
                        opacity: selected ? 0.95 : 1,
                    }}
                    aria-hidden
                >
                    {formattedCount}
                </span>
            ) : null}
            {!isMulti && selected ? (
                <CheckGlyph
                    className="h-[12px] w-[12px] shrink-0"
                    color={tone.labelStrong}
                />
            ) : null}
        </button>
    )
}

/**
 * Phase 5 — compact count formatter. Anything ≥ 1000 is shown as `1.2k`
 * to keep column width predictable in the right gutter.
 */
function formatCount(n) {
    if (!Number.isFinite(n)) return ''
    if (n >= 10000) return `${Math.round(n / 1000)}k`
    if (n >= 1000) return `${(n / 1000).toFixed(1)}k`
    return String(n)
}

function MultiIndicator({ selected, tone }) {
    return (
        <span
            aria-hidden
            className="flex h-[14px] w-[14px] shrink-0 items-center justify-center"
            style={{
                border: `1px solid ${selected ? 'transparent' : tone.indicatorBorder}`,
                background: selected ? tone.indicatorActiveBg : 'transparent',
                color: tone.indicatorActiveFg,
            }}
        >
            {selected ? (
                <CheckGlyph
                    className="h-[10px] w-[10px]"
                    color={tone.indicatorActiveFg}
                />
            ) : null}
        </span>
    )
}

function CheckGlyph({ className = 'h-[10px] w-[10px]', color = 'currentColor' }) {
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
            <path d="M2.5 6.5l2.5 2.5 4.5-5" />
        </svg>
    )
}
