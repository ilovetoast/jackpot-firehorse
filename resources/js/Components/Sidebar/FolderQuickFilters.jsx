import { useMemo } from 'react'
import { usePage } from '@inertiajs/react'
import FolderQuickFilterRow from './FolderQuickFilterRow'
import FolderQuickFilterOverflow from './FolderQuickFilterOverflow'
import { parseFiltersFromUrl } from '../../utils/filterUrlUtils'
import { resolveQuickFilterTone } from '../../utils/folderQuickFilterTone'

/**
 * Phase 3 — Folder Quick Filters: contextual nested list rendered under the
 * currently active folder in the asset sidebar.
 *
 * Phase 4.3 (UX direction) — sidebar quick filters represent contextual
 * navigation DIMENSIONS, not active metadata summaries. The chip bar
 * (`AssetFilterChipsBar`) is the single source for explicit selected-value
 * display. Inline labels like "Environment · Outdoor" have been removed; an
 * active dimension is communicated visually through a subtle leading
 * indicator + tonal weight only. The count is preserved for the
 * accessibility label/title attribute on the row, but never rendered.
 *
 * UX rules:
 *   1. Renders ONLY when the parent passes `isActiveFolder=true`.
 *   2. Renders ONLY when the feature flag from
 *      `props.folder_quick_filter_settings.enabled` is true.
 *   3. Renders ONLY on `lg:` breakpoints when `desktop_only=true`.
 *   4. Caps visible rows at `max_visible_per_folder`; overflow renders a
 *      single subtle "+N more" line.
 *   5. Reads active filter values from the existing URL state (read-only).
 *      Never mutates filters, navigates, or talks to facet services.
 *
 * Inputs come from the AssetController payload — see `quick_filters` block
 * attached to each category in `AssetController::index()`.
 */
