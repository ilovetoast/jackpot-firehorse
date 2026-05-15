/**
 * Dismissible filter summary chips (mobile-first), shown under category tabs.
 * Each chip removes one query facet without clearing the whole bar.
 */
import { useMemo, useCallback } from 'react'
import { router, usePage } from '@inertiajs/react'
import { XMarkIcon } from '@heroicons/react/20/solid'
import { getWorkspaceButtonColor, hexToRgba } from '../utils/colorUtils'
import {
    MULTI_VALUE_FILTER_KEYS,
    stripUrlParams,
    removeOneMultiValueParam,
    collectParamValuesForKey,
} from '../utils/filterUrlUtils'

const SPECIAL_SINGLE_KEYS = [
    'lifecycle',
    'uploaded_by',
    'file_type',
    'compliance_filter',
]

function truncate(s, max = 42) {
    const t = String(s || '').trim()
    if (t.length <= max) return t
    return `${t.slice(0, max - 1)}…`
}

function labelForValue(schemaField, rawValue, available_values, fieldKey) {
    const v = rawValue == null || rawValue === '' ? '' : String(rawValue)
    if (!v) return ''
    const av = available_values?.[fieldKey]
    if (Array.isArray(av)) {
        const hit = av.find((o) => String(o?.value ?? o?.id ?? '') === v)
        if (hit?.label || hit?.system_label) return hit.label || hit.system_label
    }
    if (!schemaField?.options) return v
    const opt = schemaField.options.find((o) => String(o.value ?? o.id) === v)
    return opt?.label || opt?.system_label || v
}

/**
 * @param {Object} props
 * @param {Array} props.filterable_schema
 * @param {string[]} props.inertiaOnly - partial reload keys for router.get
 * @param {boolean} [props.collectionsView] - if true, skip stripping `collection` from chips (navigation)
 */
export default function AssetFilterChipsBar({
    filterable_schema = [],
    available_values = {},
    inertiaOnly = ['assets', 'next_page_url', 'filters', 'uploaded_by_users', 'q'],
    collectionsView = false,
}) {
    const { url, props: pageProps } = usePage()
    const uploadedByUsers = pageProps?.uploaded_by_users || []
    const chipAccent = useMemo(
        () => getWorkspaceButtonColor(pageProps?.auth?.activeBrand) || '#6366f1',
        [pageProps?.auth?.activeBrand],
    )
    const chipSurfaceStyle = useMemo(
        () => ({
            borderColor: hexToRgba(chipAccent, 0.22),
            backgroundColor: hexToRgba(chipAccent, 0.1),
            color: chipAccent,
        }),
        [chipAccent],
    )
    const gridFileTypeLabelByKey = useMemo(() => {
        const m = new Map()
        const grouped = pageProps?.dam_file_types?.grid_file_type_filter_options?.grouped
        if (!Array.isArray(grouped)) return m
        for (const grp of grouped) {
            for (const t of grp.types || []) {
                if (t?.key) m.set(String(t.key), String(t.label || t.key))
            }
        }
        return m
    }, [pageProps?.dam_file_types?.grid_file_type_filter_options])

    const schemaByKey = useMemo(() => {
        const m = new Map()
        for (const f of filterable_schema || []) {
            const k = f.field_key || f.key
            if (k) m.set(k, f)
        }
        return m
    }, [filterable_schema])

    const filterKeys = useMemo(
        () => (filterable_schema || []).map((f) => f.field_key || f.key).filter(Boolean),
        [filterable_schema]
    )

    const chips = useMemo(() => {
        const raw = url.includes('?') ? url.split('?')[1] : ''
        const u = new URLSearchParams(raw)
        const out = []

        const q = (u.get('q') || '').trim()
        if (q) {
            out.push({
                id: 'q',
                label: `Search: ${truncate(q, 36)}`,
                apply: () => stripUrlParams(`?${raw}`, ['q', 'asset']),
            })
        }

        for (const fk of filterKeys) {
            const field = schemaByKey.get(fk)
            const isMultiselectMetadata =
                field?.type === 'multiselect' || field?.field_type === 'multiselect'
            const isMultiValue = MULTI_VALUE_FILTER_KEYS.has(fk) || isMultiselectMetadata

            if (isMultiValue) {
                const vals = [...new Set(collectParamValuesForKey(u, fk))]
                const seen = new Set()
                for (const v of vals) {
                    if (!v || seen.has(v)) continue
                    seen.add(v)
                    const display = field ? labelForValue(field, v, available_values, fk) : v
                    out.push({
                        id: `${fk}:${v}`,
                        label: `${field?.system_label || field?.label || fk}: ${truncate(display, 28)}`,
                        apply: () => removeOneMultiValueParam(`?${raw}`, fk, v),
                    })
                }
            } else if (u.has(fk)) {
                const v = u.get(fk)
                if (v == null || v === '') continue
                const display = field ? labelForValue(field, v, available_values, fk) : v
                out.push({
                    id: `${fk}:${v}`,
                    label: `${field?.system_label || field?.label || fk}: ${truncate(display, 28)}`,
                    apply: () => stripUrlParams(`?${raw}`, [fk]),
                })
            }
        }

        for (const sk of SPECIAL_SINGLE_KEYS) {
            const v = u.get(sk)
            if (!v || v === 'all') continue
            let label = `${sk}: ${truncate(v, 24)}`
            if (sk === 'uploaded_by') {
                const uid = String(v)
                const user = uploadedByUsers.find((x) => String(x.id) === uid)
                if (user?.name) label = `Uploaded by: ${truncate(user.name, 28)}`
            }
            if (sk === 'lifecycle' && v === 'pending_publication') label = 'Pending publication'
            if (sk === 'compliance_filter') label = `Compliance: ${truncate(v, 24)}`
            if (sk === 'file_type') {
                const human = gridFileTypeLabelByKey.get(String(v))
                if (human) label = `File type: ${truncate(human, 28)}`
                else label = `File type: ${truncate(v, 24)}`
            }
            out.push({
                id: `${sk}:${v}`,
                label,
                apply: () => stripUrlParams(`?${raw}`, [sk]),
            })
        }

        if (!collectionsView) {
            const domVals = [...u.getAll('dominant_hue_group')]
            const seenDom = new Set()
            for (const v of domVals) {
                if (!v || seenDom.has(v)) continue
                seenDom.add(v)
                out.push({
                    id: `dominant_hue_group:${v}`,
                    label: `Hue: ${truncate(v, 20)}`,
                    apply: () => removeOneMultiValueParam(`?${raw}`, 'dominant_hue_group', v),
                })
            }
        }

        return out
    }, [url, filterKeys, schemaByKey, uploadedByUsers, collectionsView, available_values, gridFileTypeLabelByKey])

    const applyParams = useCallback(
        (nextParams) => {
            router.get(window.location.pathname, Object.fromEntries(nextParams), {
                preserveState: true,
                preserveScroll: true,
                only: inertiaOnly,
            })
        },
        [inertiaOnly]
    )

    if (chips.length === 0) return null

    const focusRing = `2px solid ${hexToRgba(chipAccent, 0.45)}`

    return (
        <div className="border-b border-gray-200 bg-white px-3 py-2 lg:hidden">
            <div className="flex flex-wrap items-center gap-2">
                {chips.map((c) => (
                    <button
                        key={c.id}
                        type="button"
                        onClick={() => applyParams(c.apply())}
                        className="inline-flex max-w-full items-center gap-1 rounded-full border py-1 pl-2.5 pr-1 text-left text-xs font-medium hover:brightness-[0.97] focus:outline-none"
                        style={{
                            ...chipSurfaceStyle,
                            boxShadow: 'none',
                        }}
                        onMouseEnter={(e) => {
                            e.currentTarget.style.backgroundColor = hexToRgba(chipAccent, 0.14)
                        }}
                        onMouseLeave={(e) => {
                            e.currentTarget.style.backgroundColor = hexToRgba(chipAccent, 0.1)
                        }}
                        onFocus={(e) => {
                            e.currentTarget.style.boxShadow = `0 0 0 ${focusRing}`
                        }}
                        onBlur={(e) => {
                            e.currentTarget.style.boxShadow = 'none'
                        }}
                        title="Remove filter"
                    >
                        <span className="min-w-0 truncate">{c.label}</span>
                        <span
                            className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full opacity-90 hover:opacity-100"
                            style={{ color: chipAccent }}
                        >
                            <XMarkIcon className="h-3.5 w-3.5" aria-hidden />
                            <span className="sr-only">Remove</span>
                        </span>
                    </button>
                ))}
            </div>
        </div>
    )
}