export default function FolderQuickFilters({
    quickFilters = [],
    categoryId,
    isActiveFolder = false,
    /** From the active row chrome so the nested list reads as "owned by" the folder. */
    textColor,
    activeAccentColor,
    /** Phase 4.4 — actual sidebar surface color so the flyout matches brand. */
    sidebarColor,
    /** Phase 4.4 — sidebar's brand-darkened active-row bg (mirrors active folder). */
    sidebarActiveBgColor,
    /** Workspace brand primary (hex) — tints selected flyout rows toward brand, not slate blue. */
    brandAccentHex,
}) {
    const { props, url } = usePage()
    const settings = props?.folder_quick_filter_settings || {}
    const filterableSchema = props?.filterable_schema || []

    const enabled = !!settings.enabled
    const desktopOnly = settings.desktop_only !== false // default true
    const maxVisible = Number.isFinite(Number(settings.max_visible_per_folder))
        ? Math.max(0, Number(settings.max_visible_per_folder))
        : 3

    // Per-quick-filter active snapshot. We compute the count for the row's
    // aria-label/title only — the visual treatment is now binary
    // (active/inactive). The map shape is `{ [field_key]: number }`.
    const activeCountByFieldKey = useMemo(() => {
        if (!isActiveFolder || quickFilters.length === 0) return {}
        const search = (() => {
            try {
                const path = typeof url === 'string' ? url : ''
                const queryIndex = path.indexOf('?')
                return new URLSearchParams(queryIndex >= 0 ? path.slice(queryIndex + 1) : '')
            } catch {
                return new URLSearchParams('')
            }
        })()
        const baseFilterKeys = Array.isArray(filterableSchema)
            ? filterableSchema.map((s) => s?.field_key || s?.key).filter(Boolean)
            : []
        // Union so quick filters whose `field_key` is `is_filter_hidden` for
        // this category still get parsed (matches the flyout-side fix).
        const filterKeys = [...new Set([
            ...baseFilterKeys,
            ...quickFilters.map((r) => r?.field_key).filter(Boolean),
        ])]
        const parsed = parseFiltersFromUrl(search, filterKeys)
        const out = {}
        for (const row of quickFilters) {
            const key = row?.field_key
            if (!key) continue
            const entry = parsed[key]
            if (!entry || entry.value === undefined || entry.value === null) {
                out[key] = 0
                continue
            }
            const values = Array.isArray(entry.value)
                ? entry.value.filter((v) => v !== '' && v !== null && v !== undefined)
                : entry.value === ''
                  ? []
                  : [entry.value]
            out[key] = values.length
        }
        return out
    }, [isActiveFolder, quickFilters, filterableSchema, url])

    // Phase 5.2 — pinned filters resist overflow. The backend already sorts
    // pinned-first, so a naive `slice(0, maxVisible)` would already keep them
    // visible; this loop makes the guarantee explicit (and survives a future
    // sort change). We start from the natural sort order, ensure every pinned
    // entry lands in `visible`, then top up with the next non-pinned entries
    // until the cap is hit. `hidden` gets the rest.
    // Must stay before early returns so hook count is stable (React #310).
    const { visible, hidden } = useMemo(() => {
        if (maxVisible <= 0) {
            return { visible: [], hidden: quickFilters }
        }
        const pinned = []
        const unpinned = []
        for (const row of quickFilters) {
            if (row?.pinned) pinned.push(row)
            else unpinned.push(row)
        }
        const visibleOut = []
        // Pinned rows always go first, capped by maxVisible. If the admin
        // pinned more than fits, the last pinned rows still overflow — UX
        // surfaces this in the admin visibility preview so admins can
        // reduce.
        for (const row of pinned) {
            if (visibleOut.length >= maxVisible) break
            visibleOut.push(row)
        }
        for (const row of unpinned) {
            if (visibleOut.length >= maxVisible) break
            visibleOut.push(row)
        }
        const visibleIds = new Set(visibleOut.map((r) => r.metadata_field_id))
        const hiddenOut = quickFilters.filter(
            (r) => !visibleIds.has(r.metadata_field_id)
        )
        return { visible: visibleOut, hidden: hiddenOut }
    }, [quickFilters, maxVisible])

    const quickFilterFieldKeys = useMemo(
        () => (quickFilters || []).map((r) => r?.field_key).filter(Boolean),
        [quickFilters],
    )

    // Empty / disabled / inactive: render nothing. NEVER an empty container.
    if (!isActiveFolder) return null
    if (!enabled) return null
    if (!Array.isArray(quickFilters) || quickFilters.length === 0) return null

    // Phase 4.4: brand-aware tonal palette tinted from the sidebar surface
    // and active-row tone.
    const tone = resolveQuickFilterTone(textColor, sidebarColor, sidebarActiveBgColor, brandAccentHex)

    return (
        <ul
            className={`relative mt-0 mb-1 ml-7 mr-1 list-none space-y-0 pl-2 motion-safe:animate-[qfFadeIn_130ms_ease-out] ${
                desktopOnly ? 'hidden lg:block' : 'block'
            }`}
            style={{
                // Inset left guide groups the nested list visually without a
                // tree-view feel (no chevrons, no thick lines).
                boxShadow: `inset 1px 0 0 0 ${tone.leftGuide}`,
            }}
            data-testid="folder-quick-filters"
        >
            <style>{`@keyframes qfFadeIn { from { opacity: 0; transform: translateY(-1px); } to { opacity: 1; transform: translateY(0); } }`}</style>
            {visible.map((row) => {
                const count = activeCountByFieldKey[row.field_key] ?? 0
                return (
                    <li key={row.metadata_field_id}>
                        <FolderQuickFilterRow
                            field={{
                                id: row.metadata_field_id,
                                key: row.field_key,
                                label: row.label,
                                type: row.field_type,
                            }}
                            exclusiveQuickFilterKeys={quickFilterFieldKeys.filter((k) => k !== row.field_key)}
                            categoryId={categoryId}
                            // Phase 4.3 — binary active state. Count travels
                            // through for a11y only.
                            isActive={count > 0}
                            activeValueCount={count}
                            // Phase 5.2 — pinned visual hint (subtle pin
                            // glyph; no extra row chrome). Admins manage
                            // pinning from FolderSchemaHelp's QuickFilter
                            // controls.
                            isPinned={!!row.pinned}
                            textColor={textColor}
                            activeAccentColor={activeAccentColor}
                            // Phase 4.4 — pass tone so row + flyout share
                            // one brand palette without re-deriving.
                            tone={tone}
                        />
                    </li>
                )
            })}
            {hidden.length > 0 ? (
                <li>
                    <FolderQuickFilterOverflow
                        hiddenFilters={hidden}
                        categoryId={categoryId}
                        tone={tone}
                        activeCountByFieldKey={activeCountByFieldKey}
                        allQuickFilterFieldKeys={quickFilterFieldKeys}
                    />
                </li>
            ) : null}
        </ul>
    )
}
